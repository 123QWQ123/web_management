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
                <option value="cf"    {{ old('mode','cf') === 'cf'    ? 'selected' : '' }}>☁️ CF → Backend — Cloudflare принимает трафик → бэкенд</option>
                <option value="sw"    {{ old('mode')       === 'sw'   ? 'selected' : '' }}>🛡️ SW → Backend — StormWall принимает трафик → бэкенд</option>
                <option value="cf_sw" {{ old('mode')       === 'cf_sw'? 'selected' : '' }}>🔀 CF → SW → Backend — CF проксирует на StormWall → бэкенд</option>
            </select>
            <div id="mode-hint" class="form-text mt-1"></div>
        </div>

        {{-- server_ip: shown for all modes --}}
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

        {{-- StormWall IP подтягивается автоматически из API StormWall --}}

        <button class="btn btn-primary">Создать и поставить в очередь</button>
    </form>
</div>

<script>
(function () {
    var modeSelect    = document.getElementById('mode-select');
    var modeHint      = document.getElementById('mode-hint');
    var serverIpLabel = document.getElementById('server-ip-label');
    var serverIpHint  = document.getElementById('server-ip-hint');

    var HINTS = {
        cf:    '☁️ CF принимает трафик и проксирует его на ваш бэкенд-сервер. NS у регистратора → Cloudflare.',
        sw:    '🛡️ StormWall принимает трафик и проксирует напрямую на бэкенд. A-запись у регистратора → SW IP.',
        cf_sw: '🔀 CF принимает трафик (proxied=true), DNS-запись → StormWall proxy IP. SW проксирует на бэкенд. NS у регистратора → Cloudflare.',
    };

    function update() {
        var mode = modeSelect.value;
        modeHint.textContent = HINTS[mode] || '';

        var isSw = (mode === 'sw');

        // Server IP visibility and labels
        serverIpLabel.textContent = isSw ? 'IP бэкенд-сервера' : 'IP сервера (бэкенд)';
        serverIpHint.textContent  = mode === 'cf_sw'
            ? 'IP вашего бэкенд-сервера. StormWall Proxy IP будет получен автоматически через API.'
            : 'Введите новый IP — он автоматически сохранится в списке.';
    }

    modeSelect.addEventListener('change', update);
    update();
})();
</script>
@endsection
