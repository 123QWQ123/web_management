# Web Management — Domain Provisioning & Traffic Routing Platform

A Laravel-based infrastructure management platform for domain alias provisioning and traffic routing orchestration across **Cloudflare** and **StormWall**.

> **For Frontend Developers**: See [API.md](./API.md) for complete API documentation with request/response formats.

---

## What This Is

This is **not** a CRUD app. It is an operational workflow system that manages how a domain is connected to Cloudflare and optionally StormWall, in the correct order, with observable state transitions and safe retries.

Operators can:
- Add domain aliases and assign them to projects, prelands, and traffic flows
- Select a traffic routing mode (3 supported)
- Switch routing modes on live domains with a one-click revert option
- Configure reusable infrastructure IPs from the Settings panel
- Monitor live domain status with 4-second auto-refresh

---

## Routing Modes

| Mode | Traffic Flow | Registrar config |
|------|-------------|-----------------|
| `cf` | Client → **CF (proxied)** → Backend | NS → Cloudflare |
| `sw` | Client → **SW** → Backend | NS → StormWall |
| `cf_sw` | Client → **CF (DNS Only)** → **SW** → Backend | NS → Cloudflare |

### CF DNS record target per mode

| Mode | CF DNS points to | proxied |
|------|-----------------|---------|
| `cf` | `server_ip` | ✅ true |
| `sw` | — (no CF DNS involvement) | — |
| `cf_sw` | `stormwall_ip` | ❌ false (DNS Only) |

> **`cf_sw`**: Cloudflare provides DNS resolution only (not proxying). CF DNS A-record points to StormWall IP. StormWall terminates SSL and forwards to backend.
> Registrar must point NS to Cloudflare. The platform manages the CF DNS → SW IP mapping automatically.

---

## Provisioning Workflow

Each domain progresses through a strict sequence of steps tracked by the `status` field.

```
cf:    INIT → CF_ZONE → CF_DNS → DONE

sw:    INIT → SW_DOMAIN → SW_BACKENDS → [SSL_REQUEST → WAIT_SSL] → DONE

cf_sw: INIT → CF_ZONE → SW_DOMAIN → CF_DNS → SW_BACKENDS → [SSL] → DONE
```

Every step is executed by a queued `ProcessDomainJob` → `DomainOrchestrator` fan-forward pattern. All provider calls are logged with full request/response context via `DomainWorkflowLogger`.

---

## Traffic Switcher

For domains with status `done`, operators can switch routing modes at runtime without re-provisioning:

| Switch | CF DNS change | SW backends change | CF Zone Status |
|--------|-------------|-------------------|----------------|
| `cf → sw` | none | replaced with `server_ip` | Zone remains (inactive) |
| `sw → cf` | provisions CF if missing, set to `server_ip`, proxied=true | none | Created if missing |
| `cf → cf_sw` | set to `stormwall_ip`, proxied=false (DNS Only) | replaced with `server_ip` | Active |
| `cf_sw → sw` | none | already correct | Zone remains (inactive) |
| `sw → cf_sw` | provisions CF zone if missing, set to `stormwall_ip`, proxied=false | replaced with `server_ip` | Created if missing |
| `cf_sw → cf` | set to `server_ip`, proxied=true | none | Active |

Every switch saves the previous mode + config snapshot for **one-click revert**.

---

## Architecture

```
app/
├── Http/Controllers/Admin/
│   ├── DomainController.php        # CRUD + live API feed + admin actions
│   ├── SwitchTrafficController.php # Mode switcher + revert + CF DNS sync
│   └── SettingController.php       # Reusable infrastructure IPs
│
├── Services/
│   ├── DomainOrchestrator.php      # Workflow coordinator (step routing)
│   ├── DomainWorkflowLogger.php    # Step-level request/response logging
│   ├── Cloudflare/
│   │   ├── CloudflareService.php   # Zone, DNS, settings, zone status
│   │   ├── Contracts/              # CloudflareServiceInterface
│   │   ├── DTO/                    # ZoneData, DnsRecordData
│   │   └── Http/CloudflareClient.php
│   └── StormWall/
│       ├── StormWallService.php    # Domain, backends, SSL, HTTPS redirect
│       ├── Contracts/              # StormWallServiceInterface
│       ├── DTO/                    # BackendData, CreateDomainData, SslCertificateData
│       ├── Exceptions/             # StormWallException
│       └── Http/StormWallClient.php
│
├── Jobs/
│   └── ProcessDomainJob.php        # Fan-forward job per workflow step
│
├── Models/
│   ├── Domain.php                  # Domain alias with full routing state
│   ├── DomainLog.php               # Workflow step logs (request/response)
│   └── Setting.php                 # Key-value infrastructure settings
│
└── Enums/
    └── DomainStatus.php            # All workflow statuses
```

---

## Frontend Architecture

### Technology Stack
- **Backend**: Laravel 11 (Blade templates + API endpoints)
- **Frontend**: Bootstrap 5.3 + Vanilla JS
- **Live Updates**: JavaScript polling (`/admin/domains/api`) every 4 seconds
- **Styling**: Custom CSS with green theme (`#00a72c`)

### Key UI Components

**Sidebar Navigation** (`resources/views/layouts/app.blade.php`)
- Fixed left sidebar with gradient background
- Active route highlighting
- Emoji icons

**Domain List** (`resources/views/admin/domains/index.blade.php`)
- Table view with live status updates
- Status badges with descriptions
- Traffic mode switcher dropdown
- CF zone status indicator (pending/active)
- IP filter
- Row-level loading overlay on actions

**Domain Creation Form** (`resources/views/admin/domains/create.blade.php`)
- Mode selector (cf/sw/cf_sw)
- IP dropdowns with remembered values
- Real-time validation

### Live Update System

```javascript
// Polls API every 4 seconds
setInterval(function() {
    fetch('/admin/domains/api')
        .then(r => r.json())
        .then(updateDomainRows)
        .catch(handleError);
}, 4000);
```

**Data flow:**
1. Backend updates domain status in DB
2. API endpoint returns fresh data
3. Frontend diffs current vs new data
4. Only changed rows are updated (no full re-render)

---

## API Documentation

See [API.md](./API.md) for:
- Complete endpoint reference
- Request/response formats
- Authentication headers
- Status codes
- Example payloads

---

## Setup

### Requirements

- PHP 8.2+
- Laravel 11
- MySQL 8.0+
- Redis or database queue driver
- Composer
- npm

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
STORMWALL_BACKEND_PORT=80
STORMWALL_BACKEND_TYPE=balance
STORMWALL_BACKEND_WEIGHT=50

# StormWall SSL polling
STORMWALL_SSL_POLL_DELAY_SECONDS=60
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

After code changes, restart the worker:
```bash
php artisan queue:restart
```

---

## Domain Status Lifecycle

```
init
 ↓
cloudflare_zone       (CF zone created)
 ↓
stormwall_domain      (SW domain created, SW proxy IP obtained)
 ↓
cloudflare_dns        (DNS A record created/updated in CF, www subdomain added)
 ↓
stormwall_backends    (SW backends configured for ports 80 & 443)
 ↓
stormwall_ssl_requested → waiting_stormwall_ssl (polling every 60s)
 ↓
done ✅  /  failed ❌
```

Not all steps apply to every mode — the orchestrator routes between them based on `domain.mode`.

**CF Zone Status Tracking:**
- `pending` — NS not delegated at registrar yet
- `active` — NS delegated, zone is live
- Checked via "🔄 Обновить статус CF" button

---

## Testing

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Unit/Services/DomainOrchestratorTest.php

# Run with coverage
php artisan test --coverage
```

**Key test files:**
- `tests/Unit/Services/DomainOrchestratorTest.php` — Workflow step logic
- `tests/Unit/Services/StormwallServiceTest.php` — SW API integration

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

---

## Debugging

### Logs

- **Domain workflow**: `storage/logs/domain-{date}.log` (JSON formatted)
- **Laravel errors**: `storage/logs/laravel.log`
- **Queue worker**: `storage/logs/worker.log`

### Common Issues

**Queue job not processing:**
```bash
php artisan queue:restart
php artisan queue:work --once  # Test single job
```

**CF zone stuck in pending:**
- Nameservers not updated at registrar
- Click "🔄 Обновить статус CF" to refresh

**SSL polling not finishing:**
- Check `ssl_requested_at` and `next_attempt_at` in DB
- StormWall may take up to 24 hours for DNS propagation
- Click "🔄 Перезапустить" to force re-check

**Mode switch not working:**
- Ensure domain is in `done` status
- Check browser console for JS errors
- Review `storage/logs/domain-{date}.log`

---

## License

Proprietary — Internal use only
