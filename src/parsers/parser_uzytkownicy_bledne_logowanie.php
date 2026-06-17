<?php
/**
 * Parser raportu Logsign: błędne próby logowania użytkowników.
 * Obsługiwane kolumny:
 * Source.UserName, Source.IP (Term), Source.HostName (Term), Destination.IP (Term),
 * Destination.HostName (Term), EventMap.SubType (Term), Time.Generated (Term),
 * EventSource.Description (Term), EventSource.IP (Term), Service.Name (Term).
 */
class RaportBedneLogowaniaUzytkownicyParser {
    private $filePath;
    private $fileName;

    public function __construct($filePath) {
        $this->filePath = $filePath;
        $this->fileName = basename($filePath);
    }

    private function normalizeText($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", '&nbsp;', 'Â '], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", $text);
        return trim($text);
    }

    private function cleanValue($text, $fallback = '-') {
        $text = $this->normalizeText($text);
        $firstLine = trim(explode("\n", $text)[0] ?? '');
        $firstLine = trim(preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $firstLine));
        return $firstLine !== '' ? $firstLine : $fallback;
    }

    private function extractCount($text, $default = 1) {
        if (preg_match('/\(([\d\s,]+)\)/u', (string)$text, $m)) {
            return max(1, (int)str_replace([' ', ','], '', $m[1]));
        }
        return max(1, (int)$default);
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

    private function extractTimeGeneratedList(DOMXPath $xpath, ?DOMNode $cell) {
        $list = [];
        foreach ($this->cellLines($xpath, $cell) as $line) {
            if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s*\(([\d\s,]+)\)/u', $line, $m)) {
                $count = (int)str_replace([' ', ','], '', $m[2]);
                $list[] = [
                    'datetime' => $m[1],
                    'count' => max(1, $count),
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
            $hour = (int)($item['hour'] ?? -1);
            if ($hour >= 0 && $hour <= 23) {
                $hourly[$hour] += (int)($item['count'] ?? 0);
            }
        }
        return $hourly;
    }

    private function findHeaderMap(DOMXPath $xpath, DOMNode $table) {
        $map = [];
        $headerRow = $xpath->query('./thead/tr[1] | .//thead/tr[1]', $table);
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

    private function isLoginTable(array $headerMap) {
        return $this->idx($headerMap, ['Source.UserName']) !== null
            && $this->idx($headerMap, ['Source.IP']) !== null
            && $this->idx($headerMap, ['Time.Generated']) !== null;
    }

    public function parse() {
        $data = [
            'meta' => [
                'nazwa_pliku' => $this->fileName,
                'suma_zdarzen' => 0,
                'unikalni_uzytkownicy' => 0,
                'unikalne_ip' => 0,
                'unikalne_hosty' => 0,
                'najaktywniejszy_user' => 'Brak',
                'najbardziej_aktywny_ip' => 'Brak',
                'urzadzenie' => 'Logsign SIEM'
            ],
            'records' => []
        ];

        if (!file_exists($this->filePath)) return $data;

        $htmlContent = file_get_contents($this->filePath);
        $htmlContent = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $htmlContent);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $uniqueUsers = [];
        $uniqueIps = [];
        $uniqueHosts = [];
        $userCounts = [];
        $ipCounts = [];

        $tables = $xpath->query('//table[.//th[contains(normalize-space(.), "Source.UserName")] or .//td[contains(normalize-space(.), "Source.UserName")]]');
        if ($tables === false) return $data;

        foreach ($tables as $table) {
            $headerMap = $this->findHeaderMap($xpath, $table);
            if (!$this->isLoginTable($headerMap)) continue;

            $iUser = $this->idx($headerMap, ['Source.UserName']);
            $iSourceIp = $this->idx($headerMap, ['Source.IP']);
            $iSourceHost = $this->idx($headerMap, ['Source.HostName']);
            $iDestIp = $this->idx($headerMap, ['Destination.IP']);
            $iDestHost = $this->idx($headerMap, ['Destination.HostName']);
            $iSubType = $this->idx($headerMap, ['EventMap.SubType', 'SubType']);
            $iTime = $this->idx($headerMap, ['Time.Generated']);
            $iDescription = $this->idx($headerMap, ['EventSource.Description']);
            $iEventSourceIp = $this->idx($headerMap, ['EventSource.IP']);
            $iService = $this->idx($headerMap, ['Service.Name']);

            $bodyRows = $xpath->query('./tbody/tr', $table);
            if ($bodyRows === false || $bodyRows->length === 0) {
                $bodyRows = $xpath->query('.//tbody/tr', $table);
            }
            if ($bodyRows === false) continue;

            foreach ($bodyRows as $row) {
                $cells = $this->getDirectCells($xpath, $row);
                if (count($cells) < 2) continue;

                $userRaw = $this->getCellText($xpath, $cells[$iUser] ?? null);
                $sourceIpRaw = $this->getCellText($xpath, $cells[$iSourceIp] ?? null);
                $sourceHostRaw = $this->getCellText($xpath, $cells[$iSourceHost] ?? null);
                $destIpRaw = $this->getCellText($xpath, $cells[$iDestIp] ?? null);
                $destHostRaw = $this->getCellText($xpath, $cells[$iDestHost] ?? null);
                $subTypeRaw = $this->getCellText($xpath, $cells[$iSubType] ?? null);
                $timeRaw = $this->getCellText($xpath, $cells[$iTime] ?? null);
                $descriptionRaw = $this->getCellText($xpath, $cells[$iDescription] ?? null);
                $eventSourceIpRaw = $this->getCellText($xpath, $cells[$iEventSourceIp] ?? null);
                $serviceRaw = $this->getCellText($xpath, $cells[$iService] ?? null);

                $user = $this->cleanValue($userRaw, '-');
                $sourceIp = $this->cleanValue($sourceIpRaw, '-');
                $sourceHost = $this->cleanValue($sourceHostRaw, '-');
                $destIp = $this->cleanValue($destIpRaw, '-');
                $destHost = $this->cleanValue($destHostRaw, '-');
                $subType = $this->cleanValue($subTypeRaw, '-');
                $description = $this->cleanValue($descriptionRaw, '-');
                $eventSourceIp = $this->cleanValue($eventSourceIpRaw, '-');
                $service = $this->cleanValue($serviceRaw, '-');

                $timeList = $this->extractTimeGeneratedList($xpath, $cells[$iTime] ?? null);
                $hourlyStats = $this->buildHourlyStats($timeList);

                // Liczba prób logowania pochodzi z Time.Generated (Term),
                // a nie z liczby wierszy ani z losowych/demo danych.
                // Każdy wpis typu 2026-05-23 22:00:33 (10) dodaje 10 prób.
                $sumTime = !empty($timeList) ? array_sum(array_column($timeList, 'count')) : 0;
                if ($sumTime > 0) {
                    $events = (int)$sumTime;
                } else {
                    // Fallback tylko dla raportów bez pełnego Time.Generated.
                    $events = $this->extractCount($userRaw, 1);
                    if ($events <= 1) $events = $this->extractCount($sourceIpRaw, 1);
                    if ($events <= 1) $events = $this->extractCount($sourceHostRaw, 1);
                }

                if ($user === '-' && $sourceIp === '-' && $sourceHost === '-' && $timeRaw === '') continue;

                $record = [
                    'user' => $user,
                    'user_raw' => $userRaw,
                    'source_ip' => $sourceIp,
                    'source_ip_raw' => $sourceIpRaw,
                    'source_host' => $sourceHost,
                    'source_host_raw' => $sourceHostRaw,
                    'dest_ip' => $destIp,
                    'dest_ip_raw' => $destIpRaw,
                    'dest_host' => $destHost,
                    'dest_host_raw' => $destHostRaw,
                    'sub_type' => $subType,
                    'sub_type_raw' => $subTypeRaw,
                    'time_generated' => $timeRaw,
                    'time_generated_list' => $timeList,
                    'hourly_stats' => $hourlyStats,
                    'description' => $description,
                    'description_raw' => $descriptionRaw,
                    'event_source_ip' => $eventSourceIp,
                    'event_source_ip_raw' => $eventSourceIpRaw,
                    'service_name' => $service,
                    'service_name_raw' => $serviceRaw,
                    'events_count' => $events
                ];

                $data['records'][] = $record;
                $data['meta']['suma_zdarzen'] += $events;

                if ($user !== '-') { $uniqueUsers[$user] = true; $userCounts[$user] = ($userCounts[$user] ?? 0) + $events; }
                if ($sourceIp !== '-') { $uniqueIps[$sourceIp] = true; $ipCounts[$sourceIp] = ($ipCounts[$sourceIp] ?? 0) + $events; }
                if ($sourceHost !== '-') { $uniqueHosts[$sourceHost] = true; }
            }
        }

        $data['meta']['unikalni_uzytkownicy'] = count($uniqueUsers);
        $data['meta']['unikalne_ip'] = count($uniqueIps);
        $data['meta']['unikalne_hosty'] = count($uniqueHosts);
        if (!empty($userCounts)) { arsort($userCounts); $data['meta']['najaktywniejszy_user'] = array_key_first($userCounts); }
        if (!empty($ipCounts)) { arsort($ipCounts); $data['meta']['najbardziej_aktywny_ip'] = array_key_first($ipCounts); }

        return $data;
    }
}
