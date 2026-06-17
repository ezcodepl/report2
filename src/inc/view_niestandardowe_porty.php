<!-- ========================================== -->
<!-- WIDOK: NIESTANDARDOWE PORTY WYCHODZĄCE -->
<!-- Połączenia wychodzące z hostów wewnętrznych na niestandardowe porty hostów zewnętrznych -->
<!-- ========================================== -->

<?php
if (!function_exists('ns_normalize_label')) {
    function ns_normalize_label($value, $fallback = 'Nieznany') {
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xC2\xA0", '&nbsp;', 'Â '], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        $value = preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $value);
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('ns_parse_terms_counts')) {
    function ns_parse_terms_counts($rawText, $fallbackCount = 0) {
        $items = [];
        $rawText = html_entity_decode((string)$rawText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $rawText = str_replace(["\xC2\xA0", '&nbsp;', 'Â '], ' ', $rawText);
        $rawText = preg_replace('/\s+/u', ' ', trim($rawText));

        if (preg_match_all('/([^()\n]+?)\s*\(([\d\s,]+)\)/u', $rawText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim($match[1]);
                $count = (int) str_replace([' ', ','], '', $match[2]);
                if ($label !== '') {
                    $items[$label] = ($items[$label] ?? 0) + max(1, $count);
                }
            }
        }

        if (empty($items) && trim($rawText) !== '') {
            $items[ns_normalize_label($rawText)] = max(1, (int)$fallbackCount);
        }

        return $items;
    }
}

if (!function_exists('ns_add_stat')) {
    function ns_add_stat(&$bucket, $label, $count) {
        $label = ns_normalize_label($label);
        $count = max(1, (int)$count);
        $bucket[$label] = ($bucket[$label] ?? 0) + $count;
    }
}

if (!function_exists('ns_format_bytes')) {
    function ns_format_bytes($bytes) {
        $bytes = (int)$bytes;
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', ' ') . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', ' ') . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', ' ') . ' KB';
        return number_format($bytes, 0, ',', ' ') . ' B';
    }
}

if (!function_exists('ns_build_hourly_events')) {
    function ns_build_hourly_events($scan) {
        $hourly = array_fill(0, 24, 0);
        if (!empty($scan['hourly_stats']) && is_array($scan['hourly_stats'])) {
            foreach ($scan['hourly_stats'] as $hour => $count) {
                $hour = (int)$hour;
                if ($hour >= 0 && $hour <= 23) $hourly[$hour] += (int)$count;
            }
        } else {
            $raw = (string)($scan['time_generated'] ?? '');
            if (preg_match_all('/\d{4}-\d{2}-\d{2}\s+(\d{2}):\d{2}:\d{2}(?:\s*\(([\d\s,]+)\))?/u', $raw, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $hour = (int)$match[1];
                    $count = isset($match[2]) && trim($match[2]) !== '' ? (int)str_replace([' ', ','], '', $match[2]) : 1;
                    $hourly[$hour] += max(1, $count);
                }
            }
        }
        return [
            'hours' => $hourly,
            'total' => array_sum($hourly),
            'max' => max($hourly) ?: 1,
            'raw' => (string)($scan['time_generated'] ?? '')
        ];
    }
}

if (!function_exists('ns_render_top_card')) {
    function ns_render_top_card($title, $items, $icon = 'bar-chart-3', $accent = 'red') {
        arsort($items);
        $items = array_slice($items, 0, 5, true);
        $max = !empty($items) ? max($items) : 1;
        $color = $accent === 'blue' ? 'text-blue-600' : ($accent === 'indigo' ? 'text-indigo-600' : ($accent === 'emerald' ? 'text-emerald-600' : 'text-red-600'));
        $gradient = $accent === 'blue' ? 'from-blue-500 to-indigo-500' : ($accent === 'indigo' ? 'from-indigo-500 to-violet-500' : ($accent === 'emerald' ? 'from-emerald-500 to-teal-500' : 'from-red-500 to-orange-500'));
        ?>
        <div class="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
            <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
                <i data-lucide="<?php echo htmlspecialchars($icon); ?>" class="h-5 w-5 <?php echo $color; ?>"></i>
                <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900"><?php echo htmlspecialchars($title); ?></h3>
            </div>
            <?php if (empty($items)): ?>
                <p class="py-4 text-center text-xs font-semibold text-slate-400">Brak danych</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($items as $label => $count):
                        $percent = min(100, round(((int)$count / $max) * 100));
                    ?>
                        <div>
                            <div class="mb-1 flex justify-between gap-3 text-xs font-semibold text-slate-700">
                                <span class="truncate font-mono text-slate-900" title="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></span>
                                <span class="whitespace-nowrap <?php echo $color; ?> font-bold"><?php echo number_format((int)$count, 0, ',', ' '); ?> zd.</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-gradient-to-r <?php echo $gradient; ?>" style="width: <?php echo $percent; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

$scans = $parsedData['scans'] ?? [];
$countryCounts = [];
$portCounts = [];
$protocolCounts = [];
$categoryCounts = [];
$appCounts = [];
$totalBytes = 0;

foreach ($scans as $scan) {
    $events = max(1, (int)($scan['events_count'] ?? 1));
    $totalBytes += (int)($scan['bytes_total'] ?? 0);

    foreach (ns_parse_terms_counts($scan['dest_country_raw'] ?? ($scan['dest_country'] ?? ''), $events) as $label => $count) {
        ns_add_stat($countryCounts, $label, $count);
    }
    ns_add_stat($portCounts, $scan['dest_port'] ?? 'Dowolny', $events);
    ns_add_stat($protocolCounts, $scan['protocol'] ?? 'Nieznany', $events);
    ns_add_stat($categoryCounts, $scan['application_category'] ?? ($scan['service'] ?? 'Nieznana'), $events);
    ns_add_stat($appCounts, $scan['application'] ?? 'Nieznana', $events);
}
?>

<!-- Główne Metryki -->
<div class="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Połączenia niestandardowe</p>
                <h3 class="mt-2 text-2xl font-bold text-red-600"><?php echo number_format((int)($parsedData['meta']['suma_zdarzen'] ?? 0), 0, ',', ' '); ?> <span class="text-xs font-medium text-slate-400">zd.</span></h3>
            </div>
            <div class="rounded-xl bg-red-50 p-3 text-red-600"><i data-lucide="radio-tower" class="h-6 w-6"></i></div>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Hosty źródłowe</p>
                <h3 class="mt-2 text-2xl font-bold text-slate-900"><?php echo number_format((int)($parsedData['meta']['unikalne_ip'] ?? 0), 0, ',', ' '); ?></h3>
            </div>
            <div class="rounded-xl bg-slate-100 p-3 text-slate-600"><i data-lucide="server" class="h-6 w-6"></i></div>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Najaktywniejszy host</p>
                <h3 class="mt-2 truncate font-mono text-md font-bold text-red-700" title="<?php echo htmlspecialchars($parsedData['meta']['najbardziej_aktywny_ip'] ?? 'Brak'); ?>"><?php echo htmlspecialchars($parsedData['meta']['najbardziej_aktywny_ip'] ?? 'Brak'); ?></h3>
            </div>
            <div class="rounded-xl bg-red-100 p-3 text-red-600"><i data-lucide="flame" class="h-6 w-6"></i></div>
        </div>
    </div>
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Transfer łączny</p>
                <h3 class="mt-2 text-2xl font-bold text-blue-600"><?php echo ns_format_bytes($totalBytes); ?></h3>
            </div>
            <div class="rounded-xl bg-blue-50 p-3 text-blue-600"><i data-lucide="hard-drive-download" class="h-6 w-6"></i></div>
        </div>
    </div>
</div>

<!-- Wykresy / TOP -->
<div class="mb-8 grid grid-cols-1 gap-6 xl:grid-cols-4">
    <?php ns_render_top_card('Destination.Country', $countryCounts, 'globe-2', 'indigo'); ?>
    <?php ns_render_top_card('TOP 5 Destination.Port', $portCounts, 'unplug', 'red'); ?>
    <?php ns_render_top_card('TOP 5 Protocol.Name', $protocolCounts, 'network', 'blue'); ?>
    <?php ns_render_top_card('TOP 5 Application.Category', $categoryCounts, 'layers', 'emerald'); ?>
</div>

<div class="mb-8 rounded-2xl border border-slate-150 bg-white p-6 shadow-sm">
    <div class="mb-6 flex flex-col justify-between gap-4 border-b border-slate-100 pb-4 sm:flex-row sm:items-center">
        <div>
            <h3 class="flex items-center gap-2 text-base font-bold text-slate-950">
                <span class="h-2.5 w-2.5 rounded-full bg-red-600 animate-ping"></span>
                Połączenia wychodzące z hostów wewnętrznych na niestandardowe porty hostów zewnętrznych
            </h3>
            <p class="mt-1 text-xs text-slate-400">Analiza portów, krajów docelowych, protokołów, transferu oraz godzin występowania zdarzeń.</p>
        </div>
        <div class="relative w-full max-w-xs">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><i data-lucide="search" class="h-4 w-4"></i></span>
            <input type="text" id="scan-search" onkeyup="filterScanTable()" placeholder="Szukaj IP, portu, kraju, protokołu..." class="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-4 text-xs focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/20">
        </div>
    </div>

    <?php $scansTotal = count($scans); ?>
    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-100 bg-slate-50/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-xs font-semibold text-slate-500">
            Pokazuję <span id="scan-visible-count" class="font-extrabold text-slate-900">0</span> z
            <span id="scan-total-count" class="font-extrabold text-slate-900"><?php echo number_format($scansTotal, 0, ',', ' '); ?></span> rekordów
        </div>
        <?php if ($scansTotal > 10): ?>
            <button type="button" id="scan-show-all-btn" onclick="toggleShowAllScans()" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-blue-200 bg-white px-3 py-2 text-[11px] font-bold text-blue-700 shadow-sm hover:bg-blue-50">
                <i data-lucide="list-plus" class="h-3.5 w-3.5"></i> Pokaż wszystkie (<?php echo number_format($scansTotal, 0, ',', ' '); ?>)
            </button>
        <?php endif; ?>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left" id="scans-table">
            <thead>
                <tr class="border-b border-slate-100 bg-slate-50/50 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    <th class="px-4 py-3">Kraj docelowy</th>
                    <th class="px-4 py-3">Host źródłowy</th>
                    <th class="px-4 py-3">Destination IP / Port</th>
                    <th class="px-4 py-3 text-center">Zdarzenia</th>
                    <th class="px-4 py-3">Transfer</th>
                    <th class="px-4 py-3">Protocol / Category</th>
                    <th class="px-4 py-3">Aplikacja / Akcja</th>
                    <th class="px-4 py-3 text-center">Akcja</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 text-xs font-medium" id="scans-table-body">
                <?php if (empty($scans)): ?>
                    <tr><td colspan="8" class="bg-slate-50/30 py-12 text-center font-semibold text-slate-400">Brak rekordów w raporcie.</td></tr>
                <?php else: ?>
                    <?php foreach ($scans as $index => $scan):
                        $rowId = 'scan-row-' . $index;
                        $detailId = 'scan-detail-' . $index;
                        $hoursId = 'scan-hours-' . $index;
                        $hourlyData = ns_build_hourly_events($scan);
                        $hourlyEvents = $hourlyData['hours'];
                        $maxHourlyEvents = $hourlyData['max'];
                        $totalHourlyEvents = $hourlyData['total'];
                        $timeGeneratedRaw = $hourlyData['raw'];
                        $badgeClass = 'bg-blue-50 text-blue-700 border-blue-100';
                        $rowBorder = 'border-l-4 border-l-blue-400';
                        if (($scan['danger_level'] ?? '') === 'High') { $badgeClass = 'bg-orange-50 text-orange-700 border border-orange-200'; $rowBorder = 'border-l-4 border-l-orange-400'; }
                        if (($scan['danger_level'] ?? '') === 'Critical') { $badgeClass = 'bg-red-50 text-red-700 border border-red-200'; $rowBorder = 'border-l-4 border-l-red-500'; }
                        $destCountry = ns_normalize_label($scan['dest_country'] ?? 'Unknown');
                    ?>
                        <tr class="hover:bg-slate-50/50 <?php echo $rowBorder; ?>" id="<?php echo $rowId; ?>">
                            <td class="px-4 py-3.5">
                                <span class="text-xl" title="<?php echo htmlspecialchars($destCountry); ?>"><?php echo $parser->getCountryFlag($destCountry); ?></span>
                                <span class="ml-1.5 text-[10px] font-bold text-slate-500"><?php echo htmlspecialchars($destCountry); ?></span>
                            </td>
                            <td class="px-4 py-3.5 font-mono font-bold text-slate-900">
                                <div class="flex flex-col">
                                    <span><?php echo htmlspecialchars($scan['source_ip'] ?? 'Host wewnętrzny'); ?></span>
                                    <?php if (!empty($scan['source_host']) || !empty($scan['source_username'])): ?>
                                        <span class="text-[10px] font-normal text-slate-400"><?php echo htmlspecialchars(trim(($scan['source_host'] ?? '') . ' ' . ($scan['source_username'] ?? ''))); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 font-mono">
                                <div class="max-h-28 overflow-y-auto">
                                    <div class="font-bold text-slate-900">PORT: <?php echo htmlspecialchars($scan['dest_port'] ?? 'Dowolny'); ?></div>
                                    <div class="whitespace-pre-wrap text-[10px] text-slate-500"><?php echo htmlspecialchars($scan['dest_ip'] ?? 'Dowolny'); ?></div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-center font-mono font-extrabold text-slate-900"><?php echo number_format((int)($scan['events_count'] ?? 0), 0, ',', ' '); ?></td>
                            <td class="px-4 py-3.5">
                                <div class="font-bold text-slate-900"><?php echo htmlspecialchars($scan['bytes_total_label'] ?? ns_format_bytes($scan['bytes_total'] ?? 0)); ?></div>
                                <div class="text-[10px] text-slate-400">RX: <?php echo htmlspecialchars($scan['bytes_received_label'] ?? '-'); ?> / TX: <?php echo htmlspecialchars($scan['bytes_sent_label'] ?? '-'); ?></div>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="font-bold text-slate-900"><?php echo htmlspecialchars($scan['protocol'] ?? 'Nieznany'); ?></div>
                                <div class="text-[10px] font-bold text-slate-400"><?php echo htmlspecialchars($scan['application_category'] ?? ($scan['service'] ?? 'Nieznana')); ?></div>
                            </td>
                            <td class="px-4 py-3.5 text-slate-600">
                                <div class="font-semibold text-slate-800"><?php echo htmlspecialchars($scan['application'] ?? 'Nietypowy port'); ?></div>
                                <div class="truncate text-[10px] text-slate-400" title="<?php echo htmlspecialchars($scan['event_info'] ?? ''); ?>"><?php echo htmlspecialchars($scan['event_info'] ?? ''); ?></div>
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                <div class="flex flex-col items-center gap-1.5">
                                    <button onclick="toggleScanHours('<?php echo $hoursId; ?>')" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50/70 px-2.5 py-1.5 text-[11px] font-bold text-red-700 hover:bg-red-100"><i data-lucide="bar-chart-3" class="h-3.5 w-3.5"></i> Godziny</button>
                                    <button onclick="toggleScanDetails('<?php echo $detailId; ?>')" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-600 hover:bg-slate-50"><i data-lucide="eye" class="h-3.5 w-3.5"></i> Szczegóły</button>
                                </div>
                            </td>
                        </tr>

                        <tr id="<?php echo $hoursId; ?>" class="hidden bg-red-50/30" style="display:none;">
                            <td colspan="8" class="p-5">
                                <div class="rounded-2xl border border-red-100 bg-white p-5 shadow-sm">
                                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h4 class="flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-slate-900"><i data-lucide="clock-3" class="h-4 w-4 text-red-600"></i> Zdarzenia godzinowo — <span class="font-mono text-red-700"><?php echo htmlspecialchars($scan['source_ip'] ?? 'Host'); ?></span></h4>
                                            <p class="mt-1 text-[11px] font-semibold text-slate-400">Suma z widocznych timestampów: <?php echo number_format($totalHourlyEvents, 0, ',', ' '); ?>. Pełna liczba zdarzeń: <?php echo number_format((int)($scan['events_count'] ?? 0), 0, ',', ' '); ?>.</p>
                                        </div>
                                        <div class="rounded-xl bg-red-50 px-3 py-2 text-[11px] font-bold text-red-700">Max/h: <?php echo number_format($maxHourlyEvents, 0, ',', ' '); ?></div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-12">
                                        <?php foreach ($hourlyEvents as $hour => $hourCount):
                                            $ratio = $maxHourlyEvents > 0 ? ($hourCount / $maxHourlyEvents) : 0;
                                            $opacity = $hourCount > 0 ? max(0.16, min(0.95, 0.16 + ($ratio * 0.79))) : 0.04;
                                            $textClass = $ratio > 0.55 ? 'text-white' : 'text-slate-800';
                                            $subTextClass = $ratio > 0.55 ? 'text-red-50' : 'text-slate-500';
                                        ?>
                                            <div class="rounded-xl border border-slate-100 p-2.5 shadow-sm" style="background-color: rgba(220, 38, 38, <?php echo number_format($opacity, 2, '.', ''); ?>);">
                                                <div class="text-[10px] font-black <?php echo $subTextClass; ?>"><?php echo sprintf('%02d:00', $hour); ?></div>
                                                <div class="mt-1 font-mono text-sm font-extrabold <?php echo $textClass; ?>"><?php echo number_format($hourCount, 0, ',', ' '); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (!empty($timeGeneratedRaw)): ?>
                                        <details class="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-3"><summary class="cursor-pointer text-[11px] font-bold uppercase tracking-wide text-slate-500">Surowe Time.Generated</summary><pre class="mt-2 max-h-40 overflow-y-auto whitespace-pre-wrap rounded-lg bg-white p-3 text-[10px] font-mono text-slate-600"><?php echo htmlspecialchars($timeGeneratedRaw); ?></pre></details>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <tr id="<?php echo $detailId; ?>" class="hidden bg-slate-50/50" style="display:none;">
                            <td colspan="8" class="p-6">
                                <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
                                    <div class="rounded-xl border border-slate-100 bg-white p-4">
                                        <h4 class="mb-3 flex items-center gap-1 text-xs font-bold uppercase tracking-wider text-slate-900"><i data-lucide="network" class="h-4 w-4 text-indigo-500"></i> Cele</h4>
                                        <pre class="max-h-48 overflow-y-auto whitespace-pre-wrap text-[11px] font-mono text-slate-600"><?php echo htmlspecialchars($scan['dest_ip'] ?? ''); ?></pre>
                                    </div>
                                    <div class="rounded-xl border border-slate-100 bg-white p-4">
                                        <h4 class="mb-3 flex items-center gap-1 text-xs font-bold uppercase tracking-wider text-slate-900"><i data-lucide="database" class="h-4 w-4 text-blue-500"></i> Transfer</h4>
                                        <div class="space-y-2 text-[11px] text-slate-600"><div><b>Received:</b> <?php echo htmlspecialchars($scan['bytes_received_label'] ?? '-'); ?></div><div><b>Sent:</b> <?php echo htmlspecialchars($scan['bytes_sent_label'] ?? '-'); ?></div><div><b>Total:</b> <?php echo htmlspecialchars($scan['bytes_total_label'] ?? '-'); ?></div></div>
                                    </div>
                                    <div class="rounded-xl border border-slate-100 bg-white p-4">
                                        <h4 class="mb-3 flex items-center gap-1 text-xs font-bold uppercase tracking-wider text-slate-900"><i data-lucide="search" class="h-4 w-4 text-red-500"></i> Analiza celu</h4>
                                        <div class="mb-3 text-[11px] text-slate-500">Port <b><?php echo htmlspecialchars($scan['dest_port'] ?? ''); ?></b>, kraj <b><?php echo htmlspecialchars($destCountry); ?></b>, zdarzeń <b><?php echo number_format((int)($scan['events_count'] ?? 0), 0, ',', ' '); ?></b>.</div>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="<?php echo htmlspecialchars($scan['abuse_url'] ?? '#'); ?>" target="_blank" rel="noopener" class="rounded-lg border border-red-200 bg-red-50/50 px-3 py-1.5 text-[10px] font-bold text-red-700">AbuseIPDB</a>
                                            <a href="<?php echo htmlspecialchars($scan['virustotal_url'] ?? '#'); ?>" target="_blank" rel="noopener" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-bold text-slate-700">VirusTotal</a>
                                            <a href="<?php echo htmlspecialchars($scan['whois_url'] ?? '#'); ?>" target="_blank" rel="noopener" class="rounded-lg border border-blue-200 bg-blue-50/50 px-3 py-1.5 text-[10px] font-bold text-blue-700">WHOIS</a>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const SCAN_TABLE_LIMIT = 10;
let scanTableShowAll = false;
function getScanIndex(row) { return row.id.split('-').pop(); }
function getScanDetailRow(row) { return document.getElementById('scan-detail-' + getScanIndex(row)); }
function getScanHoursRow(row) { return document.getElementById('scan-hours-' + getScanIndex(row)); }
function renderScanTable() {
    const query = (document.getElementById('scan-search')?.value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#scans-table-body tr[id^="scan-row-"]');
    const visibleCountEl = document.getElementById('scan-visible-count');
    const totalCountEl = document.getElementById('scan-total-count');
    const showAllBtn = document.getElementById('scan-show-all-btn');
    let matchedCount = 0, visibleCount = 0;
    rows.forEach(row => {
        const detailRow = getScanDetailRow(row);
        const hoursRow = getScanHoursRow(row);
        const text = (row.innerText + ' ' + (detailRow ? detailRow.innerText : '') + ' ' + (hoursRow ? hoursRow.innerText : '')).toLowerCase();
        const matches = !query || text.includes(query);
        if (matches) matchedCount++;
        const shouldShow = matches && (scanTableShowAll || visibleCount < SCAN_TABLE_LIMIT);
        row.style.display = shouldShow ? '' : 'none';
        if (shouldShow) visibleCount++;
        if (!shouldShow) {
            [detailRow, hoursRow].forEach(r => { if (r) { r.classList.add('hidden'); r.style.display = 'none'; } });
        }
    });
    if (visibleCountEl) visibleCountEl.textContent = visibleCount.toLocaleString('pl-PL');
    if (totalCountEl) totalCountEl.textContent = matchedCount.toLocaleString('pl-PL');
    if (showAllBtn) {
        showAllBtn.classList.toggle('hidden', matchedCount <= SCAN_TABLE_LIMIT);
        showAllBtn.innerHTML = scanTableShowAll
            ? '<i data-lucide="list-collapse" class="h-3.5 w-3.5"></i> Pokaż tylko TOP 10'
            : '<i data-lucide="list-plus" class="h-3.5 w-3.5"></i> Pokaż wszystkie (' + matchedCount.toLocaleString('pl-PL') + ')';
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }
}
function filterScanTable() { scanTableShowAll = false; renderScanTable(); }
function toggleShowAllScans() { scanTableShowAll = !scanTableShowAll; renderScanTable(); }
function toggleRow(id) {
    const row = document.getElementById(id);
    if (!row) return;
    const hidden = row.classList.contains('hidden');
    row.classList.toggle('hidden', !hidden);
    row.style.display = hidden ? '' : 'none';
}
function toggleScanHours(id) { toggleRow(id); }
function toggleScanDetails(id) { toggleRow(id); }
document.addEventListener('DOMContentLoaded', renderScanTable);
</script>
