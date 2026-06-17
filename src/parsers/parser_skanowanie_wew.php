<?php
/**
 * Parser raportu Logsign: Hosty wewnętrzne skanujące porty.
 * Poprawka: parser czyta rekordy per blok Source.IP i NIE bierze Destination.IP jako hosta źródłowego.
 */
class RaportWewnSkanujaceParser {
    private $filePath;
    private $fileName;

    public function __construct($filePath) {
        $this->filePath = $filePath;
        $this->fileName = basename($filePath);
    }

    public function getCountryFlag($countryName) {
        $countryName = trim(strtolower((string)$countryName));
        $countries = [
            'poland' => '🇵🇱', 'polska' => '🇵🇱',
            'united states' => '🇺🇸', 'usa' => '🇺🇸',
            'germany' => '🇩🇪', 'niemcy' => '🇩🇪',
            'russia' => '🇷🇺', 'rosja' => '🇷🇺',
            'china' => '🇨🇳', 'chiny' => '🇨🇳',
            'netherlands' => '🇳🇱', 'holandia' => '🇳🇱',
            'reserved' => '🏳️', 'unknown' => '🏳️'
        ];
        return $countries[$countryName] ?? '🏳️';
    }

    private function normalizeText($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim($text);
    }

    private function cleanValue($text) {
        $text = $this->normalizeText($text);
        $firstLine = trim(explode("\n", $text)[0] ?? '');
        return trim(preg_replace('/\s*\([\d\s,]+\)\s*$/u', '', $firstLine));
    }

    private function cleanPortValue($text) {
        $text = $this->normalizeText($text);
        $firstLine = trim(explode("\n", $text)[0] ?? '');
        if (preg_match('/^(\d+)/u', $firstLine, $m)) {
            return $m[1];
        }
        $clean = preg_replace('/\s*-\s*\([\d\s,]+\)\s*$/u', '', $firstLine);
        $clean = preg_replace('/\s*\([\d\s,]+\)\s*$/u', '', $clean);
        return trim($clean);
    }

    private function getDirectCells(DOMXPath $xpath, DOMNode $row) {
        $cells = [];
        foreach ($xpath->query('./td | ./th', $row) as $cell) {
            $cells[] = $cell;
        }
        return $cells;
    }

    private function cellLines(DOMXPath $xpath, DOMNode $cell) {
        $lines = [];
        $leafTds = $xpath->query('.//td[not(.//table)]', $cell);
        if ($leafTds->length > 0) {
            foreach ($leafTds as $td) {
                $txt = $this->normalizeText($td->textContent);
                if ($txt !== '') $lines[] = $txt;
            }
        } else {
            $txt = $this->normalizeText($cell->textContent);
            if ($txt !== '') $lines[] = $txt;
        }
        return $lines;
    }

    private function getCellText(DOMXPath $xpath, ?DOMNode $cell) {
        if (!$cell) return '';
        return implode("\n", $this->cellLines($xpath, $cell));
    }

    private function extractCount($text, $default = 1) {
        if (preg_match('/\(([\d\s,]+)\)/u', (string)$text, $m)) {
            return (int)str_replace([' ', ','], '', $m[1]);
        }
        return $default;
    }

    private function extractEventMapValueCount($text, $default = 1) {
        $text = $this->normalizeText($text);
        if (preg_match('/\b([\d\s,]+)\b/u', $text, $m)) {
            $count = (int)str_replace([' ', ','], '', $m[1]);
            if ($count > 0) return $count;
        }
        return $default;
    }

    private function extractTimeGeneratedList(DOMXPath $xpath, ?DOMNode $cell) {
        $list = [];
        if (!$cell) return $list;
        foreach ($this->cellLines($xpath, $cell) as $line) {
            if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*\(([\d\s,]+)\)/u', $line, $m)) {
                $count = (int)str_replace([' ', ','], '', $m[2]);
                $list[] = [
                    'datetime' => $m[1],
                    'count' => $count,
                    'hour' => substr($m[1], 11, 2),
                ];
            } elseif (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/u', $line, $m)) {
                $list[] = [
                    'datetime' => $m[1],
                    'count' => 1,
                    'hour' => substr($m[1], 11, 2),
                ];
            }
        }
        return $list;
    }

    private function buildHourlyStats(array $timeList) {
        $hourly = array_fill(0, 24, 0);
        foreach ($timeList as $item) {
            $hour = isset($item['hour']) ? (int)$item['hour'] : -1;
            if ($hour >= 0 && $hour <= 23) {
                $hourly[$hour] += (int)($item['count'] ?? 0);
            }
        }
        return $hourly;
    }

    private function findHeaderMap(DOMXPath $xpath, DOMNode $table) {
        $map = [];
        $headerRow = $xpath->query('./thead/tr[1]', $table)->item(0);
        if (!$headerRow) $headerRow = $xpath->query('.//thead/tr[1]', $table)->item(0);
        if (!$headerRow) return $map;
        foreach ($this->getDirectCells($xpath, $headerRow) as $i => $th) {
            $name = preg_replace('/\s+/u', ' ', $this->normalizeText($th->textContent));
            if ($name !== '') $map[$name] = $i;
        }
        return $map;
    }

    private function idx(array $headerMap, array $needles) {
        foreach ($headerMap as $name => $i) {
            foreach ($needles as $needle) {
                if (stripos($name, $needle) !== false) return $i;
            }
        }
        return null;
    }

    private function extractIpFromCell(DOMNode $cell) {
        $txt = $this->normalizeText($cell->textContent);
        if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $txt, $m) && filter_var($m[0], FILTER_VALIDATE_IP)) {
            return $m[0];
        }
        return '';
    }

    private function findDetailTablesNextToSource(DOMXPath $xpath, DOMNode $sourceCell, array $rowCells) {
        $tables = [];
        $sourceIndex = null;
        foreach ($rowCells as $i => $cell) {
            if ($cell->isSameNode($sourceCell)) { $sourceIndex = $i; break; }
        }
        if ($sourceIndex === null) return $tables;

        // Bardzo ważne: szukamy tabeli szczegółowej tylko w BEZPOŚREDNICH sąsiednich komórkach
        // tego samego wiersza nadrzędnego. Dzięki temu Destination.IP nie zostanie pomylony z Source.IP.
        for ($i = $sourceIndex + 1; $i < count($rowCells); $i++) {
            foreach ($xpath->query('.//table[.//th[contains(normalize-space(.), "Time.Generated")]]', $rowCells[$i]) as $table) {
                $tables[] = $table;
            }
        }
        return $tables;
    }

    public function parse() {
        $data = [
            'meta' => [
                'nazwa_pliku' => $this->fileName,
                'suma_zdarzen' => 0,
                'unikalne_ip' => 0,
                'najbardziej_aktywny_ip' => 'Brak',
                'urzadzenie' => 'FortiGate (FG)'
            ],
            'scans' => []
        ];

        if (!file_exists($this->filePath)) return $data;

        $htmlContent = file_get_contents($this->filePath);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $uniqueIps = [];
        $ipCounts = [];

        foreach ($xpath->query('//tr') as $hostRow) {
            $rowCells = $this->getDirectCells($xpath, $hostRow);
            if (count($rowCells) < 2) continue;

            foreach ($rowCells as $sourceCell) {
                // Komórka Source.IP ma być bezpośrednią komórką wiersza nadrzędnego.
                // Jeśli komórka sama zawiera tabelę, to nie jest Source.IP.
                if ($xpath->query('.//table', $sourceCell)->length > 0) continue;

                $sourceIp = $this->extractIpFromCell($sourceCell);
                if ($sourceIp === '') continue;

                $detailTables = $this->findDetailTablesNextToSource($xpath, $sourceCell, $rowCells);
                if (empty($detailTables)) continue;

                foreach ($detailTables as $detailTable) {
                    $headerMap = $this->findHeaderMap($xpath, $detailTable);
                    if (empty($headerMap)) continue;

                    $iPort = $this->idx($headerMap, ['Destination.Port']);
                    $iEventValue = $this->idx($headerMap, ['EventMap.Info (Value)', 'EventMap.Info Value']);
                    $iDestIp = $this->idx($headerMap, ['Destination.IP']);
                    $iProtocol = $this->idx($headerMap, ['Protocol.Name', 'Protocol']);
                    $iService = $this->idx($headerMap, ['Service.Name', 'Service']);
                    $iApp = $this->idx($headerMap, ['Application.Name', 'Application']);
                    $iSourceCountry = $this->idx($headerMap, ['Source.Country']);
                    $iDestCountry = $this->idx($headerMap, ['Destination.Country']);
                    $iEventInfo = $this->idx($headerMap, ['EventMap.Info (Term)', 'EventMap.Info']);
                    $iEventDesc = $this->idx($headerMap, ['EventSource.Description']);
                    $iSourceHost = $this->idx($headerMap, ['Source.HostName']);
                    $iDestHost = $this->idx($headerMap, ['Destination.HostName']);
                    $iTime = $this->idx($headerMap, ['Time.Generated']);

                    $bodyRows = $xpath->query('./tbody/tr', $detailTable);
                    if ($bodyRows->length === 0) $bodyRows = $xpath->query('.//tbody/tr', $detailTable);

                    foreach ($bodyRows as $detailRow) {
                        $cells = $this->getDirectCells($xpath, $detailRow);
                        if (count($cells) < 2) continue;

                        $portText = $this->getCellText($xpath, $cells[$iPort] ?? null);
                        $eventValueText = $this->getCellText($xpath, $cells[$iEventValue] ?? null);
                        $destIpText = $this->getCellText($xpath, $cells[$iDestIp] ?? null);
                        $protocolText = $this->getCellText($xpath, $cells[$iProtocol] ?? null);
                        $serviceText = $this->getCellText($xpath, $cells[$iService] ?? null);
                        $appText = $this->getCellText($xpath, $cells[$iApp] ?? null);
                        $sourceCountryText = $this->getCellText($xpath, $cells[$iSourceCountry] ?? null);
                        $destCountryText = $this->getCellText($xpath, $cells[$iDestCountry] ?? null);
                        $eventInfoText = $this->getCellText($xpath, $cells[$iEventInfo] ?? null);
                        $eventDescText = $this->getCellText($xpath, $cells[$iEventDesc] ?? null);
                        $sourceHostText = $this->getCellText($xpath, $cells[$iSourceHost] ?? null);
                        $destHostText = $this->getCellText($xpath, $cells[$iDestHost] ?? null);
                        $timeText = $this->getCellText($xpath, $cells[$iTime] ?? null);
                        $timeList = $this->extractTimeGeneratedList($xpath, $cells[$iTime] ?? null);
                        $hourlyStats = $this->buildHourlyStats($timeList);

                        // Pełna liczba zdarzeń jest w kolumnie EventMap.Info (Value).
                        // Destination.Port ma zwykle ten sam licznik w nawiasie, ale traktujemy go tylko jako fallback.
                        // Time.Generated to lista widocznych timestampów / bucketów i NIE jest pełnym licznikiem.
                        $eventMapValueCount = $this->extractEventMapValueCount($eventValueText, 0);
                        $portEventsCount = $this->extractCount($portText, 0);
                        $eventsCount = $eventMapValueCount > 0 ? $eventMapValueCount : ($portEventsCount > 0 ? $portEventsCount : 1);
                        // WAŻNE: EventMap.Info (Value) / Destination.Port zawiera PEŁNĄ liczbę zdarzeń.
                        // Time.Generated (Term) w raporcie Logsign jest listą TOP timestampów, więc często pokazuje
                        // tylko część zdarzeń. Nie wolno nadpisywać events_count sumą timestampów, bo wtedy
                        // 161 - (139) robi się np. 10, gdy w HTML widać tylko 10 timestampów po (1).
                        $timestampEventsCount = array_sum(array_column($timeList, 'count'));

                        $destPort = $this->cleanPortValue($portText) ?: 'Dowolny';
                        $destPortRaw = $this->normalizeText($portText);
                        $sourceCountry = $this->cleanValue($sourceCountryText) ?: 'Reserved';
                        $destCountry = $this->cleanValue($destCountryText) ?: 'Reserved';
                        $destCountryRaw = $this->normalizeText($destCountryText);
                        $protocol = $this->cleanValue($protocolText) ?: 'TCP';
                        $protocolRaw = $this->normalizeText($protocolText);
                        $service = $this->cleanValue($serviceText) ?: 'Nieznana';
                        $serviceRaw = $this->normalizeText($serviceText);
                        $app = $this->cleanValue($appText) ?: 'Skanowanie wewnętrzne';
                        $eventInfo = $this->cleanValue($eventInfoText) ?: 'Internal Port Scan';
                        $eventDesc = $this->cleanValue($eventDescText) ?: 'Wykryto skanowanie portów z sieci lokalnej';

                        $uniqueIps[$sourceIp] = true;
                        $ipCounts[$sourceIp] = ($ipCounts[$sourceIp] ?? 0) + $eventsCount;
                        $data['meta']['suma_zdarzen'] += $eventsCount;

                        $data['scans'][] = [
                            'source_ip' => $sourceIp,
                            'dest_ip' => $destIpText ?: 'Dowolny',
                            'dest_port' => $destPort,
                            'dest_port_raw' => $destPortRaw,
                            'protocol' => $protocol,
                            'service' => $service,
                            'application' => $app,
                            'source_country' => $sourceCountry,
                            'dest_country' => $destCountry,
                            'dest_country_raw' => $destCountryRaw,
                            'protocol_raw' => $protocolRaw,
                            'service_raw' => $serviceRaw,
                            'source_hostname' => $this->cleanValue($sourceHostText),
                            'destination_hostname' => $this->cleanValue($destHostText),
                            'danger_level' => $eventsCount > 5000 ? 'Critical' : ($eventsCount > 1000 ? 'High' : 'Medium'),
                            'events_count' => $eventsCount,
                            'eventmap_value' => $this->normalizeText($eventValueText),
                            'eventmap_value_count' => $eventMapValueCount,
                            'port_events_count' => $portEventsCount,
                            'event_info' => $eventInfo,
                            'event_desc' => $eventDesc,
                            'time_generated' => $timeText,
                            'time_generated_list' => $timeList,
                            'time_generated_visible_count' => $timestampEventsCount,
                            'hourly_stats' => $hourlyStats,
                            'abuse_url' => 'https://www.abuseipdb.com/check/' . urlencode($sourceIp),
                            'virustotal_url' => 'https://www.virustotal.com/gui/ip-address/' . urlencode($sourceIp),
                            'whois_url' => 'https://www.whois.com/whois/' . urlencode($sourceIp)
                        ];
                    }
                }
            }
        }

        // Dedup po właściwym źródle + porcie + czasie, bez mieszania Destination.IP jako Source.IP.
        $seen = [];
        $deduped = [];
        $data['meta']['suma_zdarzen'] = 0;
        $uniqueIps = [];
        $ipCounts = [];
        foreach ($data['scans'] as $scan) {
            $key = $scan['source_ip'] . '|' . $scan['dest_port'] . '|' . md5($scan['dest_ip'] . '|' . $scan['time_generated']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $deduped[] = $scan;
            $uniqueIps[$scan['source_ip']] = true;
            $ipCounts[$scan['source_ip']] = ($ipCounts[$scan['source_ip']] ?? 0) + (int)$scan['events_count'];
            $data['meta']['suma_zdarzen'] += (int)$scan['events_count'];
        }
        $data['scans'] = $deduped;
        $data['meta']['unikalne_ip'] = count($uniqueIps);
        if (!empty($ipCounts)) {
            arsort($ipCounts);
            $data['meta']['najbardziej_aktywny_ip'] = array_key_first($ipCounts);
        }

        return $data;
    }
}
