@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="mb-0">Добавить домен</h1>
        <a href="{{ route('admin.domains.index') }}" class="btn btn-outline-secondary">← Все домены</a>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="post" action="{{ route('admin.domains.store') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label">Домен</label>
            <input name="domain" class="form-control @error('domain') is-invalid @enderror"
                   value="{{ old('domain') }}" required />
            @error('domain')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label class="form-label">Режим маршрутизации</label>
            <select name="mode" id="mode-select" class="form-control">
                <option value="cf"      {{ old('mode','cf') === 'cf'      ? 'selected' : '' }}>☁️  CF Proxied — Cloudflare принимает трафик → бэкенд</option>
                <option value="dns"     {{ old('mode')       === 'dns'    ? 'selected' : '' }}>🔀 DNS Only + SW — CF DNS → StormWall → бэкенд</option>
                <option value="sw_cf"   {{ old('mode')       === 'sw_cf'  ? 'selected' : '' }}>⚡ SW → CF → бэкенд — StormWall принимает трафик, проксирует в CF</option>
                <option value="cf_only" {{ old('mode')       === 'cf_only'? 'selected' : '' }}>🔒 CF Only (failover) — только Cloudflare, без StormWall</option>
                <option value="sw_only" {{ old('mode')       === 'sw_only'? 'selected' : '' }}>🛡️  SW Only (failover) — только StormWall, без Cloudflare</option>
            </select>
            <div id="mode-hint" class="form-text mt-1"></div>
        </div>

        {{-- server_ip: shown for all modes except sw_only-only cases --}}
        <div class="mb-3" id="server-ip-group">
            <label class="form-label" id="server-ip-label">IP сервера (бэкенд)</label>
            <input name="server_ip"
                   list="server-ip-list"
                   class="form-control @error('server_ip') is-invalid @enderror"
                   value="{{ old('server_ip') }}"
                   placeholder="Выберите или введите IP" />
            <datalist id="server-ip-list">
                @foreach($serverIps as $ip)
                    <option value="{{ $ip }}">
                @endforeach
            </datalist>
            @error('server_ip')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="text-muted" id="server-ip-hint">Введите новый IP — он автоматически сохранится в списке.</small>
        </div>

        {{-- cf_proxy_ip: only for sw_cf mode --}}
        <div class="mb-3 d-none" id="cf-proxy-ip-group">
            <label class="form-label">CF Proxy IP <span class="badge bg-secondary">sw_cf</span></label>
            <input name="cf_proxy_ip"
                   list="cf-proxy-ip-list"
                   class="form-control @error('cf_proxy_ip') is-invalid @enderror"
                   value="{{ old('cf_proxy_ip') }}"
                   placeholder="Anycast IP Cloudflare (напр. 104.21.x.x)" />
            <datalist id="cf-proxy-ip-list">
                @foreach($cfProxyIps as $ip)
                    <option value="{{ $ip }}">
                @endforeach
            </datalist>
            @error('cf_proxy_ip')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <small class="text-muted">
                IP, который Cloudflare возвращает клиентам для вашей зоны (anycast).
                StormWall будет проксировать трафик на этот IP.
                Узнать: сделайте DNS-запрос к домену, уже подключённому через CF Proxied.
            </small>
        </div>

        {{-- StormWall IP подтягивается автоматически из API StormWall --}}

        <button class="btn btn-primary">Создать и поставить в очередь</button>
    </form>
</div>

<script>
(function () {
    var modeSelect    = document.getElementById('mode-select');
    var modeHint      = document.getElementById('mode-hint');
    var serverIpGroup = document.getElementById('server-ip-group');
    var serverIpLabel = document.getElementById('server-ip-label');
    var serverIpHint  = document.getElementById('server-ip-hint');
    var cfProxyGroup  = document.getElementById('cf-proxy-ip-group');

    var HINTS = {
        cf:      '☁️  CF принимает трафик и проксирует его на ваш бэкенд-сервер. NS у регистратора → Cloudflare.',
        dns:     '🔀 CF выступает как DNS-провайдер (без проксирования). Трафик идёт CF DNS → StormWall → бэкенд. NS у регистратора → Cloudflare.',
        sw_cf:   '⚡ StormWall принимает трафик и проксирует в Cloudflare, CF отправляет на бэкенд. NS у регистратора → StormWall. Требуется CF Proxy IP.',
        cf_only: '🔒 Failover: только Cloudflare, StormWall отключён. CF проксирует напрямую на бэкенд. NS → Cloudflare.',
        sw_only: '🛡️  Failover: только StormWall, Cloudflare не используется. SW проксирует напрямую на бэкенд. NS у регистратора → StormWall.',
    };

    function update() {
        var mode = modeSelect.value;
        modeHint.textContent = HINTS[mode] || '';

        var isSw   = (mode === 'sw_cf' || mode === 'sw_only');
        var isSwCf = (mode === 'sw_cf');

        // Server IP visibility and labels
        serverIpLabel.textContent = isSw ? 'IP бэкенд-сервера' : 'IP сервера (бэкенд)';
        serverIpHint.textContent  = mode === 'dns'
            ? 'IP вашего бэкенд-сервера. StormWall Proxy IP будет получен автоматически через API.'
            : mode === 'sw_cf'
            ? 'IP вашего бэкенд-сервера. CF проксирует трафик на этот адрес.'
            : 'Введите новый IP — он автоматически сохранится в списке.';

        // CF Proxy IP field: only needed for sw_cf
        cfProxyGroup.classList.toggle('d-none', !isSwCf);
    }

    modeSelect.addEventListener('change', update);
    update();
})();
</script>
@endsection
