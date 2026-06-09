@extends('layouts.app')

@section('content')
<style>
/*noinspection CssUnusedSymbol*/
.tooltip-wide .tooltip-inner {
    max-width: 480px;
    text-align: left;
    white-space: nowrap;
}
.badge-mode-sw_only { background: #343a40; color: #fff; }

/* Loading overlay for table row */
tr.row-loading {
    position: relative;
    pointer-events: none;
}
tr.row-loading td {
    filter: blur(2px);
    opacity: 0.6;
}
tr.row-loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 24px;
    height: 24px;
    border: 3px solid #0d6efd;
    border-top-color: transparent;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    z-index: 10;
}
@keyframes spin {
    to { transform: translate(-50%, -50%) rotate(360deg); }
}
</style>

<div class="page-header d-flex justify-content-between align-items-start">
    <div>
        <h1>Управление доменами</h1>
        <p>Cloudflare + StormWall маршрутизация</p>
    </div>
    <div class="d-flex align-items-center gap-3">
        {{-- Live indicator with status legend tooltip --}}
        <span class="text-muted small d-flex align-items-center gap-2" style="cursor:default"
              data-bs-toggle="tooltip"
              data-bs-placement="bottom"
              data-bs-html="true"
              title="<b>Автообновление каждые 4 сек.</b><br><br>
                     <b>Статусы домена:</b><br>
                     <span class='badge bg-secondary'>init</span> — добавлен, ещё не обработан<br>
                     <span class='badge bg-primary'>cloudflare_zone&nbsp;/&nbsp;stormwall_domain&nbsp;/&nbsp;...</span> — идёт обработка<br>
                     <span class='badge bg-success'>done</span> — всё настроено успешно<br>
                     <span class='badge bg-danger'>failed</span> — ошибка на одном из шагов<br><br>
                     <b>Индикатор:</b><br>
                     &#9679; <span style='color:#198754'>зелёный</span> — соединение активно<br>
                     &#9679; <span style='color:#ffc107'>жёлтый</span> — ошибка запроса к API">
            <span id="live-dot" class="text-success">&#9679;</span>
            <span id="live-clock" style="font-weight:500">—</span>
        </span>
        <a href="{{ route('admin.domains.create') }}" class="btn btn-primary">
            <span style="font-size:1.1rem">+</span> Добавить домен
        </a>
    </div>
</div>

@if(session('status'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('status') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- IP filter --}}
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-2">
            <input id="ip-filter" type="text" class="form-control" style="max-width:280px; border-radius:8px"
                   placeholder="🔍 Фильтр по IP (backend / SW / CF)..."
                   oninput="filterByIp(this.value)">
            <span id="ip-filter-count" class="text-muted small"></span>
        </div>
    </div>
</div>

<div id="domains-empty" class="{{ $domains->isEmpty() ? '' : 'd-none' }}">
    <div class="card">
        <div class="card-body text-center py-5">
            <div style="font-size:3rem; opacity:0.3; margin-bottom:1rem">📋</div>
            <h5 class="text-muted">Доменов пока нет</h5>
            <p class="text-muted small mb-3">Создайте первый домен для начала работы</p>
            <a href="{{ route('admin.domains.create') }}" class="btn btn-primary">+ Добавить домен</a>
        </div>
    </div>
</div>

<div class="card {{ $domains->isEmpty() ? 'd-none' : '' }}" id="domains-table-wrapper">
    <table class="table align-middle mb-0" id="domains-table">
        <thead>
            <tr>
                <th>Домен</th>
                <th>Режим</th>
                <th>Статус</th>
                <th>У регистратора</th>
                <th>SW Proxy IP</th>
                <th>IP сервера</th>
                <th>Добавлен</th>
                <th style="min-width:160px"></th>
            </tr>
        </thead>
        <tbody id="domains-tbody">
            @foreach($domains as $domain)
            @php $ns = $domain->cloudflare_nameservers ?? []; @endphp
            <tr data-id="{{ $domain->id }}"
                data-server-ip="{{ $domain->server_ip }}"
                data-stormwall-ip="{{ $domain->stormwall_ip }}"
                data-ssl-requested-at="{{ $domain->ssl_requested_at?->toIso8601String() }}"
                data-ssl-ready-at="{{ $domain->ssl_ready_at?->toIso8601String() }}">
                <td><strong>{{ $domain->domain }}</strong></td>
                <td>
                    {!! modeBadgePhp($domain->mode) !!}
                </td>
                <td>
                    @php
                        $c = match($domain->status) {
                            'done'   => 'success',
                            'failed' => 'danger',
                            'init'   => 'secondary',
                            default  => 'primary',
                        };
                        $statusLabels = [
                            'init'                    => 'Добавлен, ожидает обработки',
                            'cloudflare_zone'         => 'Создание зоны CF...',
                            'stormwall_domain'        => 'Регистрация домена в SW...',
                            'cloudflare_dns'          => 'Настройка DNS CF...',
                            'stormwall_backends'      => 'Добавление бэкендов SW...',
                            'stormwall_ssl_requested' => 'SSL-сертификат запрошен, ожидаем...',
                            'waiting_stormwall_ssl'   => 'Ожидаем активации SSL...',
                            'sw_backends'             => 'Настройка бэкендов SW...',
                            'done'                    => 'Настроен успешно',
                            'failed'                  => 'Ошибка на одном из шагов',
                        ];
                    @endphp
                    <span class="badge bg-{{ $c }}">{{ $domain->status }}</span>
                    @if(isset($statusLabels[$domain->status]))
                        <br><small class="text-muted">{{ $statusLabels[$domain->status] }}</small>
                    @endif
                    @if($domain->ssl_requested_at && !$domain->ssl_ready_at)
                        <br><small class="text-muted" title="{{ $domain->ssl_requested_at->format('d.m.Y H:i:s') }}">
                            🔐 SSL запрошен {{ $domain->ssl_requested_at->diffForHumans() }}
                        </small>
                    @elseif($domain->ssl_ready_at)
                        <br><small class="text-success" title="{{ $domain->ssl_ready_at->format('d.m.Y H:i:s') }}">
                            ✅ SSL выдан {{ $domain->ssl_ready_at->diffForHumans() }}
                        </small>
                    @endif
                </td>
                <td>
                    @php
                        $cfNs  = $domain->cloudflare_nameservers ?? [];
                        $swNs  = $domain->stormwall_nameservers  ?? [];
                        $swIp  = $domain->stormwall_ip;

                        // cf and cf_sw both use Cloudflare NS at registrar.
                        // sw: delegates NS to StormWall (dns1-4.storm-pro.net)
                        $cfModes = ['cf', 'cf_sw'];
                        $swModes = ['sw'];
                        $needsCfNs   = in_array($domain->mode, $cfModes);
                        $needsSwNs   = in_array($domain->mode, $swModes);

                        // What did the registrar need BEFORE a switch?
                        $prevNeedsCfNs = $domain->previous_mode && in_array($domain->previous_mode, $cfModes);
                        $prevNeedsSwNs = $domain->previous_mode && in_array($domain->previous_mode, $swModes);

                        // Do registrar settings need to change after the switch?
                        $registrarChanged = $domain->previous_mode && (
                            ($needsCfNs  && $prevNeedsSwNs)  ||  // sw/cf_sw → cf
                            ($needsSwNs  && $prevNeedsCfNs)      // cf → sw/cf_sw
                        );
                    @endphp

                    {{-- Current registrar config --}}
                    @if($needsCfNs)
                        {{-- CF zone status indicator --}}
                        @if($domain->cloudflare_zone_id)
                            @if($domain->cloudflare_zone_status === 'active')
                                <div class="mb-2">
                                    <span class="badge bg-success small">✅ NS активны</span>
                                </div>
                            @elseif($domain->cloudflare_zone_status === 'pending')
                                <div class="mb-2">
                                    <span class="badge bg-warning text-dark small">⏳ NS ожидают активации</span>
                                </div>
                            @endif
                        @endif

                        @forelse($cfNs as $i => $server)
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <code class="flex-grow-1" id="ns-{{ $domain->id }}-cf-{{ $i }}">{{ $server }}</code>
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        onclick="copyNs('ns-{{ $domain->id }}-cf-{{ $i }}', this)">&#128203;</button>
                            </div>
                        @empty
                            <span class="text-muted small">NS ещё не получены</span>
                        @endforelse
                        @if(!empty($cfNs))
                            <div class="mt-1">
                                <span class="badge bg-info text-dark">
                                    ☁️ Прописать NS Cloudflare у регистратора
                                </span>
                            </div>
                        @endif
                    @elseif($needsSwNs)
                        @if(!empty($swNs))
                            @foreach($swNs as $i => $server)
                                <div class="d-flex align-items-center gap-1 mb-1">
                                    <code class="flex-grow-1" id="ns-{{ $domain->id }}-sw-{{ $i }}">{{ $server }}</code>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-secondary py-0 px-1"
                                            onclick="copyNs('ns-{{ $domain->id }}-sw-{{ $i }}', this)">&#128203;</button>
                                </div>
                            @endforeach
                            <div class="mt-1">
                                <span class="badge bg-secondary">
                                    🛡️ Прописать NS StormWall у регистратора
                                </span>
                            </div>
                        @elseif($swIp)
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <code class="flex-grow-1" id="ns-{{ $domain->id }}-swip">{{ $swIp }}</code>
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        onclick="copyNs('ns-{{ $domain->id }}-swip', this)">&#128203;</button>
                            </div>
                            <div class="mt-1">
                                <span class="badge bg-warning text-dark">⏳ NS StormWall ещё загружаются</span>
                            </div>
                        @else
                            <span class="text-muted small">SW NS ещё не получены</span>
                        @endif
                    @else
                        <span class="text-muted">—</span>
                    @endif

                    {{-- After-switch warning: registrar config needs to change --}}
                    @if($registrarChanged)
                        <div class="mt-1 p-1 border border-warning rounded small">
                            <span class="text-warning fw-bold">⚠️ Нужно изменить у регистратора:</span><br>
                            @if($needsCfNs && $prevNeedsSwNs)
                                Удалить NS StormWall → заменить на NS Cloudflare ☁️
                                @if(!empty($cfNs))
                                    @foreach($cfNs as $s)
                                        <br><code class="small">{{ $s }}</code>
                                    @endforeach
                                @endif
                            @elseif($needsSwNs && $prevNeedsCfNs)
                                Удалить NS Cloudflare → прописать NS StormWall 🛡️
                                @if(!empty($swNs))
                                    @foreach($swNs as $s)
                                        <br><code class="small">{{ $s }}</code>
                                    @endforeach
                                @endif
                            @endif
                        </div>
                    @elseif($domain->previous_mode && !$registrarChanged)
                        {{-- Switch happened but NS don't change (within CF modes) --}}
                        <div class="mt-1">
                            <span class="badge bg-success small">✓ NS не меняются</span>
                        </div>
                    @endif
                </td>
                <td>{{ $domain->stormwall_ip ?? '—' }}</td>
                <td>{{ $domain->server_ip ?? '—' }}</td>
                <td class="text-muted">{{ $domain->created_at->format('d.m.Y H:i') }}</td>
                <td>
                    <div class="d-flex flex-column gap-1">
                        {{-- Traffic switcher (only for done domains) --}}
                        @if($domain->status === 'done')
                            @php
                                $switchTargets = switchTargets($domain->mode);
                            @endphp
                            @if(count($switchTargets))
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-primary w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    Переключить
                                </button>
                                <ul class="dropdown-menu">
                                    @foreach($switchTargets as $tMode => $tLabel)
                                    <li>
                                        <form action="{{ route('admin.domains.switch-traffic', $domain) }}" method="POST" class="px-3 py-1">
                                            @csrf
                                            <input type="hidden" name="mode" value="{{ $tMode }}">
                                            <button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none">{{ $tLabel }}</button>
                                        </form>
                                    </li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif
                            @if($domain->previous_mode)
                            <form action="{{ route('admin.domains.revert-traffic', $domain) }}" method="POST">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-warning w-100"
                                        onclick="return confirm('Вернуть режим «{{ $domain->previous_mode }}»?')">
                                    ↩ Вернуть ({{ $domain->previous_mode }})
                                </button>
                            </form>
                            @endif
                        @endif
                        <form action="{{ route('admin.domains.destroy', $domain) }}" method="POST"
                              onsubmit="return confirm('Удалить {{ $domain->domain }} из Cloudflare, StormWall и базы данных?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger w-100">Удалить</button>
                        </form>
                        @if($domain->cloudflare_zone_id && in_array($domain->mode, ['cf', 'cf_sw']))
                        <form action="{{ route('admin.domains.refresh-cf-status', $domain) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-outline-info w-100">🔄 Обновить статус CF</button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
</div>

@php
function modeBadgePhp(string $mode): string {
    return match($mode) {
        'cf'    => '<span class="badge bg-warning text-dark">☁️ CF → Backend</span>',
        'sw'    => '<span class="badge badge-mode-sw_only">🛡️ SW → Backend</span>',
        'cf_sw' => '<span class="badge bg-info text-dark">🔀 CF → SW</span>',
        default => '<span class="badge bg-secondary">' . e($mode) . '</span>',
    };
}

function switchTargets(string $mode): array {
    $all = [
        'cf'    => '☁️ CF → Backend',
        'sw'    => '🛡️ SW → Backend',
        'cf_sw' => '🔀 CF → SW → Backend',
    ];
    unset($all[$mode]);
    return $all;
}
@endphp

<script>
var API_URL     = '{{ route('admin.domains.api') }}';
var DELETE_BASE = '{{ url('admin/domains') }}';
var CSRF        = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

var STATUS_COLORS = { done: 'success', failed: 'danger', init: 'secondary' };
var STATUS_LABELS = {
    'init':                    'Добавлен, ожидает обработки',
    'cloudflare_zone':         'Создание зоны CF...',
    'stormwall_domain':        'Регистрация домена в SW...',
    'cloudflare_dns':          'Настройка DNS CF...',
    'stormwall_backends':      'Добавление бэкендов SW...',
    'stormwall_ssl_requested': 'SSL-сертификат запрошен, ожидаем...',
    'waiting_stormwall_ssl':   'Ожидаем активации SSL...',
    'sw_backends':             'Настройка бэкендов SW...',
    'done':                    'Настроен успешно',
    'failed':                  'Ошибка на одном из шагов',
};

function statusBadge(status, sslRequestedAt, sslReadyAt) {
    var color = STATUS_COLORS[status] || 'primary';
    var html = '<span class="badge bg-' + color + '">' + status + '</span>';
    if (STATUS_LABELS[status]) {
        html += '<br><small class="text-muted">' + STATUS_LABELS[status] + '</small>';
    }
    if (sslReadyAt) {
        html += '<br><small class="text-success">✅ SSL выдан</small>';
    } else if (sslRequestedAt) {
        var ago = sslAgo(sslRequestedAt);
        html += '<br><small class="text-muted" title="' + sslRequestedAt + '">🔐 SSL ' + ago + '</small>';
    }
    return html;
}

function sslAgo(isoStr) {
    if (!isoStr) return '';
    var diff = Math.floor((Date.now() - new Date(isoStr).getTime()) / 1000);
    if (diff < 60)  return diff + ' сек назад';
    if (diff < 3600) return Math.floor(diff/60) + ' мин назад';
    return Math.floor(diff/3600) + ' ч назад';
}

function modeBadge(mode) {
    var badges = {
        cf:    '<span class="badge bg-warning text-dark">☁️ CF → Backend</span>',
        sw:    '<span class="badge badge-mode-sw_only">🛡️ SW → Backend</span>',
        cf_sw: '<span class="badge bg-info text-dark">🔀 CF → SW</span>',
    };
    return badges[mode] || '<span class="badge bg-secondary">' + mode + '</span>';
}

// cf and cf_sw both use Cloudflare NS at registrar.
// sw: delegates NS to StormWall (dns1-4.storm-pro.net)
var CF_NS_MODES = { cf: 1, cf_sw: 1 };
var SW_A_MODES  = { sw: 1 };

function registrarCell(d) {
    var html      = '';
    var needsCfNs = !!CF_NS_MODES[d.mode];
    var needsSwNs = !!SW_A_MODES[d.mode];
    var cfNs      = d.cloudflare_nameservers || [];
    var swNs      = d.stormwall_nameservers  || [];
    var swIp      = d.stormwall_ip || '';

    // Current config
    if (needsCfNs) {
        // CF zone status indicator
        if (d.cloudflare_zone_id) {
            if (d.cloudflare_zone_status === 'active') {
                html += '<div class="mb-2"><span class="badge bg-success small">✅ NS активны</span></div>';
            } else if (d.cloudflare_zone_status === 'pending') {
                html += '<div class="mb-2"><span class="badge bg-warning text-dark small">⏳ NS ожидают активации</span></div>';
            }
        }

        if (cfNs.length) {
            cfNs.forEach(function(server, i) {
                var id = 'ns-' + d.id + '-cf-' + i;
                html += '<div class="d-flex align-items-center gap-1 mb-1">' +
                    '<code class="flex-grow-1" id="' + id + '">' + server + '</code>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"' +
                    ' onclick="copyNs(\'' + id + '\', this)">&#128203;</button></div>';
            });
            html += '<div class="mt-1"><span class="badge bg-info text-dark">☁️ Прописать NS Cloudflare у регистратора</span></div>';
        } else {
            html += '<span class="text-muted small">NS ещё не получены</span>';
        }
    } else if (needsSwNs) {
        if (swNs.length) {
            swNs.forEach(function(server, i) {
                var id = 'ns-' + d.id + '-sw-' + i;
                html += '<div class="d-flex align-items-center gap-1 mb-1">' +
                    '<code class="flex-grow-1" id="' + id + '">' + server + '</code>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"' +
                    ' onclick="copyNs(\'' + id + '\', this)">&#128203;</button></div>';
            });
            html += '<div class="mt-1"><span class="badge bg-secondary">🛡️ Прописать NS StormWall у регистратора</span></div>';
        } else if (swIp) {
            // Fallback: SW IP as A-record if NS not yet fetched
            var swId = 'ns-' + d.id + '-swip';
            html += '<div class="d-flex align-items-center gap-1 mb-1">' +
                '<code class="flex-grow-1" id="' + swId + '">' + swIp + '</code>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"' +
                ' onclick="copyNs(\'' + swId + '\', this)">&#128203;</button></div>' +
                '<div class="mt-1"><span class="badge bg-warning text-dark">⏳ NS StormWall ещё загружаются</span></div>';
        } else {
            html += '<span class="text-muted small">SW NS ещё не получены</span>';
        }
    } else {
        html += '<span class="text-muted">—</span>';
    }

    // After-switch warning
    if (d.previous_mode) {
        var prevCfNs = !!CF_NS_MODES[d.previous_mode];
        var prevSwNs = !!SW_A_MODES[d.previous_mode];
        if (needsCfNs && prevSwNs) {
            html += '<div class="mt-1 p-1 border border-warning rounded small">' +
                '<span class="text-warning fw-bold">⚠️ Нужно изменить у регистратора:</span><br>' +
                'Удалить NS StormWall → заменить на NS Cloudflare ☁️' +
                (cfNs.length ? '<br>' + cfNs.map(function(s) { return '<code class="small">' + s + '</code>'; }).join('<br>') : '') +
                '</div>';
        } else if (needsSwNs && prevCfNs) {
            html += '<div class="mt-1 p-1 border border-warning rounded small">' +
                '<span class="text-warning fw-bold">⚠️ Нужно изменить у регистратора:</span><br>' +
                'Удалить NS Cloudflare → прописать NS StormWall 🛡️' +
                (swNs.length ? '<br>' + swNs.map(function(s) { return '<code class="small">' + s + '</code>'; }).join('<br>') : '') +
                '</div>';
        } else {
            html += '<div class="mt-1"><span class="badge bg-success small">✓ NS не меняются</span></div>';
        }
    }

    return html;
}

function buildRow(d) {
    var actions = '';
    if (d.status === 'done') {
        var targets = switchTargetsJs(d.mode);
        if (targets.length) {
            var opts = targets.map(function(t) {
                return '<li><form action="' + DELETE_BASE + '/' + d.id + '/switch-traffic" method="POST" class="px-3 py-1">' +
                    '<input type="hidden" name="_token" value="' + CSRF + '">' +
                    '<input type="hidden" name="mode" value="' + t.mode + '">' +
                    '<button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none">' + t.label + '</button>' +
                    '</form></li>';
            }).join('');
            actions += '<div class="dropdown mb-1"><button class="btn btn-sm btn-outline-primary w-100 dropdown-toggle" type="button" data-bs-toggle="dropdown">Переключить</button>' +
                '<ul class="dropdown-menu">' + opts + '</ul></div>';
        }
        if (d.previous_mode) {
            actions += '<form action="' + DELETE_BASE + '/' + d.id + '/revert-traffic" method="POST" class="mb-1">' +
                '<input type="hidden" name="_token" value="' + CSRF + '">' +
                '<button type="submit" class="btn btn-sm btn-outline-warning w-100" onclick="return confirm(\'Вернуть режим «' + d.previous_mode + '»?\')">↩ Вернуть (' + d.previous_mode + ')</button>' +
                '</form>';
        }
    }
    actions += '<form action="' + DELETE_BASE + '/' + d.id + '" method="POST"' +
        ' onsubmit="return confirm(\'Удалить ' + d.domain + '?\')">' +
        '<input type="hidden" name="_token" value="' + CSRF + '">' +
        '<input type="hidden" name="_method" value="DELETE">' +
        '<button type="submit" class="btn btn-sm btn-danger w-100">Удалить</button>' +
        '</form>';
    if (d.cloudflare_zone_id && (d.mode === 'cf' || d.mode === 'cf_sw')) {
        actions += '<form action="' + DELETE_BASE + '/' + d.id + '/sync-cf-dns" method="POST">' +
            '<input type="hidden" name="_token" value="' + CSRF + '">' +
            '<button type="submit" class="btn btn-sm btn-outline-secondary w-100" onclick="return confirm(\'Пересинхронизировать CF DNS запись?\')">☁️ Sync CF DNS</button>' +
            '</form>';
        actions += '<form action="' + DELETE_BASE + '/' + d.id + '/refresh-cf-status" method="POST">' +
            '<input type="hidden" name="_token" value="' + CSRF + '">' +
            '<button type="submit" class="btn btn-sm btn-outline-info w-100">🔄 Обновить статус CF</button>' +
            '</form>';
    }
    var retryStatuses = { failed: 1, stormwall_ssl_requested: 1, waiting_stormwall_ssl: 1 };
    if (retryStatuses[d.status]) {
        actions += '<form action="' + DELETE_BASE + '/' + d.id + '/retry" method="POST">' +
            '<input type="hidden" name="_token" value="' + CSRF + '">' +
            '<button type="submit" class="btn btn-sm btn-outline-info w-100">🔄 Перезапустить</button>' +
            '</form>';
    }

    return '<tr data-id="' + d.id + '"' +
        ' data-server-ip="' + (d.server_ip || '') + '"' +
        ' data-stormwall-ip="' + (d.stormwall_ip || '') + '">' +
        '<td><strong>' + d.domain + '</strong></td>' +
        '<td>' + modeBadge(d.mode) + '</td>' +
        '<td>' + statusBadge(d.status, d.ssl_requested_at, d.ssl_ready_at) + '</td>' +
        '<td>' + registrarCell(d) + '</td>' +
        '<td>' + (d.stormwall_ip || '—') + '</td>' +
        '<td>' + (d.server_ip || '—') + '</td>' +
        '<td class="text-muted">' + d.created_at + '</td>' +
        '<td><div class="d-flex flex-column gap-1">' + actions + '</div></td></tr>';
}

function switchTargetsJs(mode) {
    var all = [
        { mode: 'cf',    label: '☁️ CF → Backend' },
        { mode: 'sw',    label: '🛡️ SW → Backend' },
        { mode: 'cf_sw', label: '🔀 CF → SW → Backend' },
    ];
    return all.filter(function(t) { return t.mode !== mode; });
}

function refresh() {
    fetch(API_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); })
        .then(function(domains) {
            var tbody = document.getElementById('domains-tbody');
            var table = document.getElementById('domains-table');
            var empty = document.getElementById('domains-empty');

            if (domains.length === 0) {
                table.classList.add('d-none');
                empty.classList.remove('d-none');
                return;
            }
            table.classList.remove('d-none');
            empty.classList.add('d-none');

            var existingIds = new Set(
                Array.from(tbody.querySelectorAll('tr[data-id]')).map(function(r) { return Number(r.dataset.id); })
            );
            var freshIds = new Set(domains.map(function(d) { return d.id; }));

            existingIds.forEach(function(id) {
                if (!freshIds.has(id)) {
                    var row = tbody.querySelector('tr[data-id="' + id + '"]');
                    if (row) row.remove();
                }
            });

            domains.forEach(function(d, idx) {
                var existing = tbody.querySelector('tr[data-id="' + d.id + '"]');
                if (existing) {
                    existing.cells[1].innerHTML = modeBadge(d.mode);
                    existing.cells[2].innerHTML = statusBadge(d.status, d.ssl_requested_at, d.ssl_ready_at);
                    existing.cells[3].innerHTML = registrarCell(d);
                    existing.cells[4].textContent = d.stormwall_ip || '—';
                    existing.dataset.serverIp    = d.server_ip    || '';
                    existing.dataset.stormwallIp = d.stormwall_ip || '';
                } else {
                    var tmp = document.createElement('tbody');
                    tmp.innerHTML = buildRow(d);
                    tbody.insertBefore(tmp.firstChild, tbody.children[idx] || null);
                }
            });

            // Reapply filter after refresh
            var filterVal = document.getElementById('ip-filter').value;
            if (filterVal) filterByIp(filterVal);

            // Blink clock
            var clock = document.getElementById('live-clock');
            clock.style.opacity = '0.25';
            setTimeout(function() { clock.style.opacity = ''; }, 250);

            document.getElementById('live-dot').className = 'text-success';
        })
        .catch(function() {
            document.getElementById('live-dot').className = 'text-warning';
        });
}

function filterByIp(query) {
    var rows  = document.querySelectorAll('#domains-tbody tr[data-id]');
    var q     = query.trim().toLowerCase();
    var shown = 0;

    rows.forEach(function(row) {
        var ips = [
            row.dataset.serverIp,
            row.dataset.stormwallIp,
        ].join(' ').toLowerCase();

        var match = !q || ips.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) shown++;
    });

    var counter = document.getElementById('ip-filter-count');
    if (q) {
        counter.textContent = 'Показано: ' + shown + ' из ' + rows.length;
    } else {
        counter.textContent = '';
    }
}

setInterval(refresh, 4000);
refresh();

// Live clock — ticks every second
function tickClock() {
    var el = document.getElementById('live-clock');
    if (el) el.textContent = new Date().toLocaleTimeString('ru-RU');
}
setInterval(tickClock, 1000);
tickClock();

// Init Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    var els = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    els.forEach(function(el) {
        new bootstrap.Tooltip(el, {
            sanitize: false,
            customClass: 'tooltip-wide'
        });
    });
});

function copyNs(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.textContent.trim()).then(function() {
        var orig = btn.innerHTML;
        btn.innerHTML = '&#10003;';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.classList.replace('btn-success', 'btn-outline-secondary');
        }, 1500);
    });
}

// Add loading overlay to table row when switching mode
document.addEventListener('submit', function(e) {
    var form = e.target;
    var action = form.getAttribute('action');
    
    // Check if it's a switch/revert traffic form
    if (action && (action.includes('switch-traffic') || action.includes('revert-traffic'))) {
        var row = form.closest('tr[data-id]');
        if (row) {
            row.classList.add('row-loading');
        }
    }
});
</script>

@endsection
