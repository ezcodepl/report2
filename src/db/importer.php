<?php
require_once __DIR__ . '/../config/database.php';

function raport2_require_parsers(): void
{
    static $loaded = false;
    if ($loaded) return;
    $base = __DIR__ . '/../parsers/';
    foreach ([
        'parser.php',
        'parser_skanowanie_wew.php',
        'parser_host_logowanie.php',
        'parser_skanowanie_zew.php',
        'parser_odrzuconych_polaczen_wew.php',
        'parser_odrzucownych_polaczen_zew.php',
        'parser_polaczen_niestandardowe_porty.php',
        'parser_uzytkownicy_bledne_logowanie.php',
    ] as $file) {
        if (file_exists($base . $file)) require_once $base . $file;
    }
    $loaded = true;
}

function raport2_bootstrap_schema(): void
{
    $pdo = raport2_db();
    $schema = __DIR__ . '/schema.sql';
    if (!file_exists($schema)) return;
    $sql = file_get_contents($schema);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function raport2_detect_parser(string $fullPath): array
{
    raport2_require_parsers();
    $filename = basename($fullPath);
    if (mb_stripos($filename, 'transfer') !== false || mb_stripos($filename, 'transfe') !== false) {
        return ['transfer', class_exists('RaportParser') ? new RaportParser($fullPath, 'all') : null];
    }
    if (mb_stripos($filename, 'wewnetrzne_skanujace_porty') !== false) {
        return ['skanowanie_port_host_wew', class_exists('RaportWewnSkanujaceParser') ? new RaportWewnSkanujaceParser($fullPath) : null];
    }
    if (mb_stripos($filename, 'Hosty_z_bednymi_probami_logowania') !== false) {
        return ['host_logowanie', class_exists('RaportHostLogowanieParser') ? new RaportHostLogowanieParser($fullPath) : null];
    }
    if (mb_stripos($filename, 'zewnetrzne_skanujace_porty') !== false) {
        return ['skanowanie_port_host_zew', class_exists('RaportZewnSkanujaceParser') ? new RaportZewnSkanujaceParser($fullPath) : null];
    }
    if (mb_stripos($filename, 'Odrzucone_poaczenia_z_hostow_wewnetrznych') !== false) {
        return ['skanowanie_odrzucone_host_wew', class_exists('RaportOdrzuconeWewnParser') ? new RaportOdrzuconeWewnParser($fullPath) : null];
    }
    if (mb_stripos($filename, 'Odrzucone_poaczenia_z_hostow_zewnetrznych') !== false) {
        return ['skanowanie_odrzucone_host_zew', class_exists('RaportOdrzuconeZewnParser') ? new RaportOdrzuconeZewnParser($fullPath) : null];
    }
    if (mb_stripos($filename, 'Poaczenia_wychodzace') !== false) {
        return ['skanowanie_niestandardowe_porty', class_exists('RaportWychodzaceNiestandardoweParser') ? new RaportWychodzaceNiestandardoweParser($fullPath) : null];
    }
    if (mb_stripos($filename, 'Uzytkownicy_z_bednymi_probami_logowania') !== false) {
        return ['uzytkownicy_logowanie', class_exists('RaportBedneLogowaniaUzytkownicyParser') ? new RaportBedneLogowaniaUzytkownicyParser($fullPath) : null];
    }
    return ['unknown', null];
}

function raport2_clean(?string $value, ?string $fallback = null): ?string
{
    $value = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = str_replace(["\xC2\xA0", '&nbsp;', 'Â ', 'Â '], ' ', $value);
    $value = preg_replace('/\s+/u', ' ', trim($value));
    $value = preg_replace('/\s*[-–]?\s*\([\d\s,]+\)\s*$/u', '', $value);
    $value = trim($value, " \t\n\r\0\x0B-–—");
    return $value !== '' ? $value : $fallback;
}

function raport2_extract_count($value, int $default = 1): int
{
    if (preg_match('/\(([\d\s,]+)\)/u', (string)$value, $m)) {
        return max(1, (int)str_replace([' ', ','], '', $m[1]));
    }
    $digits = preg_replace('/\D+/', '', (string)$value);
    return $digits !== '' ? max(1, (int)$digits) : max(1, $default);
}

function raport2_first_time(array $item): array
{
    $datetime = null;
    $hour = null;
    $sources = [];
    foreach (['time_generated_list', 'time_raw'] as $key) {
        if (!empty($item[$key]) && is_array($item[$key])) $sources[] = $item[$key];
    }
    if (!empty($item['time_generated'])) $sources[] = [(string)$item['time_generated']];

    $flat = [];
    $walk = function ($v) use (&$walk, &$flat) {
        if (is_array($v)) { foreach ($v as $vv) $walk($vv); return; }
        if (is_scalar($v)) $flat[] = (string)$v;
    };
    foreach ($sources as $s) $walk($s);

    foreach ($flat as $line) {
        if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/u', $line, $m)) {
            $datetime = $m[1];
            $hour = (int)substr($datetime, 11, 2);
            break;
        }
    }
    return [$datetime, $hour];
}

function raport2_insert_event(PDO $pdo, array $row): void
{
    $columns = [
        'report_id','report_date','report_type','event_time','event_hour','events_count',
        'source_ip','source_host','source_user','source_country',
        'destination_ip','destination_host','destination_port','destination_country',
        'protocol_name','service_name','application_name','application_category',
        'event_subtype','event_info','event_description',
        'bytes_rx','bytes_tx','bytes_total','raw_payload'
    ];
    foreach ($columns as $col) if (!array_key_exists($col, $row)) $row[$col] = null;
    $row['events_count'] = max(1, (int)($row['events_count'] ?? 1));
    $row['bytes_rx'] = max(0, (int)($row['bytes_rx'] ?? 0));
    $row['bytes_tx'] = max(0, (int)($row['bytes_tx'] ?? 0));
    $row['bytes_total'] = max(0, (int)($row['bytes_total'] ?? 0));
    if (is_array($row['raw_payload'])) {
        $row['raw_payload'] = json_encode($row['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $sql = 'INSERT INTO report_events (' . implode(',', $columns) . ') VALUES (:' . implode(',:', $columns) . ')';
    $stmt = $pdo->prepare($sql);
    foreach ($columns as $col) $stmt->bindValue(':' . $col, $row[$col]);
    $stmt->execute();
}

function raport2_import_report_file(string $fullPath, ?string $originalName = null): array
{
    raport2_bootstrap_schema();
    $pdo = raport2_db();
    $checksum = hash_file('sha256', $fullPath);
    $stored = basename($fullPath);
    $date = basename(dirname($fullPath));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d', filemtime($fullPath));

    [$type, $parser] = raport2_detect_parser($fullPath);
    if (!$parser || !method_exists($parser, 'parse')) {
        return ['status' => 'failed', 'message' => 'Brak parsera dla pliku: ' . $stored];
    }

    $existing = $pdo->prepare('SELECT id FROM reports WHERE checksum = ? LIMIT 1');
    $existing->execute([$checksum]);
    if ($existing->fetchColumn()) {
        return ['status' => 'duplicate', 'message' => 'Raport juz istnieje w bazie: ' . $stored];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO reports (report_date, report_type, original_filename, stored_filename, file_path, checksum, parser_status) VALUES (?,?,?,?,?,?,\'pending\')');
        $stmt->execute([$date, $type, $originalName ?: $stored, $stored, str_replace(__DIR__ . '/../', '', $fullPath), $checksum]);
        $reportId = (int)$pdo->lastInsertId();

        $data = $parser->parse();
        $eventsTotal = 0;

        if ($type === 'transfer') {
            foreach (($data['top_hosts'] ?? []) as $host) {
                [$dt, $hour] = raport2_first_time($host);
                $count = (int)($host['zdarzenia'] ?? 1);
                $eventsTotal += max(1, $count);
                raport2_insert_event($pdo, [
                    'report_id' => $reportId,
                    'report_date' => $date,
                    'report_type' => $type,
                    'event_time' => $dt,
                    'event_hour' => $hour,
                    'events_count' => $count,
                    'source_ip' => raport2_clean($host['ip'] ?? null),
                    'source_host' => raport2_clean($host['opis'] ?? null),
                    'bytes_rx' => (int)round((float)($host['rx_raw'] ?? 0) * 1024 * 1024),
                    'bytes_tx' => (int)round((float)($host['tx_raw'] ?? 0) * 1024 * 1024),
                    'bytes_total' => (int)round((float)($host['suma_raw'] ?? 0) * 1024 * 1024),
                    'raw_payload' => $host,
                ]);
            }
        } elseif (!empty($data['scans']) && is_array($data['scans'])) {
            foreach ($data['scans'] as $scan) {
                [$dt, $hour] = raport2_first_time($scan);
                $count = (int)($scan['event_value_count'] ?? $scan['eventmap_value_count'] ?? $scan['events_count'] ?? 1);
                $eventsTotal += max(1, $count);
                raport2_insert_event($pdo, [
                    'report_id' => $reportId,
                    'report_date' => $date,
                    'report_type' => $type,
                    'event_time' => $dt,
                    'event_hour' => $hour,
                    'events_count' => $count,
                    'source_ip' => raport2_clean($scan['source_ip'] ?? null),
                    'source_host' => raport2_clean($scan['source_host'] ?? null),
                    'source_country' => raport2_clean($scan['source_country'] ?? null),
                    'destination_ip' => raport2_clean($scan['dest_ip'] ?? $scan['destination_ip'] ?? null),
                    'destination_port' => raport2_clean($scan['dest_port'] ?? $scan['destination_port'] ?? null),
                    'destination_country' => raport2_clean($scan['dest_country'] ?? $scan['destination_country'] ?? null),
                    'protocol_name' => raport2_clean($scan['protocol'] ?? $scan['protocol_name'] ?? null),
                    'service_name' => raport2_clean($scan['service'] ?? $scan['service_name'] ?? null),
                    'application_name' => raport2_clean($scan['application'] ?? $scan['application_name'] ?? null),
                    'application_category' => raport2_clean($scan['application_category'] ?? null),
                    'event_info' => raport2_clean($scan['event_info'] ?? null),
                    'event_description' => raport2_clean($scan['description'] ?? $scan['event_description'] ?? null),
                    'bytes_total' => (int)($scan['bytes_total'] ?? 0),
                    'raw_payload' => $scan,
                ]);
            }
        } elseif (!empty($data['records']) && is_array($data['records'])) {
            foreach ($data['records'] as $record) {
                [$dt, $hour] = raport2_first_time($record);
                $count = (int)($record['events_count'] ?? 1);
                $eventsTotal += max(1, $count);
                raport2_insert_event($pdo, [
                    'report_id' => $reportId,
                    'report_date' => $date,
                    'report_type' => $type,
                    'event_time' => $dt,
                    'event_hour' => $hour,
                    'events_count' => $count,
                    'source_ip' => raport2_clean($record['source_ip'] ?? null),
                    'source_host' => raport2_clean($record['source_host'] ?? null),
                    'source_user' => raport2_clean($record['user'] ?? $record['source_user'] ?? null),
                    'destination_ip' => raport2_clean($record['dest_ip'] ?? null),
                    'destination_host' => raport2_clean($record['dest_host'] ?? null),
                    'service_name' => raport2_clean($record['service_name'] ?? null),
                    'event_subtype' => raport2_clean($record['sub_type'] ?? null),
                    'event_description' => raport2_clean($record['description'] ?? null),
                    'raw_payload' => $record,
                ]);
            }
        }

        if ($eventsTotal <= 0 && isset($data['meta']['suma_zdarzen'])) $eventsTotal = (int)$data['meta']['suma_zdarzen'];
        if ($eventsTotal <= 0 && isset($data['meta']['liczba_zdarzen'])) $eventsTotal = (int)preg_replace('/\D+/', '', (string)$data['meta']['liczba_zdarzen']);

        $stmt = $pdo->prepare('UPDATE reports SET events_total = ?, parser_status = \'parsed\' WHERE id = ?');
        $stmt->execute([$eventsTotal, $reportId]);
        $pdo->commit();
        return ['status' => 'success', 'message' => 'Zaimportowano do MySQL: ' . $stored, 'report_id' => $reportId, 'events_total' => $eventsTotal];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try {
            $stmt = $pdo->prepare('INSERT INTO import_logs (source_file, status, message) VALUES (?, \'failed\', ?)');
            $stmt->execute([$stored, $e->getMessage()]);
        } catch (Throwable $ignore) {}
        return ['status' => 'failed', 'message' => $e->getMessage()];
    }
}
