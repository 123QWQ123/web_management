@extends('layouts.app')

@section('content')
<style>
.tooltip-wide .tooltip-inner {
    max-width: 420px;
    text-align: left;
    white-space: nowrap;
}
</style>
<div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
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
                <span id="live-indicator" style="transition:opacity 0.25s">
                    <span id="live-dot" class="text-success">&#9679;</span>
                    Прямой эфир &mdash; <span id="live-clock" style="transition:opacity 0.25s; display:inline-block; width:6ch; text-align:right">—</span>
                </span>
            </span>
            <a href="{{ route('admin.domains.create') }}" class="btn btn-primary">+ Добавить домен</a>
        </div>
    </div>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div id="domains-empty" class="{{ $domains->isEmpty() ? '' : 'd-none' }}">
        <div class="alert alert-secondary">Доменов пока нет.</div>
    </div>

    <table class="table table-bordered table-hover align-middle small {{ $domains->isEmpty() ? 'd-none' : '' }}" id="domains-table">
        <thead class="table-dark">
            <tr>
                <th>Домен</th>
                <th>Режим</th>
                <th>Статус</th>
                <th>Cloudflare NS <span class="text-warning">(&#8594; регистратор)</span></th>
                <th>SW Proxy IP</th>
                <th>IP сервера</th>
                <th>Добавлен</th>
                <th style="width:100px"></th>
            </tr>
        </thead>
        <tbody id="domains-tbody">
            @foreach($domains as $domain)
            @php $ns = $domain->cloudflare_nameservers ?? []; @endphp
            <tr data-id="{{ $domain->id }}">
                <td><strong>{{ $domain->domain }}</strong></td>
                <td>
                    @if($domain->mode === 'cf')
                        <span class="badge bg-warning text-dark">Proxied</span>
                    @else
                        <span class="badge bg-info text-dark">DNS Only</span>
                    @endif
                </td>
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
                    @forelse($ns as $i => $server)
                        <div class="d-flex align-items-center gap-1 mb-1">
                            <code class="flex-grow-1" id="ns-{{ $domain->id }}-{{ $i }}">{{ $server }}</code>
                            <button type="button"
                                    class="btn btn-sm btn-outline-secondary py-0 px-1"
                                    onclick="copyNs('ns-{{ $domain->id }}-{{ $i }}', this)">&#128203;</button>
                        </div>
                    @empty
                        <span class="text-muted">—</span>
                    @endforelse
                    @if(!empty($ns))
                        <div class="mt-1"><span class="badge bg-warning text-dark">Прописать у регистратора</span></div>
                    @endif
                </td>
                <td>{{ $domain->stormwall_ip ?? '—' }}</td>
                <td>{{ $domain->server_ip ?? '—' }}</td>
                <td class="text-muted">{{ $domain->created_at->format('d.m.Y H:i') }}</td>
                <td>
                    <form action="{{ route('admin.domains.destroy', $domain) }}" method="POST"
                          onsubmit="return confirm('Удалить {{ $domain->domain }} из Cloudflare, StormWall и базы данных?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-danger w-100">Удалить</button>
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

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
    return mode === 'cf'
        ? '<span class="badge bg-warning text-dark">Proxied</span>'
        : '<span class="badge bg-info text-dark">DNS Only</span>';
}

function nsCells(domainId, nameservers) {
    if (!nameservers || nameservers.length === 0) return '<span class="text-muted">—</span>';
    var html = '';
    nameservers.forEach(function(ns, i) {
        var id = 'ns-' + domainId + '-' + i;
        html += '<div class="d-flex align-items-center gap-1 mb-1">' +
            '<code class="flex-grow-1" id="' + id + '">' + ns + '</code>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"' +
            ' onclick="copyNs(\'' + id + '\', this)">&#128203;</button></div>';
    });
    html += '<div class="mt-1"><span class="badge bg-warning text-dark">Прописать у регистратора</span></div>';
    return html;
}

function buildRow(d) {
    return '<tr data-id="' + d.id + '">' +
        '<td><strong>' + d.domain + '</strong></td>' +
        '<td>' + modeBadge(d.mode) + '</td>' +
        '<td>' + statusBadge(d.status) + '</td>' +
        '<td>' + nsCells(d.id, d.cloudflare_nameservers) + '</td>' +
        '<td>' + (d.stormwall_ip || '—') + '</td>' +
        '<td>' + (d.server_ip || '—') + '</td>' +
        '<td class="text-muted">' + d.created_at + '</td>' +
        '<td><form action="' + DELETE_BASE + '/' + d.id + '" method="POST"' +
        ' onsubmit="return confirm(\'Удалить ' + d.domain + '?\')">' +
        '<input type="hidden" name="_token" value="' + CSRF + '">' +
        '<input type="hidden" name="_method" value="DELETE">' +
        '<button type="submit" class="btn btn-sm btn-danger w-100">Удалить</button>' +
        '</form></td></tr>';
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
                    existing.cells[2].innerHTML = statusBadge(d.status);
                    existing.cells[3].innerHTML = nsCells(d.id, d.cloudflare_nameservers);
                    existing.cells[4].textContent = d.stormwall_ip || '—';
                } else {
                    var tmp = document.createElement('tbody');
                    tmp.innerHTML = buildRow(d);
                    tbody.insertBefore(tmp.firstChild, tbody.children[idx] || null);
                }
            });

            // Blink only the clock on refresh
            var clock = document.getElementById('live-clock');
            clock.style.opacity = '0.25';
            setTimeout(function() { clock.style.opacity = ''; }, 250);

            document.getElementById('live-dot').className = 'text-success';
        })
        .catch(function() {
            document.getElementById('live-dot').className = 'text-warning';
        });
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
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 1500);
    });
}
</script>
@endsection
