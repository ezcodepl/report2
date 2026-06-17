<!-- ========================================== -->
<!-- WIDOK: ANALITYKA TRANSFERU DOBOWEGO       -->
<!-- ========================================== -->
<div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-8">
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Suma Transferu</p>
                <h3 class="mt-2 text-2xl font-bold text-slate-900"><?php echo htmlspecialchars($parsedData['meta']['suma_transferu']?? ''); ?></h3>
            </div>
            <div class="rounded-xl bg-blue-50 p-3 text-blue-600">
                <i data-lucide="arrow-left-right" class="h-6 w-6"></i>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Dane Pobrane (RX)</p>
                <h3 class="mt-2 text-2xl font-bold text-emerald-600"><?php echo htmlspecialchars($parsedData['meta']['pobrane_rx']?? ''); ?></h3>
            </div>
            <div class="rounded-xl bg-emerald-50 p-3 text-emerald-600">
                <i data-lucide="download-cloud" class="h-6 w-6"></i>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Dane Wysłane (TX)</p>
                <h3 class="mt-2 text-2xl font-bold text-amber-600"><?php echo htmlspecialchars($parsedData['meta']['wyslane_tx']?? ''); ?></h3>
            </div>
            <div class="rounded-xl bg-amber-50 p-3 text-amber-600">
                <i data-lucide="upload-cloud" class="h-6 w-6"></i>
            </div>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Liczba Zdarzeń (Suma)</p>
                <h3 class="mt-2 text-2xl font-bold text-violet-600"><?php echo htmlspecialchars($parsedData['meta']['liczba_zdarzen']?? ''); ?></h3>
            </div>
            <div class="rounded-xl bg-violet-50 p-3 text-violet-600">
                <i data-lucide="activity" class="h-6 w-6"></i>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center gap-2 mb-6">
            <span class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>
            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Top 5 Najaktywniejszych Hostów</h3>
        </div>
        <div class="space-y-4">
            <?php
            $top5Hosts = array_slice($parsedData['top_hosts'], 0, 5);
            $maxHostTransfer = 0.001;
            foreach ($top5Hosts as $h) {
                $mb = convertToMb($h['suma']);
                if ($mb > $maxHostTransfer) $maxHostTransfer = $mb;
            }

            if (empty($top5Hosts)): ?>
                <p class="text-xs text-slate-400 font-semibold py-4 text-center">Brak danych</p>
            <?php else: ?>
                <?php foreach ($top5Hosts as $h):
                    $currentMb = convertToMb($h['suma'] ?? 0);
                    $percent = $maxHostTransfer > 0 ? min(100, ($currentMb / $maxHostTransfer) * 100) : 0;
                    $ip = trim(preg_replace('/\s*\([^)]*\)/', '', $h['ip'] ?? '')) ?? 'unknown';
                    $opis = $h['opis'] ?? '';
                    $displayName = $ip;
                ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                            <span class="font-mono text-slate-900 truncate max-w-[150px] inline-block" title="<?php echo htmlspecialchars($displayName); ?>"><?php echo htmlspecialchars($displayName); ?></span><span><?php echo htmlspecialchars($opis); ?></span>
                            <span class="text-blue-600 font-bold"><?php echo htmlspecialchars($h['suma']?? 0); ?></span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-blue-500 to-indigo-600 rounded-full transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center gap-2 mb-6">
            <span class="h-2.5 w-2.5 rounded-full bg-emerald-600"></span>
            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Top 5 Kierunków Docelowych</h3>
        </div>
        <div class="space-y-4">
            <?php
            $top5Kierunki = array_slice($parsedData['selected_host']['kierunki'] ?? [], 0, 5);
            if (empty($top5Kierunki)): ?>
                <div class="flex flex-col items-center justify-center py-8 text-slate-400">
                    <i data-lucide="info" class="h-8 w-8 mb-2 opacity-60"></i>
                    <span class="text-xs font-semibold">Brak danych docelowych IP</span>
                </div>
            <?php else: ?>
                <?php foreach ($top5Kierunki as $k): ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                             <a href="<?php echo $k['whois_url']; ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline flex items-center gap-1">
                                    <?php echo htmlspecialchars($k['ip']); ?>
                                    <i data-lucide="external-link" class="h-3 w-3 opacity-60"></i>
                                </a>
                            <span class="text-emerald-600 font-bold"><?php echo htmlspecialchars($k['zdarzenia']); ?></span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-emerald-500 to-teal-600 rounded-full transition-all duration-500" style="width: <?php echo $k['procent']; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center gap-2 mb-6">
            <span class="h-2.5 w-2.5 rounded-full bg-rose-600"></span>
            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Top 5 Krajów ze Zdarzeniami</h3>
        </div>
        <div class="space-y-4">
            <?php
            $top5Kraje = array_slice($parsedData['selected_host']['geolokalizacja'] ?? [], 0, 5);
            if (empty($top5Kraje)): ?>
                <div class="flex flex-col items-center justify-center py-8 text-slate-400">
                    <i data-lucide="globe" class="h-8 w-8 mb-2 opacity-60"></i>
                    <span class="text-xs font-semibold">Brak danych</span>
                </div>
            <?php else: ?>
                <?php foreach ($top5Kraje as $k): ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                            <span>
                                <span class="text-slate-400 uppercase text-[10px] mr-1"><?php echo htmlspecialchars($k['prefiks']); ?></span>
                                <?php echo htmlspecialchars($k['kraj']); ?>
                            </span>
                            <span class="text-rose-600 font-bold"><?php echo htmlspecialchars($k['logi']); ?></span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-rose-500 to-red-600 rounded-full transition-all duration-500" style="width: <?php echo $k['procent']; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="flex items-center gap-2 mb-6">
            <span class="h-2.5 w-2.5 rounded-full bg-amber-600"></span>
            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide">Top 5 Usług i Aplikacji</h3>
        </div>
        <div class="space-y-4">
            <?php
            $top5Uslugi = array_slice($parsedData['selected_host']['uslugi'] ?? [], 0, 5);
            if (empty($top5Uslugi)): ?>
                <div class="flex flex-col items-center justify-center py-8 text-slate-400">
                    <i data-lucide="layers" class="h-8 w-8 mb-2 opacity-60"></i>
                    <span class="text-xs font-semibold">Brak danych</span>
                </div>
            <?php else: ?>
                <?php foreach ($top5Uslugi as $u): ?>
                    <div>
                        <div class="flex justify-between text-xs font-semibold text-slate-700 mb-1">
                            <span class="rounded bg-slate-100 px-2 py-0.5 font-bold text-slate-700 font-mono text-[11px]"><?php echo htmlspecialchars($u['nazwa']); ?></span>
                            <span class="text-amber-600 font-bold"><?php echo htmlspecialchars($u['zdarzenia']); ?></span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-amber-500 to-orange-600 rounded-full transition-all duration-500" style="width: <?php echo $u['procent']; ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tabela Hostów -->
<div class="mb-8 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center mb-6">
        <div>
            <h3 class="text-base font-bold text-slate-950 flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-blue-600"></span>
                Top Hosty o Największym Transferze Dobowym
            </h3>
            <p class="text-xs text-slate-400 mt-1">Lista najaktywniejszych adresów IP na podstawie dobowego transferu danych.</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                    <th class="py-3 px-4 text-center">Pozycja</th>
                    <th class="py-3 px-4">Adres IP źródłowy (Host)</th>
                    <th class="py-3 px-4 text-right">Zdarzenia</th>
                    <th class="py-3 px-4 text-right text-emerald-600 font-bold">Odebrane (RX)</th>
                    <th class="py-3 px-4 text-right text-amber-600 font-bold">Wysłane (TX)</th>
                    <th class="py-3 px-4 text-right font-bold">Łącznie (Suma)</th>
                    <th class="py-3 px-4">Wykorzystanie Pasma</th>
                    <th class="py-3 px-4 text-center">Akcja</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50 text-xs">
                <?php foreach ($parsedData['top_hosts'] as $index => $host): ?>
                    <tr class="host-row hover:bg-slate-50/50 transition-colors <?php echo $index >= 5 ? 'hidden' : ''; ?>">
                        <td class="py-3 px-4 text-center">
                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full <?php echo $host['pozycja'] <= 3 ? 'bg-amber-50 text-amber-700 font-bold border border-amber-200' : 'bg-slate-100 text-slate-600'; ?>">
                                <?php echo $host['pozycja']; ?>
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <div class="font-bold text-slate-900 font-mono"><?php echo htmlspecialchars($host['ip']); ?></div>
                            <div class="text-[10px] text-slate-400 font-medium"><?php echo htmlspecialchars($host['opis']); ?></div>
                        </td>
                        <td class="py-3 px-4 text-right font-semibold text-slate-600"><?php echo htmlspecialchars($host['zdarzenia']); ?></td>
                        <td class="py-3 px-4 text-right font-bold text-emerald-600"><?php echo htmlspecialchars($host['rx']); ?></td>
                        <td class="py-3 px-4 text-right font-bold text-amber-500"><?php echo htmlspecialchars($host['tx']); ?></td>
                        <td class="py-3 px-4 text-right font-bold text-slate-900"><?php echo htmlspecialchars($host['suma']); ?></td>
                        <td class="py-3 px-4 w-44">
                            <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full bg-blue-600 rounded-full" style="width: <?php echo $host['procent_pasma']; ?>%"></div>
                            </div>
                        </td>
                        <td class="py-3 px-4 text-center">
                            <a href="index.php?file=<?php echo urlencode($selectedFile); ?>&filter_day=<?php echo urlencode($filterDay); ?>&active_ip=<?php echo urlencode($host['ip']); ?>" class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-600 shadow-3xs hover:bg-slate-50 hover:text-blue-600 hover:border-blue-200 transition-all">
                                <i data-lucide="eye" class="h-3.5 w-3.5"></i>
                                Analizuj
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (count($parsedData['top_hosts']) > 5): ?>
        <div class="mt-4 text-center border-t border-slate-100 pt-4">
            <button id="btn-show-more" onclick="showAllHostsRows()" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-bold text-slate-600 shadow-xs hover:bg-slate-50 hover:text-blue-600 transition-all">
                <i data-lucide="chevrons-down" class="h-4 w-4"></i>
                Pokaż więcej (<?php echo count($parsedData['top_hosts']) - 5; ?>)
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- KARTA ANALITYCZNA Z TRANSFERU DOBOWEGO -->
<div class="mb-8 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
    <div class="flex items-center justify-between border-b border-slate-100 pb-4 mb-6">
        <div>
            <h3 class="text-base font-bold text-slate-950">Karta Analityczna Wybranego Hosta</h3>
            <p class="text-xs text-slate-400 mt-1">Szczegółowa korelacja ruchu, lokalizacji docelowych oraz rozkładu czasowego dla wybranego IP.</p>
        </div>
        <span class="rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-600">Wybrany: <?php echo htmlspecialchars($parsedData['selected_host']['ip'] ?? ''); ?></span>
    </div>

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <div class="lg:border-r lg:border-slate-100 lg:pr-8 flex flex-col justify-between">
            <div class="space-y-5">
                <div class="leading-tight">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Identyfikacja Hosta</span>
                        <span class="rounded-md bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-blue-600">Lokalny IP</span>
                    </div>
                    <div class="mt-2">
                        <h4 class="text-xl font-bold text-slate-900 font-mono"><?php echo htmlspecialchars($parsedData['selected_host']['ip'] ?? ''); ?></h4>
                        <p class="text-xs font-semibold text-slate-600"><?php echo htmlspecialchars($parsedData['selected_host']['nazwa'] ?? ''); ?></p>
                        <span class="text-[11px] text-slate-400"><?php echo htmlspecialchars($parsedData['selected_host']['domena'] ?? ''); ?></span>
                    </div>
                </div>

                <div>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-2">Transfer</span>
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-xs font-semibold mb-1">
                                <span class="flex items-center gap-2 text-slate-600">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span> Pobieranie
                                </span>
                                <span class="text-emerald-600 font-bold"><?php echo number_format($parsedData['selected_host']['rx_raw'] ?? 0, 1) . ' MB'; ?></span>
                            </div>
                            <?php
                            $rx = (float)($parsedData['selected_host']['rx_raw'] ?? 0);
                            $tx = (float)($parsedData['selected_host']['tx_raw'] ?? 0);
                            $total = $rx + $tx;
                            $total = $total > 0 ? $total : 1;
                            $rxPercent = ($rx / $total) * 100;
                            $txPercent = ($tx / $total) * 100;
                            ?>
                            <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full bg-emerald-500 rounded-full" style="width: <?php echo round($rxPercent, 1); ?>%"></div>
                            </div>
                        </div>

                        <div>
                            <div class="flex justify-between text-xs font-semibold mb-1">
                                <span class="flex items-center gap-2 text-slate-600">
                                    <span class="h-2 w-2 rounded-full bg-amber-500"></span> Wysyłanie
                                </span>
                                <span class="text-amber-600 font-bold"><?php echo number_format($parsedData['selected_host']['tx_raw'] ?? 0, 1) . ' MB'; ?></span>
                            </div>
                            <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full bg-amber-500 rounded-full" style="width: <?php echo round($txPercent, 1); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-slate-100 text-xs text-slate-500 space-y-2">
                    <div class="flex justify-between">
                        <span>Suma transferu:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($parsedData['selected_host']['suma'] ?? '0 MB'); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span>Zdarzenia:</span>
                        <span class="font-bold text-slate-800"><?php echo htmlspecialchars($parsedData['selected_host']['zdarzenia'] ?? '0'); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span>System:</span>
                        <span class="font-semibold text-blue-600 flex items-center gap-1">
                            <i data-lucide="shield" class="h-3 w-3"></i>
                            <?php echo htmlspecialchars($parsedData['meta']['urzadzenie']); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="lg:border-r lg:border-slate-100 lg:px-4">
            <div class="mb-4">
                <div class="flex items-center justify-between">
                    <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Kierunki Docelowe (IP)</span>
                </div>
                <p class="text-[10px] text-slate-400 mt-1">Kliknij w adres IP, aby sprawdzić informacje w serwisie WHOIS.</p>
            </div>
            <div class="space-y-2 max-h-64 overflow-y-auto pr-1 mb-6">
                <?php if (empty($parsedData['selected_host']['kierunki'])): ?>
                    <div class="flex flex-col items-center justify-center py-12 text-slate-400 border border-dashed border-slate-150 rounded-2xl bg-slate-50/50">
                        <i data-lucide="info" class="h-8 w-8 mb-2 opacity-60"></i>
                        <span class="text-xs font-semibold">Brak danych</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($parsedData['selected_host']['kierunki'] as $kierunek): ?>
                        <div class="text-xs py-1.5 border-b border-slate-50 font-mono">
                            <div class="flex items-center justify-between font-bold mb-1">
                                <a href="<?php echo $kierunek['whois_url']; ?>" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline flex items-center gap-1">
                                    <?php echo htmlspecialchars($kierunek['ip']); ?>
                                    <i data-lucide="external-link" class="h-3 w-3 opacity-60"></i>
                                </a>
                                <span class="text-slate-700"><?php echo htmlspecialchars($kierunek['zdarzenia']); ?></span>
                            </div>
                            <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full" style="width: <?php echo $kierunek['procent']; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="border-t border-slate-100 pt-4">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-3">Rozpoznane Aplikacje</span>
                <div class="space-y-3">
                    <?php if (empty($parsedData['selected_host']['aplikacje'])): ?>
                        <div class="flex flex-col items-center justify-center py-6 text-slate-400 border border-dashed border-slate-150 rounded-2xl bg-slate-50/50">
                            <i data-lucide="layers" class="h-8 w-8 mb-2 opacity-60"></i>
                            <span class="text-xs font-semibold">Brak danych</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($parsedData['selected_host']['aplikacje'] as $ap): ?>
                            <div>
                                <div class="flex justify-between text-xs font-semibold mb-1">
                                    <span class="rounded bg-slate-100 px-2 py-0.5 font-bold text-slate-700 font-mono text-[11px]"><?php echo htmlspecialchars($ap['nazwa']); ?></span>
                                    <span class="text-slate-600 font-bold"><?php echo htmlspecialchars($ap['zdarzenia']); ?></span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-amber-500 to-orange-600 rounded-full transition-all duration-500" style="width: <?php echo $ap['procent']; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="lg:pl-4 space-y-6">
            <div>
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-3">Geolokalizacja (Kraje)</span>
                <div class="space-y-3">
                    <?php if (empty($parsedData['selected_host']['geolokalizacja'])): ?>
                        <div class="flex flex-col items-center justify-center py-6 text-slate-400 border border-dashed border-slate-150 rounded-2xl bg-slate-50/50">
                            <i data-lucide="globe" class="h-8 w-8 mb-2 opacity-60"></i>
                            <span class="text-xs font-semibold">Brak danych</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($parsedData['selected_host']['geolokalizacja'] as $krajData): ?>
                            <div>
                                <div class="flex justify-between text-xs font-semibold mb-1">
                                    <span class="text-slate-600 font-bold">
                                        <span class="text-slate-400 font-normal uppercase mr-1"><?php echo htmlspecialchars($krajData['prefiks']); ?></span>
                                        <?php echo htmlspecialchars($krajData['kraj']); ?>
                                    </span>
                                    <span class="text-slate-800 font-bold"><?php echo htmlspecialchars($krajData['logi']); ?></span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-rose-500 to-red-600 rounded-full transition-all duration-500" style="width: <?php echo $krajData['procent']; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="border-t border-slate-100 pt-4">
                <span class="text-[10px] font-bold uppercase tracking-wider text-slate-400 block mb-3">Rozpoznane Usługi (Protokoły)</span>
                <div class="space-y-3">
                    <?php if (empty($parsedData['selected_host']['uslugi'])): ?>
                        <div class="flex flex-col items-center justify-center py-6 text-slate-400 border border-dashed border-slate-150 rounded-2xl bg-slate-50/50">
                            <i data-lucide="layers" class="h-8 w-8 mb-2 opacity-60"></i>
                            <span class="text-xs font-semibold">Brak danych</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($parsedData['selected_host']['uslugi'] as $usluga): ?>
                            <div>
                                <div class="flex justify-between text-xs font-semibold mb-1">
                                    <span class="rounded bg-slate-100 px-2 py-0.5 font-bold text-slate-700 font-mono text-[11px]"><?php echo htmlspecialchars($usluga['nazwa']); ?></span>
                                    <span class="text-slate-600 font-bold"><?php echo htmlspecialchars($usluga['zdarzenia']); ?></span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                                    <div class="h-full bg-indigo-500 rounded-full" style="width: <?php echo $usluga['procent']; ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ROZKŁAD CZASOWY ZDARZEŃ (HEATMAPA GODZINOWA) -->
<div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
    <h3 class="text-base font-bold text-slate-950 mb-4">
        Rozkład czasowy zdarzeń (Aktywność dobowo-godzinowa)
    </h3>
    <div class="grid grid-cols-4 gap-3 sm:grid-cols-6 md:grid-cols-8 lg:grid-cols-12">
        <?php
        $hours = $parsedData['selected_host']['rozkład_godzinowy'] ?? [];
        $logiArray = array_column($hours, 'logi');
        $maxLogi = !empty($logiArray) ? max($logiArray) : 1;

        foreach ($hours as $godzina):
            $logCount = intval($godzina['logi']);
            $intensity = $maxLogi > 0 ? ($logCount / $maxLogi) * 100 : 0;

            if ($intensity >= 85) {
                $bgClass = 'bg-blue-900 text-white border-blue-950';
            } elseif ($intensity >= 70) {
                $bgClass = 'bg-blue-700 text-white border-blue-800';
            } elseif ($intensity >= 50) {
                $bgClass = 'bg-blue-500 text-white border-blue-600';
            } elseif ($intensity >= 30) {
                $bgClass = 'bg-blue-300 text-blue-950 border-blue-400';
            } elseif ($intensity >= 15) {
                $bgClass = 'bg-blue-100 text-blue-900 border-blue-200';
            } else {
                $bgClass = 'bg-slate-50 text-slate-500 border-slate-200';
            }
        ?>
            <div class="rounded-xl p-3 text-center border shadow-sm transition-all duration-200 hover:scale-105 flex flex-col justify-between items-center min-h-[75px] <?php echo $bgClass; ?>">
                <span class="text-[9px] font-semibold uppercase tracking-wider opacity-85">
                    <?php echo htmlspecialchars($godzina['godzina']); ?>
                </span>
                <span class="text-xs font-bold mt-1">
                    <?php echo htmlspecialchars($godzina['logi']); ?> zd.
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
