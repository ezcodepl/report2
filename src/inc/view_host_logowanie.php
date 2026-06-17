<!-- ======================================================== -->
<!-- WIDOK: DASHBOARD LOGOWAŃ WEDŁUG HOSTÓW ŹRÓDŁOWYCH        -->
<!-- ======================================================== -->

<?php
if (!function_exists('hlg_norm')) {
    function hlg_norm($value, $fallback = '-') {
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xC2\xA0", '&nbsp;', 'Â '], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        $value = preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $value);
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('hlg_add_stat')) {
    function hlg_add_stat(&$bucket, $label, $count) {
        $label = hlg_norm($label, '-');
        if ($label === '-') return;
        $bucket[$label] = ($bucket[$label] ?? 0) + max(1, (int)$count);
    }
}

if (!function_exists('hlg_build_hourly')) {
    function hlg_build_hourly($record) {
        $hours = array_fill(0, 24, 0);
        if (!empty($record['hourly_stats']) && is_array($record['hourly_stats'])) {
            foreach ($record['hourly_stats'] as $hour => $count) {
                $hour = (int)$hour;
                if ($hour >= 0 && $hour <= 23) $hours[$hour] += (int)$count;
            }
            return $hours;
        }
        $raw = (string)($record['time_generated'] ?? '');
        if (preg_match_all('/\d{4}-\d{2}-\d{2}\s+(\d{2}):\d{2}:\d{2}(?:\s*\(([\d\s,]+)\))?/u', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $hour = (int)$m[1];
                $count = isset($m[2]) && trim($m[2]) !== '' ? (int)str_replace([' ', ','], '', $m[2]) : 1;
                if ($hour >= 0 && $hour <= 23) $hours[$hour] += max(1, $count);
            }
        }
        return $hours;
    }
}

if (!function_exists('hlg_merge_hourly')) {
    function hlg_merge_hourly(&$target, $source) {
        for ($i = 0; $i < 24; $i++) $target[$i] += (int)($source[$i] ?? 0);
    }
}

if (!function_exists('hlg_render_bar_list')) {
    function hlg_render_bar_list($items, $accent = 'indigo', $unit = 'prób') {
        arsort($items);
        $items = array_slice($items, 0, 5, true);
        $max = !empty($items) ? max($items) : 1;
        $bar = $accent === 'sky' ? 'bg-sky-500' : ($accent === 'rose' ? 'bg-rose-500' : 'bg-indigo-600');
        $badge = $accent === 'sky' ? 'bg-sky-50 text-sky-700 border-sky-100' : ($accent === 'rose' ? 'bg-rose-50 text-rose-700 border-rose-100' : 'bg-indigo-50 text-indigo-700 border-indigo-100');
        if (empty($items)) {
            echo '<p class="py-4 text-center text-xs font-semibold text-slate-400">Brak danych</p>';
            return;
        }
        $idx = 1;
        foreach ($items as $label => $count) {
            $pct = min(100, round(((int)$count / $max) * 100));
            ?>
            <div class="space-y-1">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span class="flex min-w-0 items-center gap-2 font-semibold text-slate-700">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded border border-slate-200 bg-slate-100 text-[10px] font-bold text-slate-500"><?php echo $idx++; ?></span>
                        <span class="truncate" title="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></span>
                    </span>
                    <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-extrabold <?php echo $badge; ?>"><?php echo number_format((int)$count, 0, ',', ' '); ?> <?php echo $unit; ?></span>
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div class="h-full rounded-full <?php echo $bar; ?>" style="width: <?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php
        }
    }
}

if (!function_exists('hlg_status_badge')) {
    function hlg_status_badge($subType) {
        $st = strtolower((string)$subType);
        if (preg_match('/success|ok|allow/i', $st)) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (preg_match('/lock|blocked|suspicious/i', $st)) return 'bg-amber-50 text-amber-700 border-amber-200';
        return 'bg-rose-50 text-rose-700 border-rose-200';
    }
}

$records = $parsedData['records'] ?? [];
$hostCounts = [];
$userCounts = [];
$ipCounts = [];
$serviceCounts = [];
$subTypeCounts = [];
$globalHourly = array_fill(0, 24, 0);
$uniqueHosts = [];
$uniqueUsers = [];
$uniqueIps = [];
$totalEvents = 0;
$failedEvents = 0;
$hostHourly = [];
$hostMeta = [];

foreach ($records as $record) {
    $events = max(1, (int)($record['events_count'] ?? 1));
    $host = hlg_norm($record['source_host'] ?? '-');
    $user = hlg_norm($record['user'] ?? '-');
    $ip = hlg_norm($record['source_ip'] ?? '-');
    $service = hlg_norm($record['service_name'] ?? '-');
    $subType = hlg_norm($record['sub_type'] ?? '-');
    $hours = hlg_build_hourly($record);

    $totalEvents += $events;
    if (preg_match('/fail|error|deny|lock|bad|invalid|wrong/i', $subType)) $failedEvents += $events;
    hlg_add_stat($hostCounts, $host, $events);
    hlg_add_stat($userCounts, $user, $events);
    hlg_add_stat($ipCounts, $ip, $events);
    hlg_add_stat($serviceCounts, $service, $events);
    hlg_add_stat($subTypeCounts, $subType, $events);
    hlg_merge_hourly($globalHourly, $hours);

    if ($host !== '-') {
        $uniqueHosts[$host] = true;
        if (!isset($hostHourly[$host])) $hostHourly[$host] = array_fill(0, 24, 0);
        hlg_merge_hourly($hostHourly[$host], $hours);
        if (!isset($hostMeta[$host])) {
            $hostMeta[$host] = [
                'source_host' => $host,
                'source_ip' => $ip,
                'user' => $user,
                'dest_ip' => hlg_norm($record['dest_ip'] ?? '-'),
                'dest_host' => hlg_norm($record['dest_host'] ?? '-'),
                'service_name' => $service,
                'description' => hlg_norm($record['description'] ?? '-'),
                'events_count' => 0
            ];
        }
        $hostMeta[$host]['events_count'] += $events;
    }
    if ($user !== '-') $uniqueUsers[$user] = true;
    if ($ip !== '-') $uniqueIps[$ip] = true;
}

$peakHour = array_search(max($globalHourly), $globalHourly, true);
$peakHourText = sprintf('%02d:00', $peakHour === false ? 0 : $peakHour);
$recordsCount = count($records);

$modalHosts = [];
foreach ($hostMeta as $host => $meta) {
    $meta['hourly'] = $hostHourly[$host] ?? array_fill(0, 24, 0);
    $modalHosts[$host] = $meta;
}
?>

<div class="min-h-screen bg-slate-50 pb-10 text-slate-800">
    <div class="mx-auto max-w-[1600px] px-4 pt-6 sm:px-6 lg:px-8">

        

        <section class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="rounded-xl bg-sky-50 p-3 text-sky-600"><i data-lucide="server" class="h-6 w-6"></i></div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Wszystkie zdarzenia</p><p class="mt-0.5 text-2xl font-bold text-slate-900"><?php echo number_format($totalEvents, 0, ',', ' '); ?></p></div>
            </div>
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="rounded-xl bg-indigo-50 p-3 text-indigo-600"><i data-lucide="monitor" class="h-6 w-6"></i></div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Unikalne hosty</p><p class="mt-0.5 text-2xl font-bold text-slate-900"><?php echo number_format(count($uniqueHosts), 0, ',', ' '); ?></p></div>
            </div>
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="rounded-xl bg-rose-50 p-3 text-rose-600"><i data-lucide="alert-triangle" class="h-6 w-6"></i></div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Błędne logowania</p><p class="mt-0.5 text-2xl font-bold text-slate-900"><?php echo number_format($failedEvents ?: $totalEvents, 0, ',', ' '); ?></p></div>
            </div>
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="rounded-xl bg-amber-50 p-3 text-amber-600"><i data-lucide="clock" class="h-6 w-6"></i></div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Szczyt godzinowy</p><p class="mt-0.5 text-2xl font-bold text-slate-900"><?php echo $peakHourText; ?></p></div>
            </div>
        </section>

        <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight text-slate-900">Top 5: Hosty Źródłowe</h2>
                        <p class="text-xs text-slate-500">Największa liczba prób logowania według Source.HostName</p>
                    </div>
                    <span class="rounded-full border border-sky-100 bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">Source.HostName</span>
                </div>
                <div class="space-y-4"><?php hlg_render_bar_list($hostCounts, 'sky', 'prób'); ?></div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight text-slate-900">Top 5: Użytkownicy</h2>
                        <p class="text-xs text-slate-500">Konta z największą liczbą prób logowania</p>
                    </div>
                    <span class="rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">Source.UserName</span>
                </div>
                <div class="space-y-4"><?php hlg_render_bar_list($userCounts, 'indigo', 'prób'); ?></div>
            </div>
        </div>

        <div class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-lg font-bold tracking-tight text-slate-900">Profil Godzinowy Hostów</h2>
                    <p class="text-xs text-slate-500">Siatka 24-godzinna aktywności. Zdarzenia po 16:00 są oznaczone na czerwono.</p>
                </div>
                <span class="rounded-full border border-violet-100 bg-violet-50 px-2.5 py-1 text-xs font-bold text-violet-700">Zdarzenia / godz.</span>
            </div>
            <div class="grid grid-cols-4 gap-3 sm:grid-cols-6 xl:grid-cols-12">
                <?php $maxHour = max($globalHourly) ?: 1; foreach ($globalHourly as $hour => $count):
                    $ratio = $count / $maxHour;
                    $afterHours = $hour >= 16 && $count > 0;
                    if ($count <= 0) {
                        $bg = 'background-color:#f1f5f9;'; $text = 'text-slate-500'; $sub = 'text-slate-400';
                    } else {
                        $base = $afterHours ? '220, 38, 38' : '79, 70, 229';
                        $opacity = max(0.16, min(0.95, 0.16 + ($ratio * 0.79)));
                        $bg = 'background-color: rgba(' . $base . ', ' . number_format($opacity, 2, '.', '') . ');';
                        $text = $ratio > 0.55 ? 'text-white' : ($afterHours ? 'text-red-900' : 'text-indigo-900');
                        $sub = $ratio > 0.55 ? ($afterHours ? 'text-red-50' : 'text-indigo-50') : 'text-slate-500';
                    }
                ?>
                    <div class="rounded-xl border border-slate-200/60 p-3 shadow-sm transition hover:scale-[1.03]" style="<?php echo $bg; ?>" title="<?php echo sprintf('%02d:00', $hour); ?> — <?php echo number_format((int)$count, 0, ',', ' '); ?> zdarzeń">
                        <div class="text-[10px] font-black uppercase <?php echo $sub; ?>"><?php echo sprintf('%02d:00', $hour); ?></div>
                        <div class="mt-1 text-lg font-extrabold <?php echo $text; ?>"><?php echo number_format((int)$count, 0, ',', ' '); ?></div>
                        <div class="text-[9px] font-semibold <?php echo $sub; ?>"><?php echo $afterHours ? 'po 16:00' : 'zdarzeń'; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col justify-between gap-4 border-b border-slate-200 bg-white p-6 sm:flex-row sm:items-center">
                <div>
                    <div class="flex items-center gap-3">
                        <h2 class="text-lg font-bold tracking-tight text-slate-900">Dziennik Zdarzeń — Hosty Logowania</h2>
                        <span id="hostBadgeCount" class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">Top 10</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">Kliknij „Szczegóły”, aby pokazać rozkład godzinowy wybranego hosta.</p>
                </div>
                <div class="flex w-full items-center gap-3 sm:w-auto">
                    <input type="text" id="hostTableSearch" placeholder="Filtruj tabelę (Host, User, IP)..." class="w-full rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-800 placeholder-slate-400 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500 sm:w-72">
                    <button onclick="hlgToggleAllRows()" id="hostBtnShowAll" class="whitespace-nowrap rounded-lg bg-sky-600 px-4 py-2 text-xs font-bold uppercase tracking-wide text-white shadow-md shadow-sky-600/10 transition-all hover:bg-sky-700">Pokaż wszystkie</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left" id="hostLogsTable">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <th class="px-6 py-4">Source.HostName</th>
                            <th class="px-6 py-4">Próby logowania</th>
                            <th class="px-6 py-4">Source.UserName</th>
                            <th class="px-6 py-4">Source.IP</th>
                            <th class="px-6 py-4">EventMap.SubType</th>
                            <th class="px-6 py-4">Time.Generated</th>
                            <th class="px-6 py-4">Service.Name</th>
                            <th class="px-6 py-4 text-right">Akcja</th>
                        </tr>
                    </thead>
                    <tbody id="hostTableBody" class="divide-y divide-slate-100 text-sm text-slate-700">
                    <?php if (empty($records)): ?>
                        <tr><td colspan="8" class="px-6 py-12 text-center text-slate-400">Brak danych w raporcie.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $idx => $record):
                            $host = hlg_norm($record['source_host'] ?? '-');
                            $user = hlg_norm($record['user'] ?? '-');
                            $ip = hlg_norm($record['source_ip'] ?? '-');
                            $subType = hlg_norm($record['sub_type'] ?? '-');
                            $time = hlg_norm($record['time_generated'] ?? '-', '-');
                            $service = hlg_norm($record['service_name'] ?? '-');
                            $events = max(1, (int)($record['events_count'] ?? 1));
                            $badgeClass = hlg_status_badge($subType);
                        ?>
                        <tr class="host-log-row cursor-pointer border-b border-slate-100 transition-colors hover:bg-slate-50" data-index="<?php echo $idx; ?>" data-search="<?php echo htmlspecialchars(strtolower($host . ' ' . $user . ' ' . $ip . ' ' . $subType . ' ' . $service)); ?>">
                            <td class="whitespace-nowrap px-6 py-4 font-semibold text-slate-900"><?php echo htmlspecialchars($host); ?></td>
                            <td class="px-6 py-4 font-mono text-sm font-extrabold text-sky-700"><?php echo number_format($events, 0, ',', ' '); ?></td>
                            <td class="whitespace-nowrap px-6 py-4 font-medium text-slate-700"><?php echo htmlspecialchars($user); ?></td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-500"><?php echo htmlspecialchars($ip); ?></td>
                            <td class="px-6 py-4"><span class="rounded-full border px-2.5 py-1 text-xs font-bold uppercase tracking-wide <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($subType); ?></span></td>
                            <td class="px-6 py-4 font-mono text-xs text-slate-500 max-w-[260px] truncate" title="<?php echo htmlspecialchars($record['time_generated'] ?? ''); ?>"><?php echo htmlspecialchars($time); ?></td>
                            <td class="px-6 py-4 text-slate-600"><span class="rounded border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs text-slate-700"><?php echo htmlspecialchars($service); ?></span></td>
                            <td class="px-6 py-4 text-right"><button onclick="hlgShowDetails('<?php echo htmlspecialchars(base64_encode($host)); ?>')" class="rounded-lg border border-sky-100 bg-sky-50 px-3 py-1.5 text-xs font-bold text-sky-700 transition-all hover:bg-sky-600 hover:text-white">Szczegóły</button></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col items-center justify-between gap-4 border-t border-slate-100 bg-slate-50/50 p-4 text-xs text-slate-500 sm:flex-row">
                <span id="hostShowingLogsCount">Pokazujesz 0 z 0 rekordów</span>
                <div class="flex gap-2">
                    <span class="rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-bold uppercase text-emerald-600 shadow-sm">Success</span>
                    <span class="rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-bold uppercase text-rose-600 shadow-sm">Failure / Error</span>
                    <span class="rounded border border-slate-200 bg-white px-2 py-1 text-[10px] font-bold uppercase text-amber-600 shadow-sm">Po 16:00 = czerwony</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="hostModal" class="fixed inset-0 z-50 hidden justify-end bg-slate-900/40 backdrop-blur-sm">
    <div class="flex h-full w-full max-w-2xl flex-col overflow-y-auto border-l border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 p-6">
            <div><h3 id="hostModalTitle" class="text-xl font-bold text-slate-950">Analiza hosta</h3><p class="mt-1 text-xs text-slate-500">Rozkład godzinowy prób logowania dla wybranego Source.HostName</p></div>
            <button onclick="hlgCloseModal()" class="rounded-lg p-2 text-slate-400 transition-colors hover:bg-slate-200 hover:text-slate-700"><i data-lucide="x" class="h-6 w-6"></i></button>
        </div>
        <div class="flex-1 space-y-6 p-6">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <h4 class="mb-4 text-xs font-bold uppercase tracking-wider text-slate-500">Metadane hosta</h4>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div><span class="block text-xs text-slate-400">Source.IP</span><span id="hostDetailSourceIp" class="font-mono text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Główny użytkownik</span><span id="hostDetailUser" class="font-semibold text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Destination.IP</span><span id="hostDetailDestIp" class="font-mono text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Service.Name</span><span id="hostDetailService" class="font-semibold text-sky-700">-</span></div>
                    <div><span class="block text-xs text-slate-400">Próby logowania</span><span id="hostDetailAttempts" class="font-bold text-slate-900">-</span></div>
                    <div><span class="block text-xs text-slate-400">Destination.HostName</span><span id="hostDetailDestHost" class="font-semibold text-slate-800">-</span></div>
                </div>
                <div class="mt-4 border-t border-slate-200 pt-3"><span class="mb-1 block text-xs text-slate-400">EventSource.Description</span><p id="hostDetailDesc" class="rounded-lg border border-slate-200 bg-white p-3 font-mono text-xs leading-relaxed text-slate-700">-</p></div>
            </div>
            <div>
                <div class="mb-4 flex items-center justify-between"><h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Rozkład godzinowy hosta</h4><span class="rounded border border-rose-100 bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-700">Po 16:00 na czerwono</span></div>
                <div id="hostUserHourGrid" class="grid grid-cols-4 gap-3 sm:grid-cols-6"></div>
            </div>
        </div>
        <div class="border-t border-slate-200 bg-slate-50 p-6"><button onclick="hlgCloseModal()" class="w-full rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white shadow-md transition hover:bg-slate-800">Zamknij panel szczegółów</button></div>
    </div>
</div>

<script>
const HLG_HOSTS = <?php echo json_encode($modalHosts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let hlgShowAll = false;

function hlgDecodeBase64(value) {
    try { return decodeURIComponent(escape(window.atob(value))); } catch (e) { return window.atob(value); }
}

function hlgRenderRows() {
    const search = (document.getElementById('hostTableSearch')?.value || '').toLowerCase().trim();
    const rows = Array.from(document.querySelectorAll('.host-log-row'));
    const matched = rows.filter(row => !search || (row.dataset.search || '').includes(search));
    let shown = 0;
    rows.forEach(row => {
        const isMatch = matched.includes(row);
        const shouldShow = isMatch && (hlgShowAll || shown < 10);
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) shown++;
    });
    const countEl = document.getElementById('hostShowingLogsCount');
    if (countEl) countEl.textContent = `Pokazujesz ${shown.toLocaleString('pl-PL')} z ${matched.length.toLocaleString('pl-PL')} rekordów`;
    const badge = document.getElementById('hostBadgeCount');
    if (badge) badge.textContent = hlgShowAll ? 'Wszystkie' : 'Top 10';
    const btn = document.getElementById('hostBtnShowAll');
    if (btn) btn.textContent = hlgShowAll ? 'Pokaż mniej (10)' : `Pokaż wszystkie (${matched.length.toLocaleString('pl-PL')})`;
}

function hlgToggleAllRows() { hlgShowAll = !hlgShowAll; hlgRenderRows(); }

document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('hostTableSearch');
    if (search) search.addEventListener('input', () => { hlgShowAll = false; hlgRenderRows(); });
    hlgRenderRows();
});

function hlgShowDetails(encodedHost) {
    const host = hlgDecodeBase64(encodedHost);
    const data = HLG_HOSTS[host];
    if (!data) return;
    document.body.style.overflow = 'hidden';
    document.getElementById('hostModal').classList.remove('hidden');
    document.getElementById('hostModal').classList.add('flex');
    document.getElementById('hostModalTitle').textContent = `Profil hosta: ${host}`;
    document.getElementById('hostDetailSourceIp').textContent = data.source_ip || '-';
    document.getElementById('hostDetailUser').textContent = data.user || '-';
    document.getElementById('hostDetailDestIp').textContent = data.dest_ip || '-';
    document.getElementById('hostDetailDestHost').textContent = data.dest_host || '-';
    document.getElementById('hostDetailService').textContent = data.service_name || '-';
    document.getElementById('hostDetailAttempts').textContent = (data.events_count || 0).toLocaleString('pl-PL');
    document.getElementById('hostDetailDesc').textContent = data.description || '-';
    hlgRenderHostHours(data.hourly || []);
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
}

function hlgRenderHostHours(hours) {
    const grid = document.getElementById('hostUserHourGrid');
    grid.innerHTML = '';
    const max = Math.max(...hours, 1);
    for (let h = 0; h < 24; h++) {
        const count = Number(hours[h] || 0);
        const ratio = count / max;
        const afterHours = h >= 16 && count > 0;
        let bg = '#f8fafc';
        let text = 'text-slate-500';
        let sub = 'text-slate-400';
        if (count > 0) {
            const color = afterHours ? '220, 38, 38' : '2, 132, 199';
            const opacity = Math.max(0.16, Math.min(0.95, 0.16 + (ratio * 0.79)));
            bg = `rgba(${color}, ${opacity.toFixed(2)})`;
            text = ratio > 0.55 ? 'text-white' : (afterHours ? 'text-red-900' : 'text-sky-900');
            sub = ratio > 0.55 ? 'text-white/80' : 'text-slate-500';
        }
        grid.innerHTML += `<div class="rounded-xl border border-slate-200 p-2.5 shadow-sm" style="background-color:${bg}"><div class="text-[10px] font-black ${sub}">${String(h).padStart(2,'0')}:00</div><div class="mt-1 text-sm font-extrabold ${text}">${count.toLocaleString('pl-PL')}</div></div>`;
    }
}

function hlgCloseModal() {
    document.body.style.overflow = '';
    document.getElementById('hostModal').classList.add('hidden');
    document.getElementById('hostModal').classList.remove('flex');
}
</script>
