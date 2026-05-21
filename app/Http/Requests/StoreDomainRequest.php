<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain'          => ['required', 'string', 'max:253'],
            'project_id'      => ['nullable', 'integer'],
            'preland_id'      => ['nullable', 'integer'],
            'traffic_flow_id' => ['nullable', 'integer'],
            'mode'            => ['required', 'in:cf,dns,sw_cf,cf_only,sw_only'],
            'server_ip'       => ['nullable', 'ip'],
            'cf_proxy_ip'     => ['nullable', 'ip'],
            // stormwall_ip is populated automatically from the StormWall API in dns/sw modes
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'server_ip'   => $this->server_ip   ?: null,
            'cf_proxy_ip' => $this->cf_proxy_ip ?: null,
        ]);
    }
}
