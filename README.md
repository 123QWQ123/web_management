# Web Management — Domain Provisioning & Traffic Routing Platform

A Laravel-based infrastructure management platform for domain alias provisioning and traffic routing orchestration across **Cloudflare** and **StormWall**.

---

## What This Is

This is **not** a CRUD app. It is an operational workflow system that manages how a domain is connected to Cloudflare and optionally StormWall, in the correct order, with observable state transitions and safe retries.

Operators can:
- Add domain aliases and assign them to projects, prelands, and traffic flows
- Select a traffic routing mode (4 supported)
- Switch routing modes on live domains with a one-click revert option
- Configure reusable infrastructure IPs from the Settings panel
- Monitor live domain status with 4-second auto-refresh

---

## Routing Modes

| Mode | Traffic Flow | Registrar |
|------|-------------|-----------|
| `cf` | Registrar → **CF** → Backend | NS → Cloudflare |
| `sw` | Registrar → **SW** → Backend | A-record → StormWall IP |
| `cf_sw` | Registrar → **CF** → SW → Backend | NS → Cloudflare |
| `sw_cf` | Registrar → **SW** → CF → Backend | A-record → StormWall IP |

### CF DNS record target per mode

| Mode | CF DNS points to | proxied |
|------|-----------------|---------|
| `cf` | `server_ip` | ✅ true |
| `sw` | — (no CF record) | — |
| `cf_sw` | `stormwall_ip` | ✅ true |
| `sw_cf` | `server_ip` | ✅ true |

---

## Provisioning Workflow

Each domain progresses through a strict sequence of steps tracked by the `status` field.

```
cf:    INIT → CF_ZONE → CF_DNS → DONE

sw:    INIT → SW_DOMAIN → SW_BACKENDS → [SSL_REQUEST → WAIT_SSL] → DONE

cf_sw: INIT → CF_ZONE → SW_DOMAIN → CF_DNS → SW_BACKENDS → [SSL] → DONE

sw_cf: INIT → CF_ZONE → CF_DNS → SW_DOMAIN → SW_CF_BACKENDS → DONE
```

Every step is executed by a queued `ProcessDomainJob` → `DomainOrchestrator` fan-forward pattern. All provider calls are logged with full request/response context via `DomainWorkflowLogger`.

---

## Traffic Switcher

For domains with status `done`, operators can switch routing modes at runtime without re-provisioning:

- **CF → SW**: updates CF DNS, replaces SW backends with `server_ip`
- **SW → CF**: provisions CF zone if missing, sets CF DNS to `server_ip`
- **CF → CF_SW**: points CF DNS to StormWall IP, replaces SW backends with `server_ip`
- **SW → SW_CF**: sets CF DNS to `server_ip`, resolves CF anycast IP, sets SW backend to CF proxy IP
- Any switch saves the previous mode + config snapshot for **one-click revert**

### Pending CF Activation (sw → sw_cf)

If the CF zone is in `pending` status (registrar NS not yet changed), the switcher:
1. Sets mode to `cf` as intermediate state
2. Stores `sw_cf` in `pending_mode`
3. Dispatches `PollCfActivationJob` (polls every 30s, max 24h)
4. Shows the operator the NS servers to add at the registrar
5. Completes the switch automatically once the zone becomes `active`

---

## Architecture

```
app/
├── Http/Controllers/Admin/
│   ├── DomainController.php        # CRUD + live API feed
│   ├── SwitchTrafficController.php # Mode switcher + revert
│   └── SettingController.php       # Reusable infrastructure IPs
│
├── Services/
│   ├── DomainOrchestrator.php      # Workflow coordinator (step routing)
│   ├── DomainWorkflowLogger.php    # Step-level request/response logging
│   ├── Cloudflare/
│   │   ├── CloudflareService.php   # Zone, DNS, settings, anycast IP resolve
│   │   ├── Contracts/              # CloudflareServiceInterface
│   │   ├── DTO/                    # ZoneData, DnsRecordData
│   │   └── Http/CloudflareClient.php
│   └── StormWall/
│       ├── StormWallService.php    # Domain, backends, SSL, proxy ports
│       ├── Contracts/              # StormWallServiceInterface
│       ├── DTO/                    # BackendData, CreateDomainData, ...
│       ├── Exceptions/             # StormWallException
│       └── Http/StormWallClient.php
│
├── Jobs/
│   ├── ProcessDomainJob.php        # Fan-forward job per workflow step
│   └── PollCfActivationJob.php     # Polls CF zone until active (sw_cf deferred)
│
├── Models/
│   ├── Domain.php                  # Domain alias with full routing state
│   └── Setting.php                 # Key-value infrastructure settings
│
└── Enums/
    └── DomainStatus.php            # All workflow statuses
```

---

## Setup

### Requirements

- PHP 8.2+
- Laravel 11
- MySQL / PostgreSQL
- Redis or database queue driver
- `dig` CLI tool available on the server (for CF anycast IP resolution)

### Installation

```bash
git clone <repo>
cd web-management
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

### Environment Variables

```env
# Cloudflare
CLOUDFLARE_API_TOKEN=
CLOUDFLARE_ACCOUNT_ID=

# StormWall
STORMWALL_API_KEY=
STORMWALL_SERVICE_ID=
STORMWALL_BASE_URL=https://api.stormwall.pro

# StormWall backend defaults
STORMWALL_DOMAIN_PORT=80
STORMWALL_BACKEND_PORT=80
STORMWALL_DOMAIN_USES_SSL=false
STORMWALL_BACKEND_TYPE=balance
STORMWALL_BACKEND_WEIGHT=1
STORMWALL_USE_PROXY_SNI=false

# StormWall SSL (Let's Encrypt)
STORMWALL_SSL_LE_ENABLED=false
STORMWALL_SSL_LE_WWW_INCLUDED=true
STORMWALL_SSL_POLL_DELAY_SECONDS=300
STORMWALL_SSL_MAX_WAIT_MINUTES=30

# Retry config
STORMWALL_RETRY_TIMES=3
STORMWALL_RETRY_SLEEP=500
```

### Queue Worker

The workflow is fully async. You must run the queue worker:

```bash
php artisan queue:work
```

For production, use Supervisor or a similar process manager to keep it running.

---

## Admin Panel Routes

| Method | URL | Description |
|--------|-----|-------------|
| GET | `/admin/domains` | Domain list with live auto-refresh |
| GET | `/admin/domains/create` | Add new domain form |
| POST | `/admin/domains` | Store new domain, dispatch provisioning job |
| DELETE | `/admin/domains/{domain}` | Delete domain from CF, SW, and DB |
| POST | `/admin/domains/{domain}/switch-traffic` | Switch routing mode |
| POST | `/admin/domains/{domain}/revert-traffic` | Revert to previous mode |
| GET | `/admin/settings` | Infrastructure IP settings |
| POST | `/admin/settings` | Save IP settings |
| GET | `/admin/domains/api` | Live JSON feed (polled by frontend every 4s) |

---

## Domain Status Lifecycle

```
init
 ↓
cloudflare_zone       (CF zone created)
 ↓
stormwall_domain      (SW domain created, SW proxy IP obtained)
 ↓
cloudflare_dns        (DNS A record created/updated in CF)
 ↓
stormwall_backends    (SW backends set to server_ip)
 ↓
sw_cf_backends        (SW backends set to CF proxy IP — sw_cf mode)
 ↓
sw_backends           (SW backends set to server_ip — sw mode)
 ↓
stormwall_ssl_requested → waiting_stormwall_ssl
 ↓
done ✅  /  failed ❌
```

Not all steps apply to every mode — the orchestrator routes between them based on `domain.mode`.

---

## Key Design Principles

- **Controllers are thin** — no external API calls, no business logic
- **Service interfaces** — CF and SW are behind contracts, swappable and testable
- **DTOs** — all provider payloads are typed value objects
- **Idempotent workflow** — `findZoneByName` / `findDnsRecord` prevent duplicate creation
- **Strict ordering** — no steps are collapsed or skipped
- **Observable state** — every step updates `status` and logs request + response
- **Safe retries** — provider calls use configurable retry/backoff via `StormWallClient`
- **One-click revert** — every mode switch snapshots the previous config for rollback
