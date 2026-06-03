<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDomainJob;
use App\Models\Domain;
use Illuminate\Http\Request;

class DomainController extends Controller
{
    public function store(Request $request)
    {
        $domain = Domain::create([
            'domain' => $request->domain,
            'mode'   => $request->mode,
            'status' => 'init',
        ]);

        ProcessDomainJob::dispatch($domain->id);

        return response()->json($domain);
    }

    public function show(Domain $domain)
    {
        return $domain->load('logs');
    }
}
