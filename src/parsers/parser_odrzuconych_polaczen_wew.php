<?php
/**
 * Parser raportu Logsign: Odrzucone połączenia z hostów wewnętrznych.
 * Wersja poprawiona pod układ, gdzie KAŻDY REKORD jest osobną tabelą szczegółową.
 * Nie iteruje globalnie po wszystkich <td>, żeby nie mieszać Destination.IP z Source.IP.
 */
class RaportOdrzuconeWewnParser {
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
            'canada' => '🇨🇦',
            'united states' => '🇺🇸', 'usa' => '🇺🇸',
            'germany' => '🇩🇪', 'niemcy' => '🇩🇪',
            'russia' => '🇷🇺', 'rosja' => '🇷🇺',
            'china' => '🇨🇳', 'chiny' => '🇨🇳',
            'netherlands' => '🇳🇱', 'the netherlands' => '🇳🇱', 'holandia' => '🇳🇱',
            'france' => '🇫🇷', 'australia' => '🇦🇺', 'brazil' => '🇧🇷',
            'malaysia' => '🇲🇾', 'estonia' => '🇪🇪', 'guatemala' => '🇬🇹',
            'israel' => '🇮🇱', 'italy' => '🇮🇹',
            'reserved' => '🏳️', 'unknown' => '🏳️', 'nieznany' => '🏳️'
        ];
        return $countries[$countryName] ?? '🏳️';
    }

    private function normalizeText($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', 'Â '], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim($text);
    }

    private function cleanValue($text, $fallback = '') {
        $text = $this->normalizeText($text);
        $firstLine = trim(explode("\n", $text)[0] ?? '');
        $firstLine = trim(preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $firstLine));
        return $firstLine !== '' ? $firstLine : $fallback;
    }

    private function getDirectCells(DOMXPath $xpath, DOMNode $row) {
        $cells = [];
        $nodes = $xpath->query('./td | ./th', $row);
        if ($nodes === false) return $cells;
        foreach ($nodes as $cell) $cells[] = $cell;
        return $cells;
    }

    private function cellLines(DOMXPath $xpath, ?DOMNode $cell) {
        $lines = [];
        if (!$cell) return $lines;

        $leafTds = $xpath->query('.//td[not(.//table)]', $cell);
        if ($leafTds !== false && $leafTds->length > 0) {
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
        return $cell ? implode("\n", $this->cellLines($xpath, $cell)) : '';
    }

    private function extractCount($text, $default = 1) {
        if (preg_match('/\(([\d\s,]+)\)/u', (string)$text, $m)) {
            return (int)str_replace([' ', ','], '', $m[1]);
        }
        return $default;
    }

    private function extractTimeGeneratedList(DOMXPath $xpath, ?DOMNode $cell) {
        $list = [];
        foreach ($this->cellLines($xpath, $cell) as $line) {
            if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*\(([\d\s,]+)\)/u', $line, $m)) {
                $count = (int)str_replace([' ', ','], '', $m[2]);
                $list[] = [
                    'datetime' => $m[1],
                    'count' => $count,
                    'hour' => substr($m[1], 11, 2),
                ];
            }
        }
        return $list;
    }

    private function buildHourlyStats(array $timeList) {
        $hourly = array_fill(0, 24, 0);
        foreach ($timeList as $item) {
            $hour = (int)($item['hour'] ?? -1);
            if ($hour >= 0 && $hour <= 23) $hourly[$hour] += (int)($item['count'] ?? 0);
        }
        return $hourly;
    }

    private function findHeaderMap(DOMXPath $xpath, DOMNode $table) {
        $map = [];
        $headerRow = $xpath->query('./thead/tr[1]', $table);
        if ($headerRow === false || $headerRow->length === 0) return $map;
        foreach ($this->getDirectCells($xpath, $headerRow->item(0)) as $i => $th) {
            $name = $this->normalizeText($th->textContent);
            $name = preg_replace('/\s+/u', ' ', $name);
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

    private function normalizeHeaderName($name) {
        $name = $this->normalizeText($name);
        $name = preg_replace('/\s+/u', ' ', $name);
        return trim($name);
    }

    private function idxExact(array $headerMap, array $exactNames) {
        $wanted = [];
        foreach ($exactNames as $name) {
            $wanted[] = mb_strtolower($this->normalizeHeaderName($name), 'UTF-8');
        }
        foreach ($headerMap as $name => $i) {
            $normalized = mb_strtolower($this->normalizeHeaderName($name), 'UTF-8');
            if (in_array($normalized, $wanted, true)) return $i;
        }
        return null;
    }

    private function idxEventInfoTerm(array $headerMap) {
        $exact = $this->idxExact($headerMap, ['EventMap.Info (Term)', 'EventMap.Info Term']);
        if ($exact !== null) return $exact;
        foreach ($headerMap as $name => $i) {
            $normalized = mb_strtolower($this->normalizeHeaderName($name), 'UTF-8');
            if (strpos($normalized, 'eventmap.info') !== false && strpos($normalized, 'term') !== false) return $i;
        }
        return null;
    }

    private function isDetailTable(array $headerMap) {
        $hasDest = $this->idx($headerMap, ['Destination.IP']) !== null;
        $hasSourceCountry = $this->idx($headerMap, ['Source.Country']) !== null;
        $hasTime = $this->idx($headerMap, ['Time.Generated']) !== null;
        return $hasDest && $hasSourceCountry && $hasTime;
    }

    private function findSourceIpForRecordTable(DOMXPath $xpath, DOMNode $table) {
        // Rekord szczegółowy zwykle jest obok / po komórce Source.IP w bloku nadrzędnym.
        // Bierzemy najbliższe poprzedzające TD-liść z poprawnym IP, ale ignorujemy IP z tabel szczegółowych.
        $nodes = $xpath->query('preceding::td[not(.//table)]', $table);
        if ($nodes === false || $nodes->length === 0) return '';

        for ($i = $nodes->length - 1; $i >= 0; $i--) {
            $td = $nodes->item($i);
            $txt = $this->normalizeText($td->textContent);
            if (!preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $txt, $m)) continue;
            $ip = $m[0];
            if (!filter_var($ip, FILTER_VALIDATE_IP)) continue;

            // Jeżeli ten TD należy do tabeli szczegółowej z Destination.IP, pomijamy go,
            // bo to cel, nie Source.IP.
            $ancestorTables = $xpath->query('ancestor::table', $td);
            $insideDetail = false;
            if ($ancestorTables !== false) {
                foreach ($ancestorTables as $ancestorTable) {
                    $hm = $this->findHeaderMap($xpath, $ancestorTable);
                    if ($this->isDetailTable($hm)) {
                        $insideDetail = true;
                        break;
                    }
                }
            }
            if ($insideDetail) continue;

            return $ip;
        }
        return '';
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

        // Najważniejsze: każdy rekord to osobna tabela.
        // Nie filtrujemy po samym <th>, bo Logsign czasem robi nagłówki jako <td>.
        // Iterujemy po tabelach, budujemy mapę nagłówków i dopiero wtedy sprawdzamy,
        // czy to tabela rekordu: Destination.IP + Source.Country + Time.Generated.
        $tables = $xpath->query('//table[./thead/tr]');
        if ($tables === false) return $data;

        foreach ($tables as $detailTable) {
            $headerMap = $this->findHeaderMap($xpath, $detailTable);
            if (!$this->isDetailTable($headerMap)) continue;

            $sourceIp = $this->findSourceIpForRecordTable($xpath, $detailTable);
            if ($sourceIp === '') $sourceIp = 'Host wewnętrzny';

            $iDestIp = $this->idx($headerMap, ['Destination.IP']);
            $iEventValue = $this->idxExact($headerMap, ['EventMap.Info (Value)', 'EventMap.Info Value']);
            $iPosition = $this->idx($headerMap, ['Destination.Position']);
            $iPort = $this->idx($headerMap, ['Destination.Port']);
            $iService = $this->idx($headerMap, ['Service.Name', 'Service']);
            $iApp = $this->idx($headerMap, ['Application.Name', 'Application']);
            $iProtocol = $this->idx($headerMap, ['Protocol.Name', 'Protocol']);
            $iSourceCountry = $this->idx($headerMap, ['Source.Country']);
            $iDestCountry = $this->idxExact($headerMap, ['Destination.Country', 'Destination.Country (Term)', 'Destination.Country Term']);
            $iEventInfo = $this->idxEventInfoTerm($headerMap);
            $iEventDesc = $this->idx($headerMap, ['EventSource.Description']);
            $iSourceHost = $this->idx($headerMap, ['Source.HostName']);
            $iDestHost = $this->idx($headerMap, ['Destination.HostName']);
            $iTime = $this->idx($headerMap, ['Time.Generated']);

            $bodyRows = $xpath->query('./tbody/tr', $detailTable);
            if ($bodyRows === false || $bodyRows->length === 0) continue;

            foreach ($bodyRows as $detailRow) {
                $cells = $this->getDirectCells($xpath, $detailRow);
                if (count($cells) < 2) continue;

                $destIpText = $this->getCellText($xpath, $cells[$iDestIp] ?? null);
                $eventValueText = $this->getCellText($xpath, $cells[$iEventValue] ?? null);
                $positionText = $this->getCellText($xpath, $cells[$iPosition] ?? null);
                $portText = $this->getCellText($xpath, $cells[$iPort] ?? null);
                $serviceText = $this->getCellText($xpath, $cells[$iService] ?? null);
                $appText = $this->getCellText($xpath, $cells[$iApp] ?? null);
                $protocolText = $this->getCellText($xpath, $cells[$iProtocol] ?? null);
                $sourceCountryText = $this->getCellText($xpath, $cells[$iSourceCountry] ?? null);
                $destCountryText = $this->getCellText($xpath, $cells[$iDestCountry] ?? null);

                // Twardy fallback tylko dla Source.Country pod typowy układ Logsign:
                // 0 Destination.IP, 1 EventMap.Info (Value), 2 Destination.Position, 3 Destination.Port,
                // 4 Service.Name, 5 Application.Name, 6 Protocol.Name, 7 Source.Country (Term),
                // 8 EventMap.Info (Term). Nie wolno mapować kolumny 8 jako Destination.Country,
                // bo wtedy wartości typu "Network Connection Deny" trafiają do TOP Destination.Country.
                if (trim($sourceCountryText) === '' && isset($cells[7])) {
                    $sourceCountryText = $this->getCellText($xpath, $cells[7]);
                }
                $eventInfoText = $this->getCellText($xpath, $cells[$iEventInfo] ?? null);
                $eventDescText = $this->getCellText($xpath, $cells[$iEventDesc] ?? null);
                $sourceHostText = $this->getCellText($xpath, $cells[$iSourceHost] ?? null);
                $destHostText = $this->getCellText($xpath, $cells[$iDestHost] ?? null);
                $timeText = $this->getCellText($xpath, $cells[$iTime] ?? null);
                $timeList = $this->extractTimeGeneratedList($xpath, $cells[$iTime] ?? null);
                $hourlyStats = $this->buildHourlyStats($timeList);

                $eventsCount = 1;
                if ($eventValueText !== '' && preg_match('/\d+/', $eventValueText, $m)) {
                    $eventsCount = (int)$m[0];
                } elseif ($destIpText !== '') {
                    $eventsCount = $this->extractCount($destIpText, 1);
                } elseif ($portText !== '') {
                    $eventsCount = $this->extractCount($portText, 1);
                }

                $destIp = $this->cleanValue($destIpText, 'Dowolny');
                $destPort = $this->cleanValue($portText, 'Dowolny');
                // Source.Country (Term), np. "Poland (6051)\nCanada (1360)".
                // Widok TOP używa source_country_raw, a source_country to tylko pierwsza etykieta do tabeli.
                $sourceCountry = $this->cleanValue($sourceCountryText, 'Unknown');
                $destCountry = $this->cleanValue($destCountryText, 'Unknown');
                $protocol = $this->cleanValue($protocolText, 'Nieznany');
                $service = $this->cleanValue($serviceText, 'Nieznana');
                $app = $this->cleanValue($appText, 'Odrzucone połączenie');
                $position = $this->cleanValue($positionText, 'out');
                $eventInfo = $this->cleanValue($eventInfoText, 'Network Connection Deny');
                $eventSource = $this->cleanValue($eventDescText, 'FG');
                $sourceHost = $this->cleanValue($sourceHostText, '');
                $destHost = $this->cleanValue($destHostText, '');

                $data['scans'][] = [
                    'source_ip' => $sourceIp,
                    'dest_ip' => $destIp,
                    'dest_port' => $destPort,
                    'protocol' => $protocol,
                    'service' => $service,
                    'application' => $app,
                    'source_country' => $sourceCountry,
                    'source_country_raw' => $sourceCountryText,
                    'dest_country' => $destCountry,
                    'dest_country_raw' => $destCountryText,
                    'destination_position' => $position,
                    'danger_level' => $eventsCount > 5000 ? 'Critical' : ($eventsCount > 1000 ? 'High' : 'Medium'),
                    'events_count' => $eventsCount,
                    'event_info' => $eventInfo,
                    'event_desc' => 'Połączenie z hosta wewnętrznego zostało odrzucone przez regułę bezpieczeństwa.',
                    'event_source_description' => $eventSource,
                    'source_host' => $sourceHost,
                    'dest_host' => $destHost,
                    'time_generated' => $timeText,
                    'time_generated_list' => $timeList,
                    'hourly_stats' => $hourlyStats,
                    'abuse_url' => 'https://www.abuseipdb.com/check/' . urlencode($destIp),
                    'virustotal_url' => 'https://www.virustotal.com/gui/ip-address/' . urlencode($destIp),
                    'whois_url' => 'https://www.whois.com/whois/' . urlencode($destIp)
                ];

                if (filter_var($sourceIp, FILTER_VALIDATE_IP)) {
                    $uniqueIps[$sourceIp] = true;
                    $ipCounts[$sourceIp] = ($ipCounts[$sourceIp] ?? 0) + $eventsCount;
                }
                $data['meta']['suma_zdarzen'] += $eventsCount;
            }
        }

        // Dedup tylko po realnym rekordzie tabeli, bez wielokrotnego powielania rekordów.
        $seen = [];
        $deduped = [];
        $data['meta']['suma_zdarzen'] = 0;
        $uniqueIps = [];
        $ipCounts = [];

        foreach ($data['scans'] as $scan) {
            $key = $scan['source_ip'] . '|' . $scan['dest_ip'] . '|' . $scan['dest_port'] . '|' . md5($scan['time_generated'] . '|' . $scan['source_country_raw']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $deduped[] = $scan;
            if (filter_var($scan['source_ip'], FILTER_VALIDATE_IP)) {
                $uniqueIps[$scan['source_ip']] = true;
                $ipCounts[$scan['source_ip']] = ($ipCounts[$scan['source_ip']] ?? 0) + (int)$scan['events_count'];
            }
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
