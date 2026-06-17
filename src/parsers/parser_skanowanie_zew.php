<?php
/**
 * Parser raportu Logsign: hosty zewnętrzne skanujące porty.
 * Wersja pod układ raportu Source.IP -> tabela szczegółowa:
 * Destination.Port, EventMap.Info (Value), Destination.IP, Protocol.Name,
 * Service.Name, Application.Name, Source.Country, Destination.Country,
 * EventMap.Info, EventSource.Description, Time.Generated.
 *
 * Ważne:
 * - liczba zdarzeń pochodzi z EventMap.Info (Value), a dopiero fallback z Destination.Port,
 * - Time.Generated służy tylko do rozkładu godzinowego i NIE nadpisuje liczby zdarzeń,
 * - parser czyta wiele bloków Source.IP,
 * - nie miesza komórek głównych z komórkami tabel zagnieżdżonych.
 */
class RaportZewnSkanujaceParser {
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
            'australia' => '🇦🇺',
            'brazil' => '🇧🇷',
            'canada' => '🇨🇦',
            'france' => '🇫🇷',
            'malaysia' => '🇲🇾',
            'estonia' => '🇪🇪',
            'guatemala' => '🇬🇹',
            'israel' => '🇮🇱',
            'italy' => '🇮🇹',
            'reserved' => '🏳️',
            'unknown' => '🏳️',
            'nieznany' => '🏳️'
        ];
        return $countries[$countryName] ?? '🏳️';
    }

    private function normalizeText($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', 'Â '], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim($text);
    }

    private function cleanValue($text) {
        $text = $this->normalizeText($text);
        $firstLine = trim(explode("\n", $text)[0] ?? '');
        return trim(preg_replace('/\s*\([\d\s,]+\)\s*$/u', '', $firstLine));
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
                if ($txt !== '') {
                    $lines[] = $txt;
                }
            }
        } else {
            $txt = $this->normalizeText($cell->textContent);
            if ($txt !== '') {
                $lines[] = $txt;
            }
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

    private function extractEventValueCount($text, $fallback = 1) {
        $text = $this->normalizeText($text);
        if (preg_match('/^\s*([\d\s,]+)\s*$/u', $text, $m)) {
            return (int)str_replace([' ', ','], '', $m[1]);
        }
        if (preg_match('/\b([\d][\d\s,]*)\b/u', $text, $m)) {
            return (int)str_replace([' ', ','], '', $m[1]);
        }
        return $fallback;
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
            $name = $this->normalizeText($th->textContent);
            $name = preg_replace('/\s+/u', ' ', $name);
            if ($name !== '') {
                $map[$name] = $i;
            }
        }

        return $map;
    }

    private function idx(array $headerMap, array $needles) {
        foreach ($headerMap as $name => $i) {
            foreach ($needles as $needle) {
                if (stripos($name, $needle) !== false) {
                    return $i;
                }
            }
        }
        return null;
    }

    public function parse() {
        $data = [
            'meta' => [
                'nazwa_pliku' => $this->fileName,
                'suma_zdarzen' => 0,
                'unikalne_ip' => 0,
                'najbardziej_aktywny_ip' => 'Brak',
                'urzadzenie' => 'Logsign SIEM'
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

        // Szukamy komórek z IP, ale akceptujemy tylko te, których wiersz zawiera tabelę szczegółową z Time.Generated.
        // Dzięki temu Destination.IP nie zostanie błędnie uznane za Source.IP.
        $sourceCells = $xpath->query('//td[not(.//table)]');

        foreach ($sourceCells as $sourceCell) {
            $sourceText = $this->normalizeText($sourceCell->textContent);
            if (!preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $sourceText, $ipMatch)) {
                continue;
            }

            $sourceIp = $ipMatch[0];
            if (!filter_var($sourceIp, FILTER_VALIDATE_IP)) {
                continue;
            }

            $hostRow = $sourceCell->parentNode;
            if (!$hostRow) continue;

            $detailTables = $xpath->query('.//table[.//th[contains(normalize-space(.), "Time.Generated")]]', $hostRow);
            if ($detailTables->length === 0) {
                continue;
            }

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
                $iTime = $this->idx($headerMap, ['Time.Generated']);

                $bodyRows = $xpath->query('./tbody/tr', $detailTable);

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
                    $timeText = $this->getCellText($xpath, $cells[$iTime] ?? null);

                    $timeList = $this->extractTimeGeneratedList($xpath, $cells[$iTime] ?? null);
                    $hourlyStats = $this->buildHourlyStats($timeList);

                    $eventsCount = 1;
                    if ($eventValueText !== '') {
                        $eventsCount = $this->extractEventValueCount($eventValueText, 1);
                    } elseif ($portText !== '') {
                        $eventsCount = $this->extractCount($portText, 1);
                    }

                    $destPort = $this->cleanValue($portText) ?: 'Dowolny';
                    $sourceCountry = $this->cleanValue($sourceCountryText) ?: 'Unknown';
                    $destCountry = $this->cleanValue($destCountryText) ?: 'Unknown';
                    $protocol = $this->cleanValue($protocolText) ?: 'TCP';
                    $service = $this->cleanValue($serviceText) ?: 'Nieznana';
                    $app = $this->cleanValue($appText) ?: 'Skanowanie portów';
                    $eventInfo = $this->cleanValue($eventInfoText) ?: 'External Scan detected';
                    $eventDesc = $this->cleanValue($eventDescText) ?: 'Złośliwe próby skanowania portów z adresu zewnętrznego.';

                    $uniqueIps[$sourceIp] = true;
                    $ipCounts[$sourceIp] = ($ipCounts[$sourceIp] ?? 0) + $eventsCount;
                    $data['meta']['suma_zdarzen'] += $eventsCount;

                    $data['scans'][] = [
                        'source_ip' => $sourceIp,
                        'dest_ip' => $destIpText ?: 'Dowolny',
                        'dest_port' => $destPort,
                        'dest_port_raw' => $portText,
                        'protocol' => $protocol,
                        'protocol_raw' => $protocolText,
                        'service' => $service,
                        'service_raw' => $serviceText,
                        'application' => $app,
                        'application_raw' => $appText,
                        'source_country' => $sourceCountry,
                        'source_country_raw' => $sourceCountryText,
                        'dest_country' => $destCountry,
                        'dest_country_raw' => $destCountryText,
                        'event_value_count' => $eventsCount,
                        'event_value_raw' => $eventValueText,
                        'danger_level' => $eventsCount > 5000 ? 'Critical' : ($eventsCount > 1000 ? 'High' : 'Medium'),
                        'events_count' => $eventsCount,
                        'event_info' => $eventInfo,
                        'event_desc' => $eventDesc,
                        'time_generated' => $timeText,
                        'time_generated_list' => $timeList,
                        'hourly_stats' => $hourlyStats,
                        'abuse_url' => 'https://www.abuseipdb.com/check/' . urlencode($sourceIp),
                        'virustotal_url' => 'https://www.virustotal.com/gui/ip-address/' . urlencode($sourceIp),
                        'whois_url' => 'https://www.whois.com/whois/' . urlencode($sourceIp)
                    ];
                }
            }
        }

        // Dedup, gdyby raport miał zduplikowane bloki.
        $seen = [];
        $deduped = [];
        $data['meta']['suma_zdarzen'] = 0;
        $ipCounts = [];
        $uniqueIps = [];

        foreach ($data['scans'] as $scan) {
            $key = $scan['source_ip'] . '|' . $scan['dest_port'] . '|' . md5($scan['dest_ip'] . '|' . $scan['event_value_raw'] . '|' . $scan['time_generated']);
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
