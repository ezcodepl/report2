<?php
/**
 * Parser: Odrzucone połączenia z hostów zewnętrznych.
 * Każdy rekord raportu traktowany jest jako osobna tabela szczegółowa.
 */
class RaportOdrzuconeZewnParser {
    private string $filePath;
    private string $fileName;

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
            'netherlands' => '🇳🇱', 'the netherlands' => '🇳🇱',
            'france' => '🇫🇷', 'australia' => '🇦🇺', 'brazil' => '🇧🇷',
            'canada' => '🇨🇦', 'malaysia' => '🇲🇾', 'estonia' => '🇪🇪',
            'guatemala' => '🇬🇹', 'israel' => '🇮🇱', 'italy' => '🇮🇹',
            'reserved' => '🏳️', 'unknown' => '🏳️', 'nieznany' => '🏳️'
        ];
        return $countries[$countryName] ?? '🏳️';
    }

    private function normalizeText($text): string {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', 'Â '], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim($text);
    }

    private function cleanValue($text, $fallback = ''): string {
        $text = $this->normalizeText($text);
        $first = trim(explode("\n", $text)[0] ?? '');
        $first = preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $first);
        $first = trim((string)$first);
        return $first !== '' ? $first : $fallback;
    }

    private function getDirectCells(DOMXPath $xpath, DOMNode $row): array {
        $cells = [];
        $nodeList = $xpath->query('./td | ./th', $row);
        if ($nodeList === false) return $cells;
        foreach ($nodeList as $cell) $cells[] = $cell;
        return $cells;
    }

    private function cellLines(DOMXPath $xpath, ?DOMNode $cell): array {
        if (!$cell) return [];
        $lines = [];
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

    private function getCellText(DOMXPath $xpath, ?DOMNode $cell): string {
        return implode("\n", $this->cellLines($xpath, $cell));
    }

    private function extractCount($text, $default = 1): int {
        if (preg_match('/\(([\d\s,]+)\)/u', (string)$text, $m)) {
            return (int)str_replace([' ', ','], '', $m[1]);
        }
        return $default;
    }

    private function extractTimeGeneratedList(DOMXPath $xpath, ?DOMNode $cell): array {
        $list = [];
        foreach ($this->cellLines($xpath, $cell) as $line) {
            if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*\(([\d\s,]+)\)/u', $line, $m)) {
                $list[] = [
                    'datetime' => $m[1],
                    'count' => (int)str_replace([' ', ','], '', $m[2]),
                    'hour' => substr($m[1], 11, 2),
                ];
            }
        }
        return $list;
    }

    private function buildHourlyStats(array $timeList): array {
        $hourly = array_fill(0, 24, 0);
        foreach ($timeList as $item) {
            $h = (int)($item['hour'] ?? -1);
            if ($h >= 0 && $h <= 23) $hourly[$h] += (int)($item['count'] ?? 0);
        }
        return $hourly;
    }

    private function findHeaderMap(DOMXPath $xpath, DOMNode $table): array {
        $map = [];
        $headerRow = $xpath->query('./thead/tr[1] | .//thead/tr[1]', $table);
        if ($headerRow === false || $headerRow->length === 0) return $map;
        foreach ($this->getDirectCells($xpath, $headerRow->item(0)) as $i => $th) {
            $name = preg_replace('/\s+/u', ' ', $this->normalizeText($th->textContent));
            if ($name !== '') $map[$name] = $i;
        }
        return $map;
    }

    private function idx(array $headerMap, array $needles): ?int {
        foreach ($headerMap as $name => $i) {
            foreach ($needles as $needle) {
                if (stripos($name, $needle) !== false) return $i;
            }
        }
        return null;
    }

    private function isDetailTable(array $headerMap): bool {
        // Rekord zewnętrzny musi mieć kraj źródłowy i czas. Source.IP bywa w osobnej komórce/bloku,
        // więc nie wymagamy go twardo, żeby nie wyciąć poprawnych rekordów.
        return $this->idx($headerMap, ['Source.Country']) !== null
            && $this->idx($headerMap, ['Time.Generated']) !== null;
    }

    private function findSourceIpForTable(DOMXPath $xpath, DOMNode $table): string {
        $node = $table;
        for ($i = 0; $i < 6 && $node; $i++, $node = $node->parentNode) {
            $prevText = $this->normalizeText($node->textContent ?? '');
            if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $prevText, $m) && filter_var($m[0], FILTER_VALIDATE_IP)) {
                return $m[0];
            }
        }
        $prev = $xpath->query('preceding::td[not(.//table)][contains(., ".")][1]', $table);
        if ($prev !== false && $prev->length > 0) {
            $txt = $this->normalizeText($prev->item(0)->textContent);
            if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $txt, $m) && filter_var($m[0], FILTER_VALIDATE_IP)) return $m[0];
        }
        return '';
    }

    public function parse(): array {
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

        $html = file_get_contents($this->filePath);
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $tables = $xpath->query('//table[.//thead/tr]');
        if ($tables === false) return $data;

        $uniqueIps = [];
        $ipCounts = [];
        $seen = [];

        foreach ($tables as $table) {
            $headerMap = $this->findHeaderMap($xpath, $table);
            if (!$headerMap || !$this->isDetailTable($headerMap)) continue;

            $iSourceIp = $this->idx($headerMap, ['Source.IP']);
            $iDestIp = $this->idx($headerMap, ['Destination.IP']);
            $iEventValue = $this->idx($headerMap, ['EventMap.Info (Value)', 'EventMap.Info Value']);
            $iPosition = $this->idx($headerMap, ['Destination.Position']);
            $iPort = $this->idx($headerMap, ['Destination.Port']);
            $iService = $this->idx($headerMap, ['Service.Name', 'Service']);
            $iApp = $this->idx($headerMap, ['Application.Name', 'Application']);
            $iProtocol = $this->idx($headerMap, ['Protocol.Name', 'Protocol']);
            $iSourceCountry = $this->idx($headerMap, ['Source.Country']);
            $iDestCountry = $this->idx($headerMap, ['Destination.Country']);
            $iEventInfo = $this->idx($headerMap, ['EventMap.Info (Term)', 'EventMap.Info']);
            $iEventDesc = $this->idx($headerMap, ['EventSource.Description']);
            $iSourceHost = $this->idx($headerMap, ['Source.HostName']);
            $iDestHost = $this->idx($headerMap, ['Destination.HostName']);
            $iTime = $this->idx($headerMap, ['Time.Generated']);

            $bodyRows = $xpath->query('./tbody/tr', $table);
            if ($bodyRows === false || $bodyRows->length === 0) continue;

            foreach ($bodyRows as $row) {
                $cells = $this->getDirectCells($xpath, $row);
                if (count($cells) < 2) continue;

                $sourceIpText = $this->getCellText($xpath, $cells[$iSourceIp] ?? null);
                $sourceIp = '';
                if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $sourceIpText, $m)) $sourceIp = $m[0];
                if (!$sourceIp) $sourceIp = $this->findSourceIpForTable($xpath, $table);
                if (!$sourceIp || !filter_var($sourceIp, FILTER_VALIDATE_IP)) continue;

                $destIpText = $this->getCellText($xpath, $cells[$iDestIp] ?? null);
                $eventValueText = $this->getCellText($xpath, $cells[$iEventValue] ?? null);
                $positionText = $this->getCellText($xpath, $cells[$iPosition] ?? null);
                $portText = $this->getCellText($xpath, $cells[$iPort] ?? null);
                $serviceText = $this->getCellText($xpath, $cells[$iService] ?? null);
                $appText = $this->getCellText($xpath, $cells[$iApp] ?? null);
                $protocolText = $this->getCellText($xpath, $cells[$iProtocol] ?? null);
                $sourceCountryText = $this->getCellText($xpath, $cells[$iSourceCountry] ?? null);
                $destCountryText = $this->getCellText($xpath, $cells[$iDestCountry] ?? null);

                // Fallback pod typowy układ tabeli zewnętrznej Logsign:
                // 0 Source.IP, 1 Destination.IP, 2 EventMap.Info, 3 Destination.Position,
                // 4 Destination.Port, 5 Service.Name, 6 Application.Name,
                // 7 Protocol.Name, 8 Source.Country, 9 Destination.Country.
                if (trim($sourceCountryText) === '' && isset($cells[8])) {
                    $sourceCountryText = $this->getCellText($xpath, $cells[8]);
                }
                if (trim($destCountryText) === '' && isset($cells[9])) {
                    $destCountryText = $this->getCellText($xpath, $cells[9]);
                }

                $eventInfoText = $this->getCellText($xpath, $cells[$iEventInfo] ?? null);
                $eventDescText = $this->getCellText($xpath, $cells[$iEventDesc] ?? null);
                $sourceHostText = $this->getCellText($xpath, $cells[$iSourceHost] ?? null);
                $destHostText = $this->getCellText($xpath, $cells[$iDestHost] ?? null);
                $timeText = $this->getCellText($xpath, $cells[$iTime] ?? null);
                $timeList = $this->extractTimeGeneratedList($xpath, $cells[$iTime] ?? null);

                $eventsCount = 1;
                if ($eventValueText !== '' && preg_match('/\d+/', $eventValueText, $m)) $eventsCount = (int)$m[0];
                elseif ($destIpText !== '') $eventsCount = $this->extractCount($destIpText, 1);
                elseif ($portText !== '') $eventsCount = $this->extractCount($portText, 1);

                $destIp = $this->cleanValue($destIpText, 'Dowolny');
                $destPort = $this->cleanValue($portText, 'Dowolny');
                $sourceCountry = $this->cleanValue($sourceCountryText, 'Unknown');
                $destCountry = $this->cleanValue($destCountryText, 'Unknown');
                $protocol = $this->cleanValue($protocolText, 'Nieznany');
                $service = $this->cleanValue($serviceText, 'Nieznana');
                $app = $this->cleanValue($appText, 'Odrzucone połączenie');
                $position = $this->cleanValue($positionText, 'in');
                $eventInfo = $this->cleanValue($eventInfoText, 'Network Connection Deny');
                $eventSource = $this->cleanValue($eventDescText, 'FG');
                $sourceHost = $this->cleanValue($sourceHostText, '');
                $destHost = $this->cleanValue($destHostText, '');

                $key = $sourceIp . '|' . $destIp . '|' . $destPort . '|' . md5($timeText);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;

                $uniqueIps[$sourceIp] = true;
                $ipCounts[$sourceIp] = ($ipCounts[$sourceIp] ?? 0) + $eventsCount;
                $data['meta']['suma_zdarzen'] += $eventsCount;

                $data['scans'][] = [
                    'source_ip' => $sourceIp,
                    'dest_ip' => $destIp,
                    'dest_port' => $destPort,
                    'protocol' => $protocol,
                    'service' => $service,
                    'application' => $app,
                    'application_raw' => $appText,
                    'source_country' => $sourceCountry,
                    'source_country_raw' => $sourceCountryText,
                    'dest_country' => $destCountry,
                    'dest_country_raw' => $destCountryText,
                    'destination_position' => $position,
                    'danger_level' => $eventsCount > 5000 ? 'Critical' : ($eventsCount > 1000 ? 'High' : 'Medium'),
                    'events_count' => $eventsCount,
                    'event_info' => $eventInfo,
                    'event_desc' => 'Połączenie z hosta zewnętrznego zostało odrzucone przez regułę bezpieczeństwa.',
                    'event_source_description' => $eventSource,
                    'source_host' => $sourceHost,
                    'dest_host' => $destHost,
                    'time_generated' => $timeText,
                    'time_generated_list' => $timeList,
                    'hourly_stats' => $this->buildHourlyStats($timeList),
                    'abuse_url' => 'https://www.abuseipdb.com/check/' . urlencode($sourceIp),
                    'virustotal_url' => 'https://www.virustotal.com/gui/ip-address/' . urlencode($sourceIp),
                    'whois_url' => 'https://www.whois.com/whois/' . urlencode($sourceIp)
                ];
            }
        }

        $data['meta']['unikalne_ip'] = count($uniqueIps);
        if ($ipCounts) {
            arsort($ipCounts);
            $data['meta']['najbardziej_aktywny_ip'] = array_key_first($ipCounts);
        }
        return $data;
    }
}
