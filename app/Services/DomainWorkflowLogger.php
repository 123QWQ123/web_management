<?php

namespace App\Services;

use App\Models\Domain;

class DomainWorkflowLogger
{
    public function success(Domain $domain, string $step, array $request = [], array $response = []): void
    {
        $this->write($domain, $step, $request, $response, true);
    }

    public function failure(Domain $domain, string $step, array $request = [], array $response = []): void
    {
        $this->write($domain, $step, $request, $response, false);
    }

    private function write(
        Domain $domain,
        string $step,
        array $request,
        array $response,
        bool $success
    ): void {
        $domain->logs()->create([
            'step' => $step,
            'request' => $request === [] ? null : $request,
            'response' => $response === [] ? null : $response,
            'success' => $success,
        ]);
    }
}
