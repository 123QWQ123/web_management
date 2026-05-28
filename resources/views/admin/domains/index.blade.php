@extends('layouts.app')

@section('content')
<style>
.tooltip-wide .tooltip-inner {
    max-width: 480px;
    text-align: left;
    white-space: nowrap;
}
.badge-mode-sw_cf   { background: #6f42c1; color: #fff; }
.badge-mode-sw_only { background: #343a40; color: #fff; }
</style>
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="mb-0">Домены</h1>
        <div class="d-flex align-items-center gap-3">
            {{-- Live indicator with status legend tooltip --}}
            <span class="text-muted small" style="cursor:default"
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
                <span id="live-indicator">
                    <span id="live-dot" class="text-success">&#9679;</span>
                    Прямой эфир &mdash; <span id="live-clock" style="transition:opacity 0.25s; display:inline-block; width:6ch; text-align:right">—</span>
                </span>
            </span>
            <a href="{{ route('admin.domains.create') }}" class="btn btn-primary">+ Добавить домен</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert alert-success alert-dismissible fade show py-2">{{ session('status') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show py-2">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- IP filter --}}
    <div class="d-flex align-items-center gap-2 mb-2">
        <input id="ip-filter" type="text" class="form-control form-control-sm" style="max-width:220px"
               placeholder="🔍 Фильтр по IP (backend / SW / CF)..."
               oninput="filterByIp(this.value)">
        <span id="ip-filter-count" class="text-muted small"></span>
    </div>

    <div id="domains-empty" class="{{ $domains->isEmpty() ? '' : 'd-none' }}">
        <div class="alert alert-secondary">Доменов пока нет.</div>
    </div>

    <table class="table table-bordered table-hover align-middle small {{ $domains->isEmpty() ? 'd-none' : '' }}" id="domains-table">
        <thead class="table-dark">
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
                data-cf-proxy-ip="{{ $domain->cf_proxy_ip }}">
                <td><strong>{{ $domain->domain }}</strong></td>
                <td>{!! modeBadgePhp($domain->mode) !!}</td>
                <td>
                    @php
                        $c = match($domain->status) {
                            'done'   => 'success',
                            'failed' => 'danger',
                            'init'   => 'secondary',
                            default  => 'primary',
                        };
                    @endphp
                    <span class="badge bg-{{ $c }}">{{ $domain->status }}</span>
                </td>
                <td>
                    @php
                        $ns  = $domain->cloudflare_nameservers ?? [];
                        $swIp = $domain->stormwall_ip;

                        // Does this mode require CF NS at registrar?
                        $cfModes = ['cf','dns','cf_only','sw_cf'];
                        $needsCfNs   = in_array($domain->mode, $cfModes);
                        $needsSwA    = $domain->mode === 'sw_only';

                        // What did the registrar need BEFORE a switch?
                        $prevNeedsCfNs = $domain->previous_mode && in_array($domain->previous_mode, $cfModes);
                        $prevNeedsSwA  = $domain->previous_mode === 'sw_only';

                        // Do registrar settings need to change after the switch?
                        $registrarChanged = $domain->previous_mode && (
                            ($needsCfNs  && $prevNeedsSwA)  ||  // sw_only → CF mode
                            ($needsSwA   && $prevNeedsCfNs)     // CF mode → sw_only
                        );
                    @endphp

                    {{-- Current registrar config --}}
                    @if($needsCfNs)
                        @forelse($ns as $i => $server)
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <code class="flex-grow-1" id="ns-{{ $domain->id }}-{{ $i }}">{{ $server }}</code>
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        onclick="copyNs('ns-{{ $domain->id }}-{{ $i }}', this)">&#128203;</button>
                            </div>
                        @empty
                            <span class="text-muted small">NS ещё не получены</span>
                        @endforelse
                        @if(!empty($ns))
                            <div class="mt-1">
                                <span class="badge bg-info text-dark">
                                    ☁️ Прописать NS Cloudflare у регистратора
                                </span>
                            </div>
                        @endif
                    @elseif($needsSwA)
                        @if($swIp)
                            <div class="d-flex align-items-center gap-1 mb-1">
                                <code class="flex-grow-1" id="ns-{{ $domain->id }}-sw">{{ $swIp }}</code>
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        onclick="copyNs('ns-{{ $domain->id }}-sw', this)">&#128203;</button>
                            </div>
                            <div class="mt-1">
                                <span class="badge bg-secondary">
                                    🛡️ A-запись → SW IP у регистратора
                                </span>
                            </div>
                        @else
                            <span class="text-muted small">SW IP ещё не получен</span>
                        @endif
                    @else
                        <span class="text-muted">—</span>
                    @endif

                    {{-- After-switch warning: registrar config needs to change --}}
                    @if($registrarChanged)
                        <div class="mt-1 p-1 border border-warning rounded small">
                            <span class="text-warning fw-bold">⚠️ Нужно изменить у регистратора:</span><br>
                            @if($needsCfNs && $prevNeedsSwA)
                                Удалить A-запись → заменить на NS Cloudflare ☁️
                                @if(!empty($ns))
                                    @foreach($ns as $s)
                                        <br><code class="small">{{ $s }}</code>
                                    @endforeach
                                @endif
                            @elseif($needsSwA && $prevNeedsCfNs)
                                Удалить NS Cloudflare → прописать A-запись 🛡️<br>
                                @if($swIp)<code class="small">{{ $swIp }}</code>@endif
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
                                        @if($tMode === 'sw_cf')
                                        {{-- sw_cf needs cf_proxy_ip — open modal instead of direct submit --}}
                                        <button type="button"
                                                class="btn btn-sm btn-link p-0 text-decoration-none px-3 py-1 w-100 text-start"
                                                onclick="openSwCfModal(
                                                    '{{ route('admin.domains.switch-traffic', $domain) }}',
                                                    '{{ addslashes($domain->cf_proxy_ip ?? '') }}'
                                                )">{{ $tLabel }}</button>
                                        @else
                                        <form action="{{ route('admin.domains.switch-traffic', $domain) }}" method="POST" class="px-3 py-1">
                                            @csrf
                                            <input type="hidden" name="mode" value="{{ $tMode }}">
                                            <button type="submit" class="btn btn-sm btn-link p-0 text-decoration-none">{{ $tLabel }}</button>
                                        </form>
                                        @endif
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
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

@php
function modeBadgePhp(string $mode): string {
    return match($mode) {
        'cf'      => '<span class="badge bg-warning text-dark">CF Proxied</span>',
        'dns'     => '<span class="badge bg-info text-dark">DNS + SW</span>',
        'sw_cf'   => '<span class="badge badge-mode-sw_cf">SW → CF</span>',
        'cf_only' => '<span class="badge bg-warning text-dark">CF Only</span>',
        'sw_only' => '<span class="badge badge-mode-sw_only">SW Only</span>',
        default   => '<span class="badge bg-secondary">' . e($mode) . '</span>',
    };
}

function switchTargets(string $mode): array {
    $all = [
        'cf'      => '☁️ CF Proxied — Cloudflare принимает трафик → бэкенд',
        'dns'     => '🔀 DNS + SW — CF DNS → StormWall → бэкенд',
        'sw_cf'   => '⚡ SW → CF → бэкенд',
        'cf_only' => '🔒 CF Only (failover)',
        'sw_only' => '🛡️ SW Only (failover)',
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

function statusBadge(status) {
    var color = STATUS_COLORS[status] || 'primary';
    return '<span class="badge bg-' + color + '">' + status + '</span>';
}

function modeBadge(mode) {
    var badges = {
        cf:      '<span class="badge bg-warning text-dark">CF Proxied</span>',
        dns:     '<span class="badge bg-info text-dark">DNS + SW</span>',
        sw_cf:   '<span class="badge badge-mode-sw_cf">SW → CF</span>',
        cf_only: '<span class="badge bg-warning text-dark">CF Only</span>',
        sw_only: '<span class="badge badge-mode-sw_only">SW Only</span>',
    };
    return badges[mode] || '<span class="badge bg-secondary">' + mode + '</span>';
}

var CF_MODES = { cf: 1, dns: 1, cf_only: 1, sw_cf: 1 };

function registrarCell(d) {
    var html      = '';
    var needsCfNs = !!CF_MODES[d.mode];
    var needsSwA  = d.mode === 'sw_only';
    var ns        = d.cloudflare_nameservers || [];
    var swIp      = d.stormwall_ip || '';

    // Current config
    if (needsCfNs) {
        if (ns.length) {
            ns.forEach(function(server, i) {
                var id = 'ns-' + d.id + '-' + i;
                html += '<div class="d-flex align-items-center gap-1 mb-1">' +
                    '<code class="flex-grow-1" id="' + id + '">' + server + '</code>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"' +
                    ' onclick="copyNs(\'' + id + '\', this)">&#128203;</button></div>';
            });
            html += '<div class="mt-1"><span class="badge bg-info text-dark">☁️ Прописать NS Cloudflare у регистратора</span></div>';
        } else {
            html += '<span class="text-muted small">NS ещё не получены</span>';
        }
    } else if (needsSwA) {
        if (swIp) {
            var swId = 'ns-' + d.id + '-sw';
            html += '<div class="d-flex align-items-center gap-1 mb-1">' +
                '<code class="flex-grow-1" id="' + swId + '">' + swIp + '</code>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"' +
                ' onclick="copyNs(\'' + swId + '\', this)">&#128203;</button></div>' +
                '<div class="mt-1"><span class="badge bg-secondary">🛡️ A-запись → SW IP у регистратора</span></div>';
        } else {
            html += '<span class="text-muted small">SW IP ещё не получен</span>';
        }
    } else {
        html += '<span class="text-muted">—</span>';
    }

    // After-switch warning
    if (d.previous_mode) {
        var prevCfNs = !!CF_MODES[d.previous_mode];
        var prevSwA  = d.previous_mode === 'sw_only';
        if (needsCfNs && prevSwA) {
            html += '<div class="mt-1 p-1 border border-warning rounded small">' +
                '<span class="text-warning fw-bold">⚠️ Нужно изменить у регистратора:</span><br>' +
                'Удалить A-запись → заменить на NS Cloudflare ☁️' +
                (ns.length ? '<br>' + ns.map(function(s) { return '<code class="small">' + s + '</code>'; }).join('<br>') : '') +
                '</div>';
        } else if (needsSwA && prevCfNs) {
            html += '<div class="mt-1 p-1 border border-warning rounded small">' +
                '<span class="text-warning fw-bold">⚠️ Нужно изменить у регистратора:</span><br>' +
                'Удалить NS Cloudflare → прописать A-запись 🛡️' +
                (swIp ? '<br><code class="small">' + swIp + '</code>' : '') +
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
                if (t.mode === 'sw_cf') {
                    return '<li><button type="button" class="btn btn-sm btn-link p-0 text-decoration-none px-3 py-1 w-100 text-start"' +
                        ' onclick="openSwCfModal(\'' + DELETE_BASE + '/' + d.id + '/switch-traffic\', \'' + (d.cf_proxy_ip || '') + '\')">' +
                        t.label + '</button></li>';
                }
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

    return '<tr data-id="' + d.id + '"' +
        ' data-server-ip="' + (d.server_ip || '') + '"' +
        ' data-stormwall-ip="' + (d.stormwall_ip || '') + '"' +
        ' data-cf-proxy-ip="' + (d.cf_proxy_ip || '') + '">' +
        '<td><strong>' + d.domain + '</strong></td>' +
        '<td>' + modeBadge(d.mode) + '</td>' +
        '<td>' + statusBadge(d.status) + '</td>' +
        '<td>' + registrarCell(d) + '</td>' +
        '<td>' + (d.stormwall_ip || '—') + '</td>' +
        '<td>' + (d.server_ip || '—') + '</td>' +
        '<td class="text-muted">' + d.created_at + '</td>' +
        '<td><div class="d-flex flex-column gap-1">' + actions + '</div></td></tr>';
}

function switchTargetsJs(mode) {
    var all = [
        { mode: 'cf',      label: '☁️ CF Proxied — Cloudflare принимает трафик → бэкенд' },
        { mode: 'dns',     label: '🔀 DNS + SW — CF DNS → StormWall → бэкенд' },
        { mode: 'sw_cf',   label: '⚡ SW → CF → бэкенд' },
        { mode: 'cf_only', label: '🔒 CF Only (failover)' },
        { mode: 'sw_only', label: '🛡️ SW Only (failover)' },
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
                    existing.cells[2].innerHTML = statusBadge(d.status);
                    existing.cells[3].innerHTML = nsCells(d.id, d.cloudflare_nameservers);
                    existing.cells[4].textContent = d.stormwall_ip || '—';
                    existing.dataset.serverIp    = d.server_ip    || '';
                    existing.dataset.stormwallIp = d.stormwall_ip || '';
                    existing.dataset.cfProxyIp   = d.cf_proxy_ip  || '';
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
            row.dataset.cfProxyIp,
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

// ─── SW→CF modal ───────────────────────────────────────────────────────────
function openSwCfModal(action, currentCfProxyIp) {
    document.getElementById('swCfForm').action = action;
    var input = document.getElementById('swCfProxyIpInput');
    var hint  = document.getElementById('swCfProxyIpHint');
    input.value = currentCfProxyIp || '';
    if (currentCfProxyIp) {
        hint.innerHTML = '✅ IP определён автоматически при настройке. Можно изменить.';
        hint.className = 'form-text text-success';
        input.required = false;
    } else {
        hint.innerHTML = 'Будет определён автоматически через DNS запрос к CF nameservers. Или введите вручную.';
        hint.className = 'form-text';
        input.required = false;
    }
    var modal = new bootstrap.Modal(document.getElementById('swCfModal'));
    modal.show();
    setTimeout(function() { input.focus(); }, 300);
}
</script>

{{-- Modal: SW→CF mode requires CF proxy IP --}}
<div class="modal fade" id="swCfModal" tabindex="-1" aria-labelledby="swCfModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="swCfModalLabel">⚡ Переключить в режим SW → CF</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="swCfForm" method="POST">
                @csrf
                <input type="hidden" name="mode" value="sw_cf">
                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        В этом режиме StormWall принимает трафик и передаёт его на Cloudflare proxy IP.
                        CF в свою очередь проксирует запросы на бэкенд.
                    </p>
                    <div class="mb-3">
                        <label for="swCfProxyIpInput" class="form-label fw-bold">
                            CF Proxy IP
                        </label>
                        <input type="text"
                               class="form-control"
                               id="swCfProxyIpInput"
                               name="cf_proxy_ip"
                               placeholder="оставьте пустым — определится автоматически"
                               list="cfProxyIpsList">
                        <datalist id="cfProxyIpsList">
                            @foreach($cfProxyIps as $ip)
                                <option value="{{ $ip }}">
                            @endforeach
                        </datalist>
                        <div id="swCfProxyIpHint" class="form-text">
                            Будет определён автоматически через DNS запрос к CF nameservers. Или введите вручную.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Переключить</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
