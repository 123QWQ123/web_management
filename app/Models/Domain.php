<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    protected $fillable = [
        'domain',
        'project_id',
        'preland_id',
        'traffic_flow_id',
        'mode',
        'previous_mode',
        'previous_config',
        'active_traffic_receiver',
        'status',
        'cloudflare_zone_id',
        'cloudflare_nameservers',
        'cloudflare_dns_id',
        'stormwall_domain_id',
        'server_ip',
        'stormwall_ip',
        'cf_proxy_ip',
        'ssl_requested_at',
        'ssl_ready_at',
        'next_attempt_at',
        'retries',
    ];

    protected $casts = [
        'cloudflare_nameservers' => 'array',
        'previous_config'        => 'array',
        'ssl_requested_at'       => 'datetime',
        'ssl_ready_at'           => 'datetime',
        'next_attempt_at'        => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(DomainLog::class);
    }
}
