# API Documentation

Complete API reference for the Web Management Platform. This document is intended for frontend developers who need to understand request/response formats.

---

## Table of Contents

1. [Authentication](#authentication)
2. [Domain Management](#domain-management)
3. [Traffic Switching](#traffic-switching)
4. [Settings Management](#settings-management)
5. [Data Models](#data-models)
6. [Status Codes](#status-codes)

---

## Authentication

All admin panel routes require authentication. The app uses Laravel's session-based authentication with CSRF tokens.

### CSRF Token

All `POST`, `PUT`, `PATCH`, `DELETE` requests must include a CSRF token:

```html
<meta name="csrf-token" content="{{ csrf_token() }}">
```

**JavaScript example:**
```javascript
const token = document.querySelector('meta[name="csrf-token"]').content;

fetch('/admin/domains', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token
    },
    body: JSON.stringify(data)
});
```

---

## Domain Management

### List Domains (HTML)

**GET** `/admin/domains`

Returns the HTML page with domain list and live update system.

**Response:** HTML (Blade template)

---

### List Domains (JSON API)

**GET** `/admin/domains/api`

Returns JSON array of all domains for live updates.

**Response:**
```json
[
    {
        "id": 6,
        "domain": "fleenking.pro",
        "mode": "cf_sw",
        "previous_mode": null,
        "active_traffic_receiver": "cf",
        "status": "done",
        "cloudflare_zone_id": "81dce8ba6b739cca08db8f464aa93ce5",
        "cloudflare_zone_status": "active",
        "cloudflare_nameservers": [
            "eleanor.ns.cloudflare.com",
            "tim.ns.cloudflare.com"
        ],
        "stormwall_nameservers": [
            "dns1.storm-pro.net",
            "dns2.storm-pro.net",
            "dns3.storm-pro.net",
            "dns4.storm-pro.net"
        ],
        "stormwall_ip": "185.71.67.102",
        "server_ip": "91.217.84.111",
        "ssl_requested_at": "2026-06-04T11:31:48+00:00",
        "ssl_ready_at": "2026-06-04T11:35:07+00:00",
        "created_at": "04.06.2026 11:31"
    }
]
```

**Field Descriptions:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Domain ID |
| `domain` | string | Domain name (e.g., "example.com") |
| `mode` | string | Routing mode: `cf`, `sw`, or `cf_sw` |
| `previous_mode` | string\|null | Mode before last switch (for revert) |
| `active_traffic_receiver` | string | Primary entry point: `cf` or `sw` |
| `status` | string | Workflow status (see [Domain Status](#domain-status)) |
| `cloudflare_zone_id` | string\|null | Cloudflare zone ID |
| `cloudflare_zone_status` | string\|null | `pending` or `active` |
| `cloudflare_nameservers` | array | CF NS to set at registrar |
| `stormwall_nameservers` | array | SW NS to set at registrar |
| `stormwall_ip` | string\|null | StormWall proxy IP |
| `server_ip` | string\|null | Backend origin server IP |
| `ssl_requested_at` | string\|null | ISO 8601 timestamp |
| `ssl_ready_at` | string\|null | ISO 8601 timestamp |
| `created_at` | string | Formatted date string |

---

### Create Domain Form

**GET** `/admin/domains/create`

Returns the HTML form for creating a new domain.

**Response:** HTML (Blade template)

---

### Create Domain

**POST** `/admin/domains`

Creates a new domain and starts the provisioning workflow.

**Request Body:**
```json
{
    "domain": "example.com",
    "mode": "cf_sw",
    "server_ip": "91.217.84.111",
    "stormwall_ip": "185.71.67.102"
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `domain` | required, string, valid domain format, unique |
| `mode` | required, in:cf,sw,cf_sw |
| `server_ip` | required_if:mode,cf\|cf_sw, IP address |
| `stormwall_ip` | required_if:mode,sw\|cf_sw, IP address |

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "Domain [example.com] added successfully! Processing started."
```

**Response (Validation Error - 422):**
```json
{
    "message": "The domain field is required.",
    "errors": {
        "domain": ["The domain field is required."]
    }
}
```

---

### Delete Domain

**DELETE** `/admin/domains/{domain}`

Deletes the domain from Cloudflare, StormWall, and database.

**URL Parameters:**
- `{domain}` — Domain ID

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "Domain [example.com] deleted from all services."
```

**Response (Error - 302 Redirect):**
```
Location: /admin/domains
Session Flash (error): "Error message here"
```

---

### Retry Domain Workflow

**POST** `/admin/domains/{domain}/retry`

Resets a failed/stuck domain and re-dispatches the workflow job.

**URL Parameters:**
- `{domain}` — Domain ID

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "Домен [example.com]: воркфлоу перезапущен (статус: init)."
```

---

### Refresh CF Zone Status

**POST** `/admin/domains/{domain}/refresh-cf-status`

Fetches the current Cloudflare zone status (checks if NS are active).

**URL Parameters:**
- `{domain}` — Domain ID

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "Домен [example.com]: статус CF зоны обновлён → ✅ active (NS работают)"
```

---

## Traffic Switching

### Switch Traffic Mode

**POST** `/admin/domains/{domain}/switch-traffic`

Switches the domain to a different routing mode.

**URL Parameters:**
- `{domain}` — Domain ID

**Request Body:**
```json
{
    "mode": "cf"
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `mode` | required, in:cf,sw,cf_sw |

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "Домен [example.com]: режим переключён [cf_sw] → [cf]."
```

**Response (Error - 302 Redirect):**
```
Location: /admin/domains
Session Flash (error): "Ошибка переключения: Missing stormwall_ip"
```

**Business Rules:**
- Only domains with `status=done` can switch
- Cannot switch to the current mode
- If target mode needs CF but zone doesn't exist, it's created on-the-fly
- If target mode is `sw` or `cf_sw` and SSL not ready, triggers SSL workflow

---

### Revert Traffic Mode

**POST** `/admin/domains/{domain}/revert-traffic`

Reverts the domain to the previous routing mode.

**URL Parameters:**
- `{domain}` — Domain ID

**Request Body:** None (no parameters needed)

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "Домен [example.com]: режим восстановлен [cf] → [cf_sw]."
```

**Response (Error - 302 Redirect):**
```
Location: /admin/domains
Session Flash (error): "Нет сохранённого состояния для восстановления."
```

**Business Rules:**
- Only works if `previous_mode` is set
- Restores DNS and backend configuration from `previous_config` snapshot

---

### Sync CF DNS

**POST** `/admin/domains/{domain}/sync-cf-dns`

Re-applies the correct CF DNS record for the domain's current mode. Useful when a record was created with wrong settings.

**URL Parameters:**
- `{domain}` — Domain ID

**Request Body:** None

**Response (Success - 302 Redirect):**
```
Location: /admin/domains
Session Flash: "CF DNS синхронизирован: [example.com] → 91.217.84.111 (Proxied)."
```

**Response (Error - 302 Redirect):**
```
Location: /admin/domains
Session Flash (error): "У домена [example.com] нет CF зоны."
```

---

## Settings Management

### View Settings

**GET** `/admin/settings`

Returns the HTML page with IP settings form.

**Response:** HTML (Blade template)

---

### Update Settings

**POST** `/admin/settings`

Updates infrastructure IP settings.

**Request Body:**
```json
{
    "server_ips": ["91.217.84.111", "92.123.45.67"],
    "cloudflare_proxy_ips": ["185.71.67.102"]
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| `server_ips` | array |
| `server_ips.*` | IP address |
| `cloudflare_proxy_ips` | array |
| `cloudflare_proxy_ips.*` | IP address |

**Response (Success - 302 Redirect):**
```
Location: /admin/settings
Session Flash: "Settings saved successfully."
```

---

## Data Models

### Domain Status

All possible workflow statuses (from `app/Enums/DomainStatus.php`):

| Status | Description |
|--------|-------------|
| `init` | Domain added, not yet processed |
| `cloudflare_zone` | Creating Cloudflare zone |
| `stormwall_domain` | Registering domain in StormWall |
| `cloudflare_dns` | Creating/updating CF DNS record (A + www) |
| `stormwall_backends` | Adding StormWall backends (ports 80, 443) |
| `stormwall_ssl_requested` | SSL certificate requested from StormWall |
| `waiting_stormwall_ssl` | Polling for SSL activation |
| `sw_backends` | Configuring SW backends (sw mode) |
| `done` | Fully configured and ready |
| `failed` | Error occurred during workflow |

### Domain Model

Complete domain model structure:

```php
{
    id: integer,
    domain: string,
    project_id: integer|null,
    preland_id: integer|null,
    traffic_flow_id: integer|null,
    
    // Routing mode
    mode: 'cf' | 'sw' | 'cf_sw',
    previous_mode: string|null,
    previous_config: {
        mode: string,
        cloudflare_dns_id: string,
        stormwall_ip: string,
        server_ip: string
    }|null,
    active_traffic_receiver: 'cf' | 'sw',
    
    // Workflow state
    status: string,  // See Domain Status table above
    
    // Cloudflare
    cloudflare_zone_id: string|null,
    cloudflare_zone_status: 'pending' | 'active' | 'initializing' | 'moved' | 'deleted' | 'deactivated' | null,
    cloudflare_nameservers: string[]|null,
    cloudflare_dns_id: string|null,
    
    // StormWall
    stormwall_domain_id: integer|null,
    stormwall_ip: string|null,
    stormwall_nameservers: string[]|null,
    
    // Backend
    server_ip: string|null,
    
    // SSL tracking
    ssl_requested_at: Carbon|null,
    ssl_ready_at: Carbon|null,
    
    // Retry logic
    next_attempt_at: Carbon|null,
    retries: integer,
    
    // Timestamps
    created_at: Carbon,
    updated_at: Carbon
}
```

### Settings Model

```php
{
    key: string,     // e.g., "server_ips", "cloudflare_proxy_ips"
    value: array,    // JSON array of IP addresses
    created_at: Carbon,
    updated_at: Carbon
}
```

---

## Status Codes

### Success Responses

| Code | Description |
|------|-------------|
| 200 | OK — Request successful (JSON API) |
| 302 | Redirect — Action successful, redirecting with flash message |

### Error Responses

| Code | Description |
|------|-------------|
| 302 | Redirect with error — Action failed, redirecting with error message |
| 404 | Not Found — Domain or resource not found |
| 422 | Unprocessable Entity — Validation failed |
| 500 | Internal Server Error — Unexpected error |

---

## Frontend Integration Guide

### Live Update System

The domain list page uses JavaScript polling to update domain status in real-time.

**Implementation:**

```javascript
const POLL_INTERVAL = 4000; // 4 seconds
const API_URL = '/admin/domains/api';

function updateDomainList() {
    fetch(API_URL)
        .then(response => response.json())
        .then(domains => {
            domains.forEach(domain => {
                const row = document.querySelector(`tr[data-id="${domain.id}"]`);
                if (row) {
                    // Update status badge
                    row.querySelector('.status-cell').innerHTML = getStatusBadge(domain.status);
                    
                    // Update SSL indicator
                    if (domain.ssl_ready_at) {
                        row.querySelector('.ssl-indicator').innerHTML = '✅ SSL выдан';
                    }
                    
                    // Update CF zone status
                    if (domain.cloudflare_zone_status === 'active') {
                        row.querySelector('.cf-status').innerHTML = '✅ NS активны';
                    }
                }
            });
        })
        .catch(error => {
            console.error('API polling failed:', error);
            document.getElementById('live-dot').className = 'text-warning';
        });
}

// Start polling
setInterval(updateDomainList, POLL_INTERVAL);
```

### Form Submission with Loading State

When submitting forms (e.g., mode switch), show loading overlay:

```javascript
document.addEventListener('submit', function(e) {
    const form = e.target;
    const action = form.getAttribute('action');
    
    if (action && (action.includes('switch-traffic') || action.includes('revert-traffic'))) {
        const row = form.closest('tr[data-id]');
        if (row) {
            row.classList.add('row-loading');
        }
    }
});
```

**CSS:**
```css
tr.row-loading {
    position: relative;
    pointer-events: none;
}
tr.row-loading td {
    filter: blur(2px);
    opacity: 0.6;
}
tr.row-loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 24px;
    height: 24px;
    border: 3px solid #00a72c;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}
```

### Status Badge Rendering

Status badges with descriptions:

```javascript
const STATUS_LABELS = {
    'init':                    'Добавлен, ожидает обработки',
    'cloudflare_zone':         'Создание зоны CF...',
    'stormwall_domain':        'Регистрация домена в SW...',
    'cloudflare_dns':          'Настройка DNS CF...',
    'stormwall_backends':      'Добавление бэкендов SW...',
    'stormwall_ssl_requested': 'SSL-сертификат запрошен, ожидаем...',
    'waiting_stormwall_ssl':   'Ожидаем активации SSL...',
    'sw_backends':             'Настройка бэкендов SW...',
    'done':                    'Настроен успешно',
    'failed':                  'Ошибка на одном из шагов',
};

function getStatusBadge(status) {
    const color = STATUS_COLORS[status] || 'primary';
    let html = `<span class="badge bg-${color}">${status}</span>`;
    if (STATUS_LABELS[status]) {
        html += `<br><small class="text-muted">${STATUS_LABELS[status]}</small>`;
    }
    return html;
}
```

---

## Example Workflows

### Complete Domain Creation Flow

1. **User fills form** (`/admin/domains/create`)
   - Selects mode: `cf_sw`
   - Enters domain: `example.com`
   - Enters server IP: `91.217.84.111`
   - Enters SW IP: `185.71.67.102`

2. **POST** `/admin/domains`
   - Backend validates data
   - Creates domain record with `status=init`
   - Dispatches `ProcessDomainJob`
   - Redirects to `/admin/domains` with success message

3. **Background workflow** (orchestrated by `ProcessDomainJob`)
   ```
   init → cloudflare_zone → stormwall_domain → cloudflare_dns → stormwall_backends 
      → stormwall_ssl_requested → waiting_stormwall_ssl → done
   ```

4. **Frontend polling** (`/admin/domains/api`)
   - Every 4 seconds, fetches domain list
   - Updates table row with current status
   - Shows SSL certificate status when ready
   - Shows CF zone status (pending → active)

5. **User sees final state:**
   - Status: `done`
   - SSL: ✅ SSL выдан
   - CF Zone: ✅ NS активны
   - Ready to handle traffic

### Traffic Mode Switch Flow

1. **User clicks** "Переключить" dropdown → selects `cf`

2. **POST** `/admin/domains/6/switch-traffic`
   ```json
   { "mode": "cf" }
   ```

3. **Backend actions:**
   - Validates domain is in `done` status
   - Saves current mode to `previous_mode`
   - Updates CF DNS: `server_ip`, `proxied=true`
   - Updates `mode=cf`, `active_traffic_receiver=cf`
   - Redirects with success message

4. **Frontend shows:**
   - Loading spinner on row
   - After redirect: mode badge changes to `☁️ CF → Backend`
   - "↩ Вернуть (cf_sw)" button appears

---

## Notes for Frontend Developers

### Data Format Consistency

- All timestamps are ISO 8601 format: `2026-06-04T11:35:07+00:00`
- IP addresses are always strings
- Arrays (`cloudflare_nameservers`, `stormwall_nameservers`) can be empty `[]` or `null`
- Status values are always lowercase strings

### Error Handling

All error responses redirect back with flash messages:

```php
// Check for flash messages in Blade
@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if(session('error'))
    <div class="alert alert-danger">{{ session('error') }}</div>
@endif
```

### Performance Considerations

- Use debouncing for IP filter input
- Only update changed table rows (diff current vs new data)
- Cache CSRF token in JavaScript (don't query DOM on every request)
- Use `visibility: hidden` instead of removing/re-adding rows

### Browser Compatibility

- Tested on Chrome 120+, Firefox 120+, Safari 17+
- Requires JavaScript enabled
- No IE11 support (uses modern ES6+ features)

---

## Questions?

For questions or issues, check:
- Main README: [README.md](./README.md)
- Laravel docs: https://laravel.com/docs
- Laravel queue docs: https://laravel.com/docs/queues
