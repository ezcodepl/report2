
<!-- ========================================== -->
<!-- WIDOK: SKANOWANIE I NARUSZENIA BEZPIECZEŃSTWA -->
<!-- ========================================== -->

<?php
if (!function_exists('buildScanHourlyEvents')) {
    /**
     * Buduje rozkład zdarzeń godzinowych z kolumny Time.Generated.
     * Obsługuje wartości zagnieżdżone z HTML-a, np.:
     * 2026-05-21 20:27:18 (21)
     * 2026-05-21 14:03:49 (15)
     */
    function buildScanHourlyEvents($scan) {
        if (!empty($scan['hourly_stats']) && is_array($scan['hourly_stats'])) {
            $hourlyEvents = array_fill(0, 24, 0);
            foreach ($scan['hourly_stats'] as $hour => $count) {
                $hour = (int)$hour;
                if ($hour >= 0 && $hour <= 23) {
                    $hourlyEvents[$hour] = (int)$count;
                }
            }
            return [
                'hours' => $hourlyEvents,
                'total' => array_sum($hourlyEvents),
                'max' => max($hourlyEvents) ?: 1,
                'raw' => (string)($scan['time_generated'] ?? ''),
            ];
        }

        $hourlyEvents = array_fill(0, 24, 0);
        $rawChunks = [];

        $collect = function ($value, $key = '') use (&$collect, &$rawChunks) {
            if (is_array($value)) {
                foreach ($value as $childKey => $childValue) {
                    $collect($childValue, (string)$childKey);
                }
                return;
            }

            if (!is_scalar($value)) {
                return;
            }

            $text = html_entity_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = str_replace("\xC2\xA0", ' ', $text);
            $text = preg_replace('/\s+/u', ' ', trim($text));

            $keyNormalized = strtolower((string)$key);
            $looksLikeTimeGenerated = str_contains($keyNormalized, 'time_generated')
                || str_contains($keyNormalized, 'time.generated')
                || str_contains($keyNormalized, 'time generated')
                || str_contains($keyNormalized, 'generated');

            if ($text !== '' && ($looksLikeTimeGenerated || preg_match('/\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}/', $text))) {
                $rawChunks[] = $text;
            }
        };

        $collect($scan);
        $rawText = implode("\n", array_unique($rawChunks));

        if (preg_match_all('/\d{4}-\d{2}-\d{2}\s+(\d{2}):\d{2}:\d{2}(?:\s*\(([\d\s]+)\))?/u', $rawText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $hour = (int)$match[1];
                $count = isset($match[2]) && trim($match[2]) !== '' ? (int)str_replace(' ', '', $match[2]) : 1;
                if ($hour >= 0 && $hour <= 23) {
                    $hourlyEvents[$hour] += max(1, $count);
                }
            }
        } elseif (!empty($scan['time_generated']) && preg_match('/(\d{2}):\d{2}:\d{2}/', (string)$scan['time_generated'], $match)) {
            $hourlyEvents[(int)$match[1]] = (int)($scan['events_count'] ?? 1);
        }

        return [
            'hours' => $hourlyEvents,
            'total' => array_sum($hourlyEvents),
            'max' => max($hourlyEvents) ?: 1,
            'raw' => $rawText,
        ];
    }
}
?>

<!-- Główne Metryki Security (KPI) -->
<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm hover:shadow-md transition-all duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Wykryte skanowania</p>
                <h3 class="mt-2 text-2xl font-bold text-red-600"><?php echo number_format($parsedData['meta']['suma_zdarzen'], 0, ',', ' '); ?> <span class="text-xs font-medium text-slate-400">zd.</span></h3>
            </div>
            <div class="rounded-xl bg-red-50 p-3 text-red-600 animate-pulse">
                <i data-lucide="shield-alert" class="h-6 w-6"></i>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm hover:shadow-md transition-all duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Aktywne Hosty Zewnętrzne</p>
                <h3 class="mt-2 text-2xl font-bold text-slate-900"><?php echo $parsedData['meta']['unikalne_ip']; ?> <span class="text-xs font-medium text-slate-400">hostów</span></h3>
            </div>
            <div class="rounded-xl bg-slate-100 p-3 text-slate-600">
                <i data-lucide="shield-off" class="h-6 w-6"></i>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm hover:shadow-md transition-all duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Najaktywniejszy Host (IP)</p>
                <h3 class="mt-2 text-md font-bold text-red-700 font-mono truncate" title="<?php echo htmlspecialchars($parsedData['meta']['najbardziej_aktywny_ip']); ?>">
                    <?php echo htmlspecialchars($parsedData['meta']['najbardziej_aktywny_ip']); ?>
                </h3>
            </div>
            <div class="rounded-xl bg-red-100 p-3 text-red-600">
                <i data-lucide="flame" class="h-6 w-6"></i>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm hover:shadow-md transition-all duration-200">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">System Zabezpieczeń</p>
                <h3 class="mt-2 text-2xl font-bold text-blue-600"><?php echo htmlspecialchars($parsedData['meta']['urzadzenie']); ?></h3>
            </div>
            <div class="rounded-xl bg-blue-50 p-3 text-blue-600">
                <i data-lucide="shield-check" class="h-6 w-6"></i>
            </div>
        </div>
    </div>
</div>
<!-- Sekcja Wykresów Podsumowujących Krajów, Portów, Usług i Protokołów -->
<?php
if (!function_exists('zewScanNormalizeStatText')) {
    function zewScanNormalizeStatText($value) {
        $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\xC2\xA0", 'Â ', '&nbsp;'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', trim($value));
        return trim($value);
    }
}

if (!function_exists('zewScanCleanLabel')) {
    function zewScanCleanLabel($value, $fallback = 'Nieznany') {
        $value = zewScanNormalizeStatText($value);
        $value = preg_replace('/\s*\([\d\s,]+\)\s*$/u', '', $value);
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }
}

if (!function_exists('zewScanCounter')) {
    function zewScanCounter($scan) {
        $count = (int)($scan['event_value_count'] ?? $scan['events_count'] ?? 0);
        return $count > 0 ? $count : 1;
    }
}

if (!function_exists('zewScanAddTermCounts')) {
    /**
     * Dodaje liczniki z tekstu typu:
     * United States (114)
     * Australia (8)
     * albo z pojedynczej wartości bez nawiasu.
     */
    function zewScanAddTermCounts(&$target, $rawText, $fallbackCount = 1, $fallbackLabel = 'Nieznany', $skipReserved = false) {
        $rawText = zewScanNormalizeStatText($rawText);

        if ($rawText === '') {
            $target[$fallbackLabel] = ($target[$fallbackLabel] ?? 0) + max(1, (int)$fallbackCount);
            return;
        }

        preg_match_all('/([^\(\)\n]+?)\s*\(([\d\s,]+)\)/u', $rawText, $matches, PREG_SET_ORDER);

        if (!empty($matches)) {
            foreach ($matches as $match) {
                $label = zewScanCleanLabel($match[1], $fallbackLabel);
                if ($skipReserved && strcasecmp($label, 'Reserved') === 0) {
                    continue;
                }

                $count = (int)str_replace([' ', ','], '', $match[2]);
                if ($count <= 0) $count = 1;

                $target[$label] = ($target[$label] ?? 0) + $count;
            }
            return;
        }

        $label = zewScanCleanLabel($rawText, $fallbackLabel);
        if ($skipReserved && strcasecmp($label, 'Reserved') === 0) {
            return;
        }

        $target[$label] = ($target[$label] ?? 0) + max(1, (int)$fallbackCount);
    }
}

if (!function_exists('zewScanRenderBarStats')) {
    function zewScanRenderBarStats($title, $items, $accentClass = 'text-red-600', $barClass = 'from-red-500 to-orange-500') {
        arsort($items);
        $items = array_slice($items, 0, 5, true);
        $max = !empty($items) ? max($items) : 1;
        ?>
        <div class="mb-5 last:mb-0">
            <div class="mb-2 flex items-center justify-between">
                <h4 class="text-[11px] font-extrabold uppercase tracking-wider text-slate-500"><?php echo htmlspecialchars($title); ?></h4>
                <span class="text-[10px] font-bold text-slate-400">TOP 5</span>
            </div>

            <?php if (empty($items)): ?>
                <p class="py-2 text-xs font-semibold text-slate-400">Brak danych</p>
            <?php else: ?>
                <div class="space-y-2.5">
                    <?php foreach ($items as $label => $count):
                        $percent = min(100, round(((int)$count / $max) * 100));
                    ?>
                        <div>
                            <div class="mb-1 flex justify-between gap-3 text-xs font-semibold text-slate-700">
                                <span class="truncate font-mono text-slate-900" title="<?php echo htmlspecialchars($label); ?>"><?php echo htmlspecialchars($label); ?></span>
                                <span class="<?php echo $accentClass; ?> whitespace-nowrap font-bold"><?php echo number_format((int)$count, 0, ',', ' '); ?> zd.</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-gradient-to-r <?php echo $barClass; ?> transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

$sourceCountryCounts = [];
$portCounts = [];
$serviceNameCounts = [];
$protocolNameCounts = [];

foreach ($parsedData['scans'] as $scan) {
    $eventCount = zewScanCounter($scan);

    // Dla hostów zewnętrznych geolokalizacja ma pokazywać Source.Country.
    zewScanAddTermCounts(
        $sourceCountryCounts,
        $scan['source_country_raw'] ?? $scan['source_country'] ?? '',
        $eventCount,
        'Nieznany',
        true
    );

    $port = zewScanCleanLabel($scan['dest_port_raw'] ?? $scan['dest_port'] ?? '', 'Dowolny');
    $service = zewScanCleanLabel($scan['service_raw'] ?? $scan['service'] ?? '', 'Nieznana');
    $protocol = zewScanCleanLabel($scan['protocol_raw'] ?? $scan['protocol'] ?? '', 'Nieznany');

    $portCounts[$port] = ($portCounts[$port] ?? 0) + $eventCount;
    $serviceNameCounts[$service] = ($serviceNameCounts[$service] ?? 0) + $eventCount;
    $protocolNameCounts[$protocol] = ($protocolNameCounts[$protocol] ?? 0) + $eventCount;
}

arsort($sourceCountryCounts);
$topCountries = array_slice($sourceCountryCounts, 0, 5, true);
$maxCountryEvents = !empty($topCountries) ? max($topCountries) : 1;
?>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 mb-8">
    <!-- Top Kraje Pochodzenia Skanów -->
    <div class="lg:col-span-1 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
            <i data-lucide="globe-2" class="h-5 w-5 text-indigo-600"></i>
            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">Source.Country — TOP kraje źródłowe</h3>
        </div>
        <div class="space-y-4">
            <?php if (empty($topCountries)): ?>
                <p class="py-4 text-center text-xs font-semibold text-slate-400">Brak szczegółowych danych geolokalizacyjnych</p>
            <?php else: ?>
                <?php foreach ($topCountries as $countryName => $count):
                    $percent = min(100, round(($count / $maxCountryEvents) * 100));
                ?>
                    <div>
                        <div class="mb-1 flex justify-between text-xs font-semibold text-slate-700">
                            <span class="flex items-center gap-1.5">
                                <span class="text-lg"><?php echo $parser->getCountryFlag($countryName); ?></span>
                                <span><?php echo htmlspecialchars($countryName); ?></span>
                            </span>
                            <span class="font-bold text-indigo-600"><?php echo number_format($count, 0, ',', ' '); ?> zd.</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-blue-600 transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Top 5 Porty / Usługi / Protokoły -->
    <div class="lg:col-span-2 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3">
            <i data-lucide="bar-chart-3" class="h-5 w-5 text-red-600"></i>
            <h3 class="text-sm font-bold uppercase tracking-wide text-slate-900">TOP 5: Destination.Port / Service.Name / Protocol.Name</h3>
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <?php zewScanRenderBarStats('TOP 5 Destination.Port', $portCounts, 'text-red-600', 'from-red-500 to-orange-500'); ?>
            <?php zewScanRenderBarStats('TOP 5 Service.Name', $serviceNameCounts, 'text-blue-600', 'from-blue-500 to-indigo-500'); ?>
            <?php zewScanRenderBarStats('TOP 5 Protocol.Name', $protocolNameCounts, 'text-indigo-600', 'from-indigo-500 to-violet-500'); ?>
        </div>
    </div>
</div>


<!-- Tabela Skonfigurowana dla Skanowania -->
<div class="mb-8 rounded-2xl border border-slate-150 bg-white p-6 shadow-sm">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center mb-6 border-b border-slate-100 pb-4">
        <div>
            <h3 class="text-base font-bold text-slate-950 flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-red-600 animate-ping"></span>
                Hosty zewnętrzne skanujące porty — analiza zdarzeń Firewall / Auth
            </h3>
            <p class="text-xs text-slate-400 mt-1">Hosty zewnętrzne generujące próby skanowania portów i nietypowe połączenia.</p>
        </div>
        <!-- Dynamiczne Filtrowanie -->
        <div class="relative max-w-xs w-full">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                <i data-lucide="search" class="w-4 h-4"></i>
            </span>
            <input type="text" id="scan-search" onkeyup="filterScanTable()" placeholder="Szukaj IP, kraju, portu..." class="w-full pl-9 pr-4 py-2 text-xs border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 focus:outline-none">
        </div>
    </div>

    <?php $scansTotal = count($parsedData['scans'] ?? []); ?>
    <div class="mb-4 flex flex-col gap-3 rounded-xl border border-slate-100 bg-slate-50/50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-xs font-semibold text-slate-500">
            Pokazuję <span id="scan-visible-count" class="font-extrabold text-slate-900">0</span> z
            <span id="scan-total-count" class="font-extrabold text-slate-900"><?php echo number_format($scansTotal, 0, ',', ' '); ?></span> rekordów
        </div>
        <?php if ($scansTotal > 10): ?>
            <button type="button" id="scan-show-all-btn" onclick="toggleShowAllScans()" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-blue-200 bg-white px-3 py-2 text-[11px] font-bold text-blue-700 shadow-sm hover:bg-blue-50 transition-all">
                <i data-lucide="list-plus" class="h-3.5 w-3.5"></i>
                Pokaż wszystkie (<?php echo number_format($scansTotal, 0, ',', ' '); ?>)
            </button>
        <?php endif; ?>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse" id="scans-table">
            <thead>
                <tr class="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-400 bg-slate-50/50">
                    <th class="py-3 px-4">Kraj (Flaga)</th>
                    <th class="py-3 px-4">Źródło (Source IP)</th>
                    <th class="py-3 px-4 w-1/3">Cele (Dest IP & Port / Zagnieżdżone)</th>
                    <th class="py-3 px-4 text-center">Zdarzenia</th>
                    <th class="py-3 px-4 text-center">Zagrożenie</th>
                    <th class="py-3 px-4">Aplikacja / Protokół</th>
                    <th class="py-3 px-4">Typ / Sygnatura</th>
                    <th class="py-3 px-4 text-center">Akcja</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 text-xs font-medium" id="scans-table-body">
                <?php if (empty($parsedData['scans'])): ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-400 font-semibold bg-slate-50/30">
                            <div class="flex flex-col items-center justify-center gap-2">
                                <i data-lucide="shield-alert" class="h-8 w-8 text-slate-300"></i>
                                <span>Brak wykrytych rekordów w wybranym pliku raportu HTML.</span>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($parsedData['scans'] as $index => $scan):
                        $badgeClass = 'bg-blue-50 text-blue-700 border-blue-100';
                        $rowBorder = 'border-l-4 border-l-blue-400';
                        if ($scan['danger_level'] === 'Critical') {
                            $badgeClass = 'bg-red-50 text-red-700 border border-red-200';
                            $rowBorder = 'border-l-4 border-l-red-500';
                        } elseif ($scan['danger_level'] === 'High') {
                            $badgeClass = 'bg-orange-50 text-orange-700 border border-orange-200';
                            $rowBorder = 'border-l-4 border-l-orange-400';
                        }

                        $rowId = 'scan-row-' . $index;
                        $detailId = 'scan-detail-' . $index;
                        $hoursId = 'scan-hours-' . $index;

                        // Grupowanie Time.Generated do widoku godzinowego dla wybranego hosta.
                        // Teraz czyta wszystkie wpisy z kolumny Time.Generated, także zagnieżdżone z HTML-a: data godzina (liczba).
                        $hourlyData = buildScanHourlyEvents($scan);
                        $hourlyEvents = $hourlyData['hours'];
                        $maxHourlyEvents = $hourlyData['max'];
                        $totalHourlyEvents = $hourlyData['total'];
                        $timeGeneratedRaw = $hourlyData['raw'];
                    ?>
                        <!-- Główny Wiersz Hosta -->
                        <tr class="hover:bg-slate-50/50 transition-colors <?php echo $rowBorder; ?>" id="<?php echo $rowId; ?>">
                            <td class="py-3.5 px-4">
                                <span class="text-xl inline-block align-middle" title="<?php echo htmlspecialchars($scan['source_country']); ?>">
                                    <?php echo $parser->getCountryFlag($scan['source_country']); ?>
                                </span>
                                <span class="text-slate-500 text-[10px] ml-1.5 align-middle block sm:inline font-bold"><?php echo htmlspecialchars($scan['source_country']); ?></span>
                            </td>
                            <td class="py-3.5 px-4 font-bold text-slate-900 font-mono">
                                <div class="flex flex-col">
                                    <span><?php echo htmlspecialchars($scan['source_ip']); ?></span>
                                    <span class="text-[10px] text-slate-400 font-normal">Zewnętrzny agresor</span>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 font-mono font-medium">
                                <div class="flex flex-col gap-1.5 max-h-36 overflow-y-auto py-1">
                                    <?php
                                    $rawDest = $scan['dest_ip'];
                                    $dest_ips = preg_split('/[\s,\n]+/', $rawDest);
                                    $dest_ips = array_filter(array_map('trim', $dest_ips));

                                    // Renderowanie zagnieżdżonych celów jako mini-tagów
                                    $chipCount = 0;
                                    foreach ($dest_ips as $single_ip):
                                        if (empty($single_ip)) continue;
                                        $single_ip_clean = preg_replace('/\s*\([^)]*\)/', '', $single_ip);

                                        // Wyciągnięcie liczby prób dla konkretnego IP z nawiasu
                                        $single_count = '';
                                        if (preg_match('/\(([\d\s]+)\)/', $single_ip, $cm)) {
                                            $single_count = ' (' . trim($cm[1]) . ')';
                                        }
                                        $chipCount++;
                                        if ($chipCount <= 2):
                                    ?>
                                        <div class="flex items-center justify-between bg-slate-50 rounded-lg px-2 py-1 border border-slate-100 hover:bg-slate-100/70 transition-all text-[10px]">
                                            <div class="flex flex-col">
                                                <span class="font-bold text-slate-800"><?php echo htmlspecialchars($single_ip_clean); ?><span class="text-indigo-600 font-mono text-[9px]"><?php echo $single_count; ?></span></span>
                                                <span class="text-[9px] text-slate-400 font-bold">PORT: <?php echo htmlspecialchars($scan['dest_port']); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php endforeach; ?>

                                    <?php if (count($dest_ips) > 2): ?>
                                        <span class="text-[10px] text-blue-600 font-semibold pl-1">+<?php echo (count($dest_ips) - 2); ?> kolejnych celów (kliknij Szczegóły)</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-center font-extrabold text-slate-900 font-mono">
                                <?php echo number_format($scan['events_count'], 0, ',', ' '); ?>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-extrabold uppercase <?php echo $badgeClass; ?>">
                                    <?php echo $scan['danger_level']; ?>
                                </span>
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="text-slate-900 font-bold"><?php echo htmlspecialchars($scan['application']); ?></div>
                                <div class="text-[10px] text-slate-400 font-bold font-mono"><?php echo htmlspecialchars($scan['protocol']); ?> / <?php echo htmlspecialchars($scan['service']); ?></div>
                            </td>
                            <td class="py-3.5 px-4 text-slate-500">
                                <div class="font-semibold text-slate-700"><?php echo htmlspecialchars($scan['event_info']); ?></div>
                                <div class="text-[10px] font-normal text-slate-400 truncate max-w-[120px]" title="<?php echo htmlspecialchars($scan['event_desc']); ?>">
                                    <?php echo htmlspecialchars($scan['event_desc']); ?>
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                <div class="flex flex-col items-center justify-center gap-1.5">
                                    <button onclick="toggleScanHours('<?php echo $hoursId; ?>')" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50/70 px-2.5 py-1.5 text-[11px] font-bold text-red-700 shadow-sm hover:bg-red-100 hover:border-red-300 transition-all">
                                        <i data-lucide="bar-chart-3" class="h-3.5 w-3.5"></i>
                                        Wybierz
                                    </button>
                                    <button onclick="toggleScanDetails('<?php echo $detailId; ?>')" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-600 shadow-sm hover:bg-slate-50 hover:text-blue-600 hover:border-blue-200 transition-all">
                                        <i data-lucide="eye" class="h-3.5 w-3.5"></i>
                                        Szczegóły
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- Podgląd zdarzeń godzinowych dla wybranego hosta -->
                        <tr id="<?php echo $hoursId; ?>" class="hidden bg-red-50/30" style="display: none;">
                            <td colspan="8" class="p-5">
                                <div class="rounded-2xl border border-red-100 bg-white p-5 shadow-sm">
                                    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h4 class="flex items-center gap-2 text-xs font-extrabold uppercase tracking-wider text-slate-900">
                                                <i data-lucide="clock-3" class="h-4 w-4 text-red-600"></i>
                                                Podgląd zdarzeń w godzinach dla hosta
                                                <span class="font-mono text-red-700"><?php echo htmlspecialchars($scan['source_ip']); ?></span>
                                            </h4>
                                            <p class="mt-1 text-[11px] font-semibold text-slate-400">
                                                Im ciemniejszy kafelek, tym więcej zdarzeń w danej godzinie. Suma z timestampów: <?php echo number_format($totalHourlyEvents, 0, ',', ' '); ?>.
                                            </p>
                                        </div>
                                        <div class="rounded-xl bg-red-50 px-3 py-2 text-[11px] font-bold text-red-700">
                                            Max/h: <?php echo number_format($maxHourlyEvents, 0, ',', ' '); ?>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 md:grid-cols-6 xl:grid-cols-12">
                                        <?php foreach ($hourlyEvents as $hour => $hourCount):
                                            $ratio = $maxHourlyEvents > 0 ? ($hourCount / $maxHourlyEvents) : 0;
                                            $opacity = $hourCount > 0 ? max(0.16, min(0.95, 0.16 + ($ratio * 0.79))) : 0.04;
                                            $textClass = $ratio > 0.55 ? 'text-white' : 'text-slate-800';
                                            $subTextClass = $ratio > 0.55 ? 'text-red-50' : 'text-slate-500';
                                        ?>
                                            <div class="rounded-xl border border-slate-100 p-2.5 shadow-sm transition hover:scale-[1.02]" style="background-color: rgba(220, 38, 38, <?php echo number_format($opacity, 2, '.', ''); ?>);" title="<?php echo sprintf('%02d:00', $hour); ?> - <?php echo number_format($hourCount, 0, ',', ' '); ?> zdarzeń">
                                                <div class="text-[10px] font-black uppercase <?php echo $subTextClass; ?>"><?php echo sprintf('%02d:00', $hour); ?></div>
                                                <div class="mt-1 font-mono text-sm font-extrabold <?php echo $textClass; ?>"><?php echo number_format($hourCount, 0, ',', ' '); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if ($totalHourlyEvents === 0): ?>
                                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-[11px] font-semibold text-amber-800">
                                            Nie znaleziono pełnej listy timestampów w <span class="font-mono">$scan</span>. Sprawdź, czy parser zapisuje całą kolumnę <span class="font-mono">Time.Generated (Term)</span>, a nie tylko pierwszy czas.
                                        </div>
                                    <?php elseif (!empty($timeGeneratedRaw)): ?>
                                        <details class="mt-4 rounded-xl border border-slate-100 bg-slate-50 p-3">
                                            <summary class="cursor-pointer text-[11px] font-bold uppercase tracking-wide text-slate-500">Surowe wpisy Time.Generated użyte do wyliczenia godzin</summary>
                                            <pre class="mt-2 max-h-40 overflow-y-auto whitespace-pre-wrap rounded-lg bg-white p-3 text-[10px] font-mono text-slate-600"><?php echo htmlspecialchars($timeGeneratedRaw); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Rozwijany Panel Szczegółów (Zagnieżdżona korelacja danych i timeline) -->
                        <tr id="<?php echo $detailId; ?>" class="hidden bg-slate-50/50">
                            <td colspan="8" class="p-6">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 border-l-2 border-slate-200 pl-4 py-1">

                                    <!-- Lewa: Pełna lista wszystkich zagnieżdżonych adresów docelowych -->
                                    <div>
                                        <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3 flex items-center gap-1">
                                            <i data-lucide="network" class="h-4 w-4 text-indigo-500"></i>
                                            Wszystkie zagnieżdżone cele (Destination IPs)
                                        </h4>
                                        <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
                                            <?php foreach ($dest_ips as $single_ip):
                                                $single_ip_clean = preg_replace('/\s*\([^)]*\)/', '', $single_ip);
                                                $single_count = '1';
                                                if (preg_match('/\(([\d\s]+)\)/', $single_ip, $cm)) {
                                                    $single_count = trim($cm[1]);
                                                }
                                            ?>
                                                <div class="flex items-center justify-between bg-white rounded-lg p-2 border border-slate-150 font-mono text-[11px] hover:border-indigo-200 transition">
                                                    <div>
                                                        <span class="font-bold text-slate-900"><?php echo htmlspecialchars($single_ip_clean); ?></span>
                                                        <div class="text-[9px] text-slate-400 font-sans font-bold">Usługa: <?php echo htmlspecialchars($scan['service'] ?: 'Port '.$scan['dest_port']); ?></div>
                                                    </div>
                                                    <span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded text-[10px] font-bold">x<?php echo $single_count; ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Środek: Timeline i zdarzenia szczegółowe -->
                                    <div>
                                        <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3 flex items-center gap-1">
                                            <i data-lucide="clock" class="h-4 w-4 text-orange-500"></i>
                                            Czas generowania (Wykryte timestampy)
                                        </h4>
                                        <div class="bg-white rounded-xl border border-slate-150 p-4 space-y-2.5 max-h-48 overflow-y-auto">
                                            <?php if (!empty($scan['time_generated'])): ?>
                                                <div class="flex items-center gap-2 text-[11px] font-mono text-slate-600">
                                                    <span class="h-2 w-2 rounded-full bg-orange-400"></span>
                                                    <span><?php echo htmlspecialchars($scan['time_generated']); ?></span>
                                                </div>
                                            <?php else: ?>
                                                <div class="flex items-center gap-2 text-[11px] font-mono text-slate-600">
                                                    <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                                                    <span><?php echo date('Y-m-d H:i:s'); ?> (Timestamp domyślny)</span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="text-[11px] text-slate-500 pt-1 leading-relaxed">
                                                <b>Opis incydentu:</b> <?php echo htmlspecialchars($scan['event_desc'] ?: 'Wykryto złośliwe próby skanowania portów z adresu zewnętrznego.'); ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Prawa: Śledztwo, Analiza reputacji i akcje -->
                                    <div>
                                        <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3 flex items-center gap-1">
                                            <i data-lucide="search" class="h-4 w-4 text-red-500"></i>
                                            Analiza Reputacyjna IP źródłowego
                                        </h4>
                                        <div class="bg-white rounded-xl border border-slate-150 p-4 space-y-3">
                                            <div class="text-[11px] text-slate-500 leading-snug">
                                                Adres IP <span class="font-mono font-bold text-slate-900"><?php echo htmlspecialchars($scan['source_ip']); ?></span> pochodzi z kraju <b><?php echo htmlspecialchars($scan['source_country']); ?></b> i wykonał łącznie <b><?php echo number_format($scan['events_count'], 0, ',', ' '); ?></b> prób połączeń.
                                            </div>
                                            <!-- Przyciski Śledztwa -->
                                            <div class="flex flex-wrap gap-2 pt-1">
                                                <a href="<?php echo $scan['abuse_url']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50/50 px-3 py-1.5 text-[10px] font-bold text-red-700 hover:bg-red-100 transition">
                                                    <i data-lucide="shield-alert" class="h-3.5 w-3.5"></i> AbuseIPDB
                                                </a>
                                                <a href="<?php echo $scan['virustotal_url']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] font-bold text-slate-700 hover:bg-slate-100 transition">
                                                    <i data-lucide="globe" class="h-3.5 w-3.5"></i> VirusTotal
                                                </a>
                                                <a href="<?php echo $scan['whois_url']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded-lg border border-blue-200 bg-blue-50/50 px-3 py-1.5 text-[10px] font-bold text-blue-700 hover:bg-blue-100 transition">
                                                    <i data-lucide="search" class="h-3.5 w-3.5"></i> WHOIS
                                                </a>
                                            </div>
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

<!-- Skrypty obsługi tabeli skanowania -->
<script>
    const SCAN_TABLE_LIMIT = 10;
    let scanTableShowAll = false;

    function getScanIndex(row) {
        const rowIdParts = row.id.split('-');
        return rowIdParts[rowIdParts.length - 1];
    }

    function getScanDetailRow(row) {
        return document.getElementById('scan-detail-' + getScanIndex(row));
    }

    function getScanHoursRow(row) {
        return document.getElementById('scan-hours-' + getScanIndex(row));
    }

    /**
     * Renderuje tabelę: domyślnie pokazuje TOP 10, a po kliknięciu wszystkie rekordy.
     * Działa również po filtrowaniu wyszukiwarką.
     */
    function renderScanTable() {
        const searchInput = document.getElementById('scan-search');
        const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const tbody = document.getElementById('scans-table-body');
        const rows = tbody ? tbody.querySelectorAll('tr[id^="scan-row-"]') : [];
        const visibleCountEl = document.getElementById('scan-visible-count');
        const totalCountEl = document.getElementById('scan-total-count');
        const showAllBtn = document.getElementById('scan-show-all-btn');

        let matchedCount = 0;
        let visibleCount = 0;

        rows.forEach(row => {
            const detailRow = getScanDetailRow(row);
            const hoursRow = getScanHoursRow(row);

            const mainText = row.innerText.toLowerCase();
            const detailText = detailRow ? detailRow.innerText.toLowerCase() : '';
            const combinedText = mainText + ' ' + detailText;
            const matchesSearch = !query || combinedText.includes(query);

            if (matchesSearch) {
                matchedCount++;
            }

            const shouldShow = matchesSearch && (scanTableShowAll || visibleCount < SCAN_TABLE_LIMIT);

            if (shouldShow) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
                if (detailRow) {
                    detailRow.classList.add('hidden');
                    detailRow.style.display = 'none';
                }
                if (hoursRow) {
                    hoursRow.classList.add('hidden');
                    hoursRow.style.display = 'none';
                }
            }
        });

        if (visibleCountEl) {
            visibleCountEl.textContent = visibleCount.toLocaleString('pl-PL');
        }
        if (totalCountEl) {
            totalCountEl.textContent = matchedCount.toLocaleString('pl-PL');
        }

        if (showAllBtn) {
            if (matchedCount <= SCAN_TABLE_LIMIT) {
                showAllBtn.classList.add('hidden');
            } else {
                showAllBtn.classList.remove('hidden');
                showAllBtn.innerHTML = scanTableShowAll
                    ? '<i data-lucide="list-collapse" class="h-3.5 w-3.5"></i> Pokaż tylko TOP 10'
                    : '<i data-lucide="list-plus" class="h-3.5 w-3.5"></i> Pokaż wszystkie (' + matchedCount.toLocaleString('pl-PL') + ')';

                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                }
            }
        }
    }

    /**
     * Filtruje wiersze tabeli skanowania w czasie rzeczywistym.
     * Po wpisaniu frazy wraca do widoku TOP 10 wyników.
     */
    function filterScanTable() {
        scanTableShowAll = false;
        renderScanTable();
    }

    function toggleShowAllScans() {
        scanTableShowAll = !scanTableShowAll;
        renderScanTable();
    }

    /**
     * Pokazuje i ukrywa godzinowy heatmap/podgląd zdarzeń dla wybranego hosta.
     */
    function toggleScanHours(hoursId) {
        const hoursRow = document.getElementById(hoursId);
        if (hoursRow) {
            if (hoursRow.classList.contains('hidden')) {
                hoursRow.style.display = '';
                hoursRow.classList.remove('hidden');
                hoursRow.style.opacity = 0;
                setTimeout(() => {
                    hoursRow.style.transition = 'opacity 0.2s ease-in-out';
                    hoursRow.style.opacity = 1;
                }, 10);
            } else {
                hoursRow.classList.add('hidden');
                hoursRow.style.display = 'none';
            }
        }
    }

    /**
     * Rozwija i zwija szczegółowy panel korelacji zdarzeń dla wybranego agresora
     */
    function toggleScanDetails(detailId) {
        const detailRow = document.getElementById(detailId);
        if (detailRow) {
            if (detailRow.classList.contains('hidden')) {
                detailRow.style.display = '';
                detailRow.classList.remove('hidden');
                // Płynna animacja pojawiania się
                detailRow.style.opacity = 0;
                setTimeout(() => {
                    detailRow.style.transition = 'opacity 0.2s ease-in-out';
                    detailRow.style.opacity = 1;
                }, 10);
            } else {
                detailRow.classList.add('hidden');
                detailRow.style.display = 'none';
            }
        }
    }

    document.addEventListener('DOMContentLoaded', renderScanTable);
</script>
