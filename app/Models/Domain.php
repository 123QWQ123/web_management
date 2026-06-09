<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a domain alias and its full provisioning + routing state.
 *
 * Routing modes (stored in `mode`):
 *   cf      — Cloudflare receives traffic, proxies to server_ip
 *   sw      — StormWall receives traffic, routes to server_ip (no CF)
 *   cf_sw   — CF receives traffic, proxies to SW, SW routes to server_ip
 *
 * Workflow progression is tracked via `status` (see DomainStatus enum).
 * Mode switches are tracked via `previous_mode` / `previous_config` (for revert).
 */
class Domain extends Model
{
    protected $fillable = [
        'domain',
        'project_id',
        'preland_id',
        'traffic_flow_id',
        'mode',             // Current routing mode: cf | sw | cf_sw
        'previous_mode',    // Mode before the last switch (used by revert)
        'previous_config',  // Snapshot of IPs + DNS IDs before the last switch
        'active_traffic_receiver', // Which service is the primary entry point: cf | sw
        'status',           // Workflow step (see DomainStatus enum)
        'cloudflare_zone_id',
        'cloudflare_zone_status',  // CF zone status: active | pending | initializing | moved | deleted | deactivated
        'cloudflare_nameservers',  // NS servers to set at registrar when CF is primary
        'cloudflare_dns_id',
        'stormwall_domain_id',
        'server_ip',        // Origin backend server IP
        'stormwall_ip',     // StormWall proxy IP (assigned by SW)
        'stormwall_nameservers', // NS servers to set at registrar when SW is primary (dns1-4.storm-pro.net)
        'ssl_requested_at',
        'ssl_ready_at',
        'next_attempt_at',
        'retries',
    ];

    protected $casts = [
        'cloudflare_nameservers'  => 'array',
        'stormwall_nameservers'   => 'array',
        'previous_config'         => 'array',
        'ssl_requested_at'        => 'datetime',
        'ssl_ready_at'            => 'datetime',
        'next_attempt_at'         => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(DomainLog::class);
    }
}
