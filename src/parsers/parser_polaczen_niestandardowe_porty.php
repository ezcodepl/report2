<?php
/**
 * Parser raportu Logsign:
 * Połączenia wychodzące z hostów wewnętrznych na niestandardowe porty hostów zewnętrznych.
 *
 * Obsługuje układ z tabelą nadrzędną hosta oraz zagnieżdżoną tabelą szczegółową:
 * Destination.Port, Bytes.*, Destination.IP, Destination.Country, Protocol.Name,
 * Application.Category, Application.Name, EventMap.Info, EventSource.Description, Time.Generated.
 */
class RaportWychodzaceNiestandardoweParser {
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
            'the netherlands' => '🇳🇱', 'netherlands' => '🇳🇱', 'holandia' => '🇳🇱',
            'australia' => '🇦🇺', 'brazil' => '🇧🇷', 'canada' => '🇨🇦',
            'france' => '🇫🇷', 'malaysia' => '🇲🇾', 'estonia' => '🇪🇪',
            'guatemala' => '🇬🇹', 'israel' => '🇮🇱', 'italy' => '🇮🇹',
            'reserved' => '🏳️', 'unknown' => '🏳️', 'nieznany' => '🏳️'
        ];
        return $countries[$countryName] ?? '🏳️';
    }

    private function normalizeText($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', "\r"], [' ', ' ', ''], $text);
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
        foreach ($xpath->query('./td | ./th', $row) as $cell) {
            $cells[] = $cell;
        }
        return $cells;
    }

    private function cellLines(DOMXPath $xpath, ?DOMNode $cell) {
        $lines = [];
        if (!$cell) return $lines;

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

    private function parseBytes($text) {
        $text = strtolower($this->normalizeText($text));
        $text = str_replace(',', '.', $text);
        if (!preg_match('/([0-9]+(?:\.[0-9]+)?)\s*([kmgt]?b)/i', $text, $m)) {
            return 0;
        }
        $value = (float)$m[1];
        $unit = strtolower($m[2]);
        $mul = 1;
        if ($unit === 'kb') $mul = 1024;
        elseif ($unit === 'mb') $mul = 1024 * 1024;
        elseif ($unit === 'gb') $mul = 1024 * 1024 * 1024;
        elseif ($unit === 'tb') $mul = 1024 * 1024 * 1024 * 1024;
        return (int)round($value * $mul);
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
        $headerRow = $xpath->query('./thead/tr[1] | .//thead/tr[1]', $table)->item(0);
        if (!$headerRow) return $map;
        $headers = $this->getDirectCells($xpath, $headerRow);
        foreach ($headers as $i => $th) {
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

    private function findSourceFromContext(DOMXPath $xpath, DOMNode $detailTable) {
        // 1) Najbliższy poprzedzający leaf td z IP, zwykle komórka grupująca Source.IP.
        $prevCells = $xpath->query('preceding::td[not(.//table)][position() <= 25]', $detailTable);
        for ($i = $prevCells->length - 1; $i >= 0; $i--) {
            $txt = $this->normalizeText($prevCells->item($i)->textContent);
            if (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/u', $txt, $m) && filter_var($m[0], FILTER_VALIDATE_IP)) {
                return $m[0];
            }
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

        $uniqueSources = [];
        $sourceCounts = [];

        $detailTables = $xpath->query('//table[.//th[contains(normalize-space(.), "Destination.Port")] and .//*[contains(normalize-space(.), "Bytes.Total")]]');

        foreach ($detailTables as $detailTable) {
            $headerMap = $this->findHeaderMap($xpath, $detailTable);
            if (empty($headerMap)) continue;

            $iPort = $this->idx($headerMap, ['Destination.Port']);
            $iBytesReceived = $this->idx($headerMap, ['Bytes.Received']);
            $iBytesSent = $this->idx($headerMap, ['Bytes.Sent']);
            $iBytesTotal = $this->idx($headerMap, ['Bytes.Total']);
            $iDestIp = $this->idx($headerMap, ['Destination.IP']);
            $iDestCountry = $this->idx($headerMap, ['Destination.Country']);
            $iUser = $this->idx($headerMap, ['Source.UserName']);
            $iProtocol = $this->idx($headerMap, ['Protocol.Name', 'Protocol']);
            $iCategory = $this->idx($headerMap, ['Application.Category']);
            $iApp = $this->idx($headerMap, ['Application.Name', 'Application']);
            $iEventInfo = $this->idx($headerMap, ['EventMap.Info (Term)', 'EventMap.Info']);
            $iEventDesc = $this->idx($headerMap, ['EventSource.Description']);
            $iSourceHost = $this->idx($headerMap, ['Source.HostName']);
            $iDestHost = $this->idx($headerMap, ['Destination.HostName']);
            $iTime = $this->idx($headerMap, ['Time.Generated']);

            $contextSourceIp = $this->findSourceFromContext($xpath, $detailTable);
            $bodyRows = $xpath->query('./tbody/tr', $detailTable);

            foreach ($bodyRows as $detailRow) {
                $cells = $this->getDirectCells($xpath, $detailRow);
                if (count($cells) < 4) continue;

                $portText = $this->getCellText($xpath, $cells[$iPort] ?? null);
                if ($portText === '') continue;

                $bytesReceivedText = $this->getCellText($xpath, $cells[$iBytesReceived] ?? null);
                $bytesSentText = $this->getCellText($xpath, $cells[$iBytesSent] ?? null);
                $bytesTotalText = $this->getCellText($xpath, $cells[$iBytesTotal] ?? null);
                $destIpText = $this->getCellText($xpath, $cells[$iDestIp] ?? null);
                $destCountryText = $this->getCellText($xpath, $cells[$iDestCountry] ?? null);
                $userText = $this->getCellText($xpath, $cells[$iUser] ?? null);
                $protocolText = $this->getCellText($xpath, $cells[$iProtocol] ?? null);
                $categoryText = $this->getCellText($xpath, $cells[$iCategory] ?? null);
                $appText = $this->getCellText($xpath, $cells[$iApp] ?? null);
                $eventInfoText = $this->getCellText($xpath, $cells[$iEventInfo] ?? null);
                $eventDescText = $this->getCellText($xpath, $cells[$iEventDesc] ?? null);
                $sourceHostText = $this->getCellText($xpath, $cells[$iSourceHost] ?? null);
                $destHostText = $this->getCellText($xpath, $cells[$iDestHost] ?? null);
                $timeText = $this->getCellText($xpath, $cells[$iTime] ?? null);

                $timeList = $this->extractTimeGeneratedList($xpath, $cells[$iTime] ?? null);
                $hourlyStats = $this->buildHourlyStats($timeList);

                $eventsCount = $this->extractCount($portText, 1);
                // W tym raporcie pełna liczba zdarzeń siedzi w Destination.Port: np. 24441 - (9).
                // Time.Generated często pokazuje tylko top wpisy, więc nie nadpisujemy licznika sumą timestampów.

                $sourceIp = $contextSourceIp;
                if ($sourceIp === '') {
                    $sourceHostClean = $this->cleanValue($sourceHostText, 'Host wewnętrzny');
                    $sourceIp = $sourceHostClean !== 'Host wewnętrzny' ? $sourceHostClean : 'Host wewnętrzny';
                }

                $destPort = $this->cleanValue($portText, 'Dowolny');
                $protocol = $this->cleanValue($protocolText, 'Nieznany');
                $category = $this->cleanValue($categoryText, 'Nieznana');
                $application = $this->cleanValue($appText, 'Nietypowy port');
                $destCountry = $this->cleanValue($destCountryText, 'Unknown');
                $eventInfo = $this->cleanValue($eventInfoText, 'Network Connection Allow');
                $eventDesc = $this->cleanValue($eventDescText, 'FG');
                $sourceHost = $this->cleanValue($sourceHostText, '');
                $destHost = $this->cleanValue($destHostText, '');
                $username = $this->cleanValue($userText, '');

                $bytesReceived = $this->parseBytes($bytesReceivedText);
                $bytesSent = $this->parseBytes($bytesSentText);
                $bytesTotal = $this->parseBytes($bytesTotalText);

                $uniqueKey = $sourceIp;
                $uniqueSources[$uniqueKey] = true;
                $sourceCounts[$uniqueKey] = ($sourceCounts[$uniqueKey] ?? 0) + $eventsCount;
                $data['meta']['suma_zdarzen'] += $eventsCount;

                $data['scans'][] = [
                    'source_ip' => $sourceIp,
                    'source_host' => $sourceHost,
                    'source_username' => $username,
                    'dest_ip' => $destIpText ?: 'Dowolny',
                    'dest_port' => $destPort,
                    'dest_country' => $destCountry,
                    'dest_country_raw' => $destCountryText,
                    'dest_host' => $destHost,
                    'protocol' => $protocol,
                    'service' => $category,
                    'application_category' => $category,
                    'application' => $application,
                    'bytes_received' => $bytesReceived,
                    'bytes_sent' => $bytesSent,
                    'bytes_total' => $bytesTotal,
                    'bytes_received_label' => $bytesReceivedText,
                    'bytes_sent_label' => $bytesSentText,
                    'bytes_total_label' => $bytesTotalText,
                    'source_country' => 'Poland',
                    'danger_level' => $eventsCount > 100 ? 'High' : ($eventsCount > 20 ? 'Medium' : 'Low'),
                    'events_count' => $eventsCount,
                    'event_info' => $eventInfo,
                    'event_desc' => 'Połączenia wychodzące na niestandardowy port docelowy ' . $destPort . '.',
                    'time_generated' => $timeText,
                    'time_generated_list' => $timeList,
                    'hourly_stats' => $hourlyStats,
                    'abuse_url' => 'https://www.abuseipdb.com/check/' . urlencode($this->cleanValue($destIpText, '')),
                    'virustotal_url' => 'https://www.virustotal.com/gui/ip-address/' . urlencode($this->cleanValue($destIpText, '')),
                    'whois_url' => 'https://www.whois.com/whois/' . urlencode($this->cleanValue($destIpText, '')),
                ];
            }
        }

        $seen = [];
        $deduped = [];
        $data['meta']['suma_zdarzen'] = 0;
        $sourceCounts = [];
        $uniqueSources = [];

        foreach ($data['scans'] as $scan) {
            $key = $scan['source_ip'] . '|' . $scan['dest_port'] . '|' . md5($scan['dest_ip'] . '|' . $scan['time_generated'] . '|' . $scan['bytes_total_label']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $deduped[] = $scan;
            $uniqueSources[$scan['source_ip']] = true;
            $sourceCounts[$scan['source_ip']] = ($sourceCounts[$scan['source_ip']] ?? 0) + (int)$scan['events_count'];
            $data['meta']['suma_zdarzen'] += (int)$scan['events_count'];
        }

        $data['scans'] = $deduped;
        $data['meta']['unikalne_ip'] = count($uniqueSources);
        if (!empty($sourceCounts)) {
            arsort($sourceCounts);
            $data['meta']['najbardziej_aktywny_ip'] = array_key_first($sourceCounts);
        }

        return $data;
    }
}
