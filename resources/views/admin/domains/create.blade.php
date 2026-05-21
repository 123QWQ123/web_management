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
            <label class="form-label">Режим</label>
            <select name="mode" class="form-control">
                <option value="cf"  {{ old('mode','cf') === 'cf'  ? 'selected' : '' }}>Cloudflare Proxied</option>
                <option value="dns" {{ old('mode')       === 'dns' ? 'selected' : '' }}>DNS Only (StormWall)</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">IP сервера</label>
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
            <small class="text-muted">Введите новый IP — он автоматически сохранится в списке.</small>
        </div>

        {{-- StormWall IP подтягивается автоматически из API StormWall --}}

        <button class="btn btn-primary">Создать и поставить в очередь</button>
    </form>
</div>

<script>
(function () {
    var modeSelect = document.querySelector('select[name="mode"]');
    var serverIpGroup = document.querySelector('input[name="server_ip"]').closest('.mb-3');

    function updateLabels() {
        var isDns = modeSelect.value === 'dns';
        var label = serverIpGroup.querySelector('.form-label');
        var hint  = serverIpGroup.querySelector('small');

        label.textContent = isDns ? 'IP бэкенд-сервера' : 'IP сервера';
        hint.textContent  = isDns
            ? 'IP вашего бэкенд-сервера. IP StormWall будет получен автоматически через API.'
            : 'Введите новый IP — он автоматически сохранится в списке.';
    }

    modeSelect.addEventListener('change', updateLabels);
    updateLabels();
})();
</script>
@endsection
