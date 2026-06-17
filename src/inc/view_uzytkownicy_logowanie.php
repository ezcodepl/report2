<!-- ======================================================== -->
<!-- DASHBOARD: BŁĘDNE PRÓBY LOGOWANIA UŻYTKOWNIKÓW          -->
<!-- GUI: Command Center / kafelki / TOP5 / tabela TOP10     -->
<!-- ======================================================== -->

<?php
$records = $parsedData['records'] ?? [];

if (!function_exists('ulg_norm')) {
    function ulg_norm($value, $fallback = '-') {
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xC2\xA0", '&nbsp;', 'Â '], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        $value = preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $value);
        return $value !== '' ? trim($value) : $fallback;
    }
}

if (!function_exists('ulg_add_stat')) {
    function ulg_add_stat(&$bucket, $label, $count) {
        $label = ulg_norm($label, '-');
        if ($label === '-') return;
        $bucket[$label] = ($bucket[$label] ?? 0) + max(1, (int)$count);
    }
}

if (!function_exists('ulg_build_hourly')) {
    function ulg_build_hourly($record) {
        $hours = array_fill(0, 24, 0);
        if (!empty($record['hourly_stats']) && is_array($record['hourly_stats'])) {
            foreach ($record['hourly_stats'] as $h => $c) {
                $h = (int)$h;
                if ($h >= 0 && $h <= 23) $hours[$h] += (int)$c;
            }
        } else {
            $raw = (string)($record['time_generated'] ?? '');
            if (preg_match_all('/\d{4}-\d{2}-\d{2}\s+(\d{2}):\d{2}:\d{2}(?:\s*\(([\d\s,]+)\))?/u', $raw, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $h = (int)$m[1];
                    $count = isset($m[2]) && trim($m[2]) !== '' ? (int)str_replace([' ', ','], '', $m[2]) : 1;
                    if ($h >= 0 && $h <= 23) $hours[$h] += max(1, $count);
                }
            }
        }
        return $hours;
    }
}

if (!function_exists('ulg_merge_hourly')) {
    function ulg_merge_hourly(&$target, $source) {
        for ($i = 0; $i < 24; $i++) $target[$i] += (int)($source[$i] ?? 0);
    }
}

if (!function_exists('ulg_render_bar_list')) {
    function ulg_render_bar_list($items, $accent = 'indigo', $unit = 'prób') {
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

$userCounts = [];
$hostCounts = [];
$ipCounts = [];
$serviceCounts = [];
$subTypeCounts = [];
$globalHourly = array_fill(0, 24, 0);
$uniqueUsers = [];
$uniqueHosts = [];
$uniqueIps = [];
$totalEvents = 0;
$failedEvents = 0;
$userHourly = [];
$userMeta = [];

foreach ($records as $record) {
    $events = max(1, (int)($record['events_count'] ?? 1));
    $user = ulg_norm($record['user'] ?? '-');
    $host = ulg_norm($record['source_host'] ?? '-');
    $ip = ulg_norm($record['source_ip'] ?? '-');
    $service = ulg_norm($record['service_name'] ?? '-');
    $subType = ulg_norm($record['sub_type'] ?? '-');
    $hours = ulg_build_hourly($record);

    $totalEvents += $events;
    if (preg_match('/fail|error|deny|lock|bad|invalid|wrong/i', $subType)) $failedEvents += $events;
    ulg_add_stat($userCounts, $user, $events);
    ulg_add_stat($hostCounts, $host, $events);
    ulg_add_stat($ipCounts, $ip, $events);
    ulg_add_stat($serviceCounts, $service, $events);
    ulg_add_stat($subTypeCounts, $subType, $events);
    ulg_merge_hourly($globalHourly, $hours);

    if ($user !== '-') {
        $uniqueUsers[$user] = true;
        if (!isset($userHourly[$user])) $userHourly[$user] = array_fill(0, 24, 0);
        ulg_merge_hourly($userHourly[$user], $hours);
        if (!isset($userMeta[$user])) {
            $userMeta[$user] = [
                'user' => $user,
                'source_ip' => $ip,
                'source_host' => $host,
                'dest_ip' => ulg_norm($record['dest_ip'] ?? '-'),
                'dest_host' => ulg_norm($record['dest_host'] ?? '-'),
                'service_name' => $service,
                'description' => ulg_norm($record['description'] ?? '-'),
                'events_count' => 0
            ];
        }
        $userMeta[$user]['events_count'] += $events;
    }
    if ($host !== '-') $uniqueHosts[$host] = true;
    if ($ip !== '-') $uniqueIps[$ip] = true;
}

$peakHour = array_search(max($globalHourly), $globalHourly, true);
$peakHourText = sprintf('%02d:00', $peakHour === false ? 0 : $peakHour);
$recordsCount = count($records);

$modalUsers = [];
foreach ($userMeta as $user => $meta) {
    $meta['hourly'] = $userHourly[$user] ?? array_fill(0, 24, 0);
    $modalUsers[$user] = $meta;
}
?>

<div class="min-h-screen bg-slate-50 pb-10 text-slate-800">
    <div class="mx-auto max-w-[1600px] px-4 pt-6 sm:px-6 lg:px-8">

        

        <section class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="rounded-xl bg-indigo-50 p-3 text-indigo-600"><i data-lucide="file-text" class="h-6 w-6"></i></div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Wszystkie zdarzenia</p><p class="mt-0.5 text-2xl font-bold text-slate-900"><?php echo number_format($totalEvents, 0, ',', ' '); ?></p></div>
            </div>
            <div class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="rounded-xl bg-emerald-50 p-3 text-emerald-600"><i data-lucide="users" class="h-6 w-6"></i></div>
                <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Unikalni użytkownicy</p><p class="mt-0.5 text-2xl font-bold text-slate-900"><?php echo number_format(count($uniqueUsers), 0, ',', ' '); ?></p></div>
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

        <!-- TOP 5: wykresy obok siebie -->
        <div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight text-slate-900">Top 5: Aktywni Użytkownicy</h2>
                        <p class="text-xs text-slate-500">Największa liczba prób logowania według Source.UserName</p>
                    </div>
                    <span class="rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700">Source.UserName</span>
                </div>
                <div class="space-y-4"><?php ulg_render_bar_list($userCounts, 'indigo', 'prób'); ?></div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-6 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold tracking-tight text-slate-900">Top 5: Hosty Źródłowe</h2>
                        <p class="text-xs text-slate-500">Hosty generujące najwięcej zdarzeń według Source.HostName</p>
                    </div>
                    <span class="rounded-full border border-sky-100 bg-sky-50 px-2.5 py-1 text-xs font-bold text-sky-700">Source.HostName</span>
                </div>
                <div class="space-y-4"><?php ulg_render_bar_list($hostCounts, 'sky', 'prób'); ?></div>
            </div>
        </div>

        <!-- ROZKŁAD GODZINOWY POD WYKRESAMI -->
        <div class="mb-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h2 class="text-lg font-bold tracking-tight text-slate-900">Profil Godzinowy Incydentów</h2>
                    <p class="text-xs text-slate-500">Siatka 24-godzinna aktywności. Zdarzenia po 16:00 są oznaczone na czerwono.</p>
                </div>
                <span class="rounded-full border border-violet-100 bg-violet-50 px-2.5 py-1 text-xs font-bold text-violet-700">Zdarzenia / godz.</span>
            </div>

            <div class="mb-6 flex w-fit flex-wrap items-center gap-3 rounded-xl border border-slate-200/60 bg-slate-50 p-3 text-xs text-slate-600">
                <span class="font-medium text-slate-500">Intensywność:</span>
                <span class="h-3.5 w-3.5 rounded border border-slate-200 bg-slate-100"></span><span>0</span>
                <span class="h-3.5 w-3.5 rounded border border-indigo-100 bg-indigo-50"></span><span>Do 16:00</span>
                <span class="h-3.5 w-3.5 rounded border border-red-200 bg-red-100"></span><span>Po 16:00</span>
                <span class="h-3.5 w-3.5 rounded bg-red-600 shadow-[0_0_6px_rgba(220,38,38,0.3)]"></span><span>Po 16:00 wysoka</span>
            </div>

            <div class="grid grid-cols-4 gap-3 sm:grid-cols-6 lg:grid-cols-12">
                <?php $maxGlobal = max($globalHourly) ?: 1; for ($h = 0; $h < 24; $h++):
                    $count = (int)$globalHourly[$h];
                    $ratio = $count / $maxGlobal;
                    $pct = $totalEvents > 0 ? round(($count / $totalEvents) * 100, 1) : 0;
                    $afterHours = $h >= 16 && $count > 0;
                    $bg = 'bg-slate-100 border-slate-200 text-slate-500';
                    $sub = 'text-slate-400';

                    if ($count > 0) {
                        if ($afterHours) {
                            if ($ratio <= .3) { $bg = 'bg-red-50 border-red-100 text-red-700'; $sub = 'text-red-500'; }
                            elseif ($ratio <= .7) { $bg = 'bg-red-100 border-red-200 text-red-800'; $sub = 'text-red-600'; }
                            else { $bg = 'bg-red-600 border-red-600 text-white font-bold shadow-md shadow-red-600/20'; $sub = 'text-red-100'; }
                        } else {
                            if ($ratio <= .3) { $bg = 'bg-indigo-50 border-indigo-100 text-indigo-700'; $sub = 'text-indigo-500'; }
                            elseif ($ratio <= .7) { $bg = 'bg-indigo-100 border-indigo-200 text-indigo-800'; $sub = 'text-indigo-600'; }
                            else { $bg = 'bg-indigo-600 border-indigo-600 text-white font-bold shadow-md shadow-indigo-600/20'; $sub = 'text-indigo-100'; }
                        }
                    }
                ?>
                    <div class="flex h-[85px] flex-col justify-between rounded-xl border p-3 transition hover:scale-105 <?php echo $bg; ?>" title="<?php echo $afterHours ? 'Po 16:00 — ' : ''; ?>Udział: <?php echo $pct; ?>% wszystkich zdarzeń">
                        <span class="text-xs font-semibold uppercase tracking-wider opacity-75"><?php echo sprintf('%02d:00', $h); ?></span>
                        <div class="mt-1 flex items-baseline justify-between"><span class="text-lg font-black"><?php echo number_format($count, 0, ',', ' '); ?></span><span class="text-[10px] font-medium <?php echo $sub; ?>"><?php echo $pct; ?>%</span></div>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="mt-6 flex flex-col justify-between gap-2 border-t border-slate-100 pt-4 text-xs text-slate-500 sm:flex-row sm:items-center">
                <span>Wskazówka: godziny 16:00–23:00 z jakimikolwiek próbami logowania są wyróżnione kolorem czerwonym.</span>
                <span class="font-semibold text-indigo-600">Profil globalny</span>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col items-start justify-between gap-4 border-b border-slate-200 bg-white p-6 sm:flex-row sm:items-center">
                <div>
                    <div class="flex items-center gap-3"><h2 class="text-lg font-bold tracking-tight text-slate-900">Dziennik Zdarzeń</h2><span id="loginBadgeCount" class="rounded-full border border-slate-200 bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">Top 10</span></div>
                    <p class="mt-1 text-xs text-slate-500">Tabela pokazuje TOP 10 użytkowników; przycisk pokaże wszystkie rekordy i ich liczbę.</p>
                </div>
                <div class="flex w-full items-center gap-3 sm:w-auto">
                    <input type="text" id="loginTableSearch" onkeyup="loginRenderTable()" placeholder="Filtruj tabelę (User, IP, Host)..." class="w-full rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-800 placeholder-slate-400 transition-all focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 sm:w-72">
                    <button type="button" onclick="loginToggleAll()" id="loginBtnShowAll" class="whitespace-nowrap rounded-lg bg-indigo-600 px-4 py-2 text-xs font-bold uppercase tracking-wide text-white shadow-md shadow-indigo-600/10 transition hover:bg-indigo-700">Pokaż wszystkie</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left" id="loginLogsTable">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50 text-xs font-semibold uppercase tracking-wider text-slate-500">
                            <th class="px-6 py-4">Source.UserName</th><th class="px-6 py-4 text-center">Próby logowania</th><th class="px-6 py-4">Source.IP</th><th class="px-6 py-4">Source.HostName</th><th class="px-6 py-4">EventMap.SubType</th><th class="px-6 py-4">Time.Generated</th><th class="px-6 py-4">Service.Name</th><th class="px-6 py-4 text-right">Akcja</th>
                        </tr>
                    </thead>
                    <tbody id="loginTableBody" class="divide-y divide-slate-100 text-sm text-slate-700"></tbody>
                </table>
            </div>
            <div class="flex flex-col items-center justify-between gap-4 border-t border-slate-100 bg-slate-50/50 p-4 text-xs text-slate-500 sm:flex-row">
                <span id="loginShowingCount">Pokazuję 0 z 0 rekordów</span>
                <div class="flex gap-2"><span class="rounded border border-emerald-200 bg-white px-2 py-1 text-[10px] font-bold uppercase text-emerald-600 shadow-sm">Success</span><span class="rounded border border-rose-200 bg-white px-2 py-1 text-[10px] font-bold uppercase text-rose-600 shadow-sm">Failure / Log error</span><span class="rounded border border-amber-200 bg-white px-2 py-1 text-[10px] font-bold uppercase text-amber-600 shadow-sm">Lockout</span></div>
            </div>
        </div>
    </div>
</div>

<div id="loginUserModal" class="fixed inset-0 z-50 hidden justify-end bg-slate-900/40 backdrop-blur-sm">
    <div class="flex h-full w-full max-w-2xl flex-col overflow-y-auto border-l border-slate-200 bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 p-6">
            <div><h3 id="loginModalTitle" class="text-xl font-bold text-slate-950">Analiza Szczegółowa</h3><p class="mt-1 text-xs text-slate-500">Pełny profil aktywności wybranego użytkownika</p></div>
            <button type="button" onclick="loginCloseModal()" class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-200 hover:text-slate-700"><i data-lucide="x" class="h-6 w-6"></i></button>
        </div>
        <div class="flex-1 space-y-6 p-6">
            <div class="space-y-4 rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Metadane użytkownika i środowiska</h4>
                <div class="grid grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div><span class="block text-xs text-slate-400">Ostatnia Source.HostName</span><span id="ulgDetailHost" class="font-semibold text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Source.IP</span><span id="ulgDetailSourceIp" class="font-mono text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Destination.IP</span><span id="ulgDetailDestIp" class="font-mono text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Destination.HostName</span><span id="ulgDetailDestHost" class="font-semibold text-slate-800">-</span></div>
                    <div><span class="block text-xs text-slate-400">Service.Name</span><span id="ulgDetailService" class="font-semibold text-indigo-600">-</span></div>
                    <div><span class="block text-xs text-slate-400">Liczba prób</span><span id="ulgDetailAttempts" class="font-bold text-slate-900">-</span></div>
                </div>
                <div class="border-t border-slate-200 pt-3"><span class="mb-1 block text-xs text-slate-400">EventSource.Description</span><p id="ulgDetailDesc" class="overflow-x-auto rounded-lg border border-slate-200 bg-white p-3 font-mono text-xs leading-relaxed text-slate-700">-</p></div>
            </div>
            <div>
                <div class="mb-4 flex items-center justify-between"><h4 class="text-xs font-bold uppercase tracking-wider text-slate-500">Rozkład godzinowy</h4><span class="rounded border border-indigo-100 bg-indigo-50 px-2 py-0.5 text-[10px] text-indigo-700">Profil usera</span></div>
                <div id="ulgUserHourGrid" class="grid grid-cols-4 gap-3 sm:grid-cols-6"></div>
            </div>
        </div>
        <div class="border-t border-slate-200 bg-slate-50 p-6"><button type="button" onclick="loginCloseModal()" class="w-full rounded-xl bg-slate-900 py-3 text-sm font-semibold text-white shadow-md transition hover:bg-slate-800">Zamknij panel szczegółów</button></div>
    </div>
</div>

<script>
const ulgRecords = <?php echo json_encode(array_values($records), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const ulgUsers = <?php echo json_encode($modalUsers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let ulgShowAll = false;

function ulgEscape(v) { return String(v ?? '-').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c])); }
function ulgBadge(subType) {
    const v = String(subType || '').toLowerCase();
    if (v.includes('success')) return '<span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-emerald-700">Success</span>';
    if (v.includes('lock')) return '<span class="rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-amber-700">Lockout</span>';
    return '<span class="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-bold uppercase tracking-wide text-rose-700">Failure</span>';
}
function ulgHourlyHtml(hours) {
    const max = Math.max(...hours, 1);
    let html = '';
    for (let h = 0; h < 24; h++) {
        const count = Number(hours[h] || 0);
        const ratio = count / max;
        const afterHours = h >= 16 && count > 0;
        let cls = 'bg-slate-50 border-slate-200 text-slate-400';
        let label = 'text-slate-500';

        if (count > 0) {
            if (afterHours) {
                if (ratio <= .3) { cls = 'bg-red-50 border-red-100 text-red-700'; label = 'text-red-600'; }
                else if (ratio <= .7) { cls = 'bg-red-100 border-red-200 text-red-800'; label = 'text-red-700'; }
                else { cls = 'bg-red-600 border-red-600 text-white font-bold shadow-md shadow-red-600/20'; label = 'text-red-100'; }
            } else {
                if (ratio <= .3) { cls = 'bg-indigo-50 border-indigo-100 text-indigo-700'; label = 'text-indigo-600'; }
                else if (ratio <= .7) { cls = 'bg-indigo-100 border-indigo-200 text-indigo-800'; label = 'text-indigo-700'; }
                else { cls = 'bg-indigo-600 border-indigo-600 text-white font-bold'; label = 'text-indigo-100'; }
            }
        }

        const title = afterHours ? 'Po 16:00 — zdarzenia: ' + count.toLocaleString('pl-PL') : 'Zdarzenia: ' + count.toLocaleString('pl-PL');
        html += `<div class="flex h-[65px] flex-col justify-between rounded-xl border p-2.5 transition hover:scale-[1.03] ${cls}" title="${title}"><span class="text-[10px] font-bold uppercase ${label}">${String(h).padStart(2,'0')}:00</span><span class="mt-1 text-right text-sm font-extrabold">${count.toLocaleString('pl-PL')}</span></div>`;
    }
    return html;
}
function loginRenderTable() {
    const q = (document.getElementById('loginTableSearch')?.value || '').toLowerCase().trim();
    const filtered = ulgRecords.filter(r => [r.user,r.source_ip,r.source_host,r.dest_ip,r.dest_host,r.sub_type,r.service_name].join(' ').toLowerCase().includes(q));
    const shown = ulgShowAll ? filtered : filtered.slice(0, 10);
    const body = document.getElementById('loginTableBody');
    body.innerHTML = '';
    if (shown.length === 0) {
        body.innerHTML = '<tr><td colspan="8" class="px-6 py-12 text-center text-slate-400">Brak rekordów pasujących do filtra.</td></tr>';
    } else {
        shown.forEach(r => {
            const user = r.user || '-';
            const firstTime = String(r.time_generated || '-').split('\n')[0] || '-';
            const userAttempts = Number((ulgUsers[user] && ulgUsers[user].events_count) || r.events_count || 0);
            body.innerHTML += `<tr class="cursor-pointer border-b border-slate-100 transition-colors hover:bg-slate-50" onclick="ulgShowDetails('${ulgEscape(user)}')"><td class="whitespace-nowrap px-6 py-4 font-semibold text-slate-900">${ulgEscape(user)}</td><td class="px-6 py-4 text-center"><span class="inline-flex items-center justify-center rounded-full border border-indigo-100 bg-indigo-50 px-3 py-1 text-xs font-extrabold text-indigo-700" title="Łączna liczba prób logowania dla użytkownika">${userAttempts.toLocaleString('pl-PL')}</span></td><td class="px-6 py-4 font-mono text-xs text-slate-500">${ulgEscape(r.source_ip)}</td><td class="px-6 py-4 font-medium text-slate-600">${ulgEscape(r.source_host)}</td><td class="px-6 py-4">${ulgBadge(r.sub_type)}</td><td class="px-6 py-4 font-mono text-xs text-slate-500">${ulgEscape(firstTime)}</td><td class="px-6 py-4 text-slate-600"><span class="rounded border border-slate-200 bg-slate-100 px-2 py-0.5 text-xs text-slate-700">${ulgEscape(r.service_name)}</span></td><td class="px-6 py-4 text-right" onclick="event.stopPropagation()"><button onclick="ulgShowDetails('${ulgEscape(user)}')" class="rounded-lg border border-indigo-100 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-600 transition hover:bg-indigo-600 hover:text-white">Szczegóły</button></td></tr>`;
        });
    }
    document.getElementById('loginShowingCount').innerText = `Pokazujesz ${shown.length.toLocaleString('pl-PL')} z ${filtered.length.toLocaleString('pl-PL')} rekordów`;
    document.getElementById('loginBadgeCount').innerText = ulgShowAll ? 'Wszystkie' : 'Top 10';
    document.getElementById('loginBtnShowAll').innerText = ulgShowAll ? 'Pokaż mniej (10)' : `Pokaż wszystkie (${filtered.length.toLocaleString('pl-PL')})`;
}
function loginToggleAll() { ulgShowAll = !ulgShowAll; loginRenderTable(); }
function ulgShowDetails(user) {
    const d = ulgUsers[user];
    if (!d) return;
    document.body.style.overflow = 'hidden';
    document.getElementById('loginUserModal').classList.remove('hidden');
    document.getElementById('loginUserModal').classList.add('flex');
    document.getElementById('loginModalTitle').innerText = `Profil bezpieczeństwa: ${user}`;
    document.getElementById('ulgDetailHost').innerText = d.source_host || '-';
    document.getElementById('ulgDetailSourceIp').innerText = d.source_ip || '-';
    document.getElementById('ulgDetailDestIp').innerText = d.dest_ip || '-';
    document.getElementById('ulgDetailDestHost').innerText = d.dest_host || '-';
    document.getElementById('ulgDetailService').innerText = d.service_name || '-';
    document.getElementById('ulgDetailAttempts').innerText = Number(d.events_count || 0).toLocaleString('pl-PL') + ' prób';
    document.getElementById('ulgDetailDesc').innerText = d.description || '-';
    document.getElementById('ulgUserHourGrid').innerHTML = ulgHourlyHtml(d.hourly || Array(24).fill(0));
    if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
}
function loginCloseModal() {
    document.body.style.overflow = '';
    document.getElementById('loginUserModal').classList.add('hidden');
    document.getElementById('loginUserModal').classList.remove('flex');
}
document.addEventListener('DOMContentLoaded', () => { loginRenderTable(); if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons(); });
</script>
