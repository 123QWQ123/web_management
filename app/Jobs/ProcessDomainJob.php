<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\DomainOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $domain;

    public function __construct(

        public int $domainId

    ) {}

    public function handle(DomainOrchestrator $orchestrator)
    {
        $domain = Domain::findOrFail($this->domainId);

        Log::channel('domain')->info('Job picked up', [
            'domain_id' => $domain->id,
            'domain'    => $domain->domain,
            'status'    => $domain->status,
        ]);

        $orchestrator->handle($domain);

        Log::channel('domain')->info('Job finished', [
            'domain_id' => $domain->id,
            'domain'    => $domain->domain,
            'status'    => $domain->fresh()->status,
        ]);
    }
}
