<?php

namespace App\Enums;

enum DomainStatus: string
{
    case INIT = 'init';

    case CLOUDFLARE_ZONE = 'cloudflare_zone';

    // DNS-only: StormWall domain is created first to obtain the proxy IP
    case STORMWALL_DOMAIN = 'stormwall_domain';

    case CLOUDFLARE_DNS = 'cloudflare_dns';

    // DNS-only: backends added after DNS is set
    case STORMWALL_BACKENDS = 'stormwall_backends';

    case STORMWALL_SSL_REQUESTED = 'stormwall_ssl_requested';

    case WAITING_STORMWALL_SSL = 'waiting_stormwall_ssl';

    case DONE = 'done';

    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::DONE, self::FAILED], true);
    }
}
