<?php

class RaportParser {

    private $filePath;
    private $filterDay;

    public function __construct($filePath = null, $filterDay = 'all') {
        $this->filePath = $filePath;
        $this->filterDay = $filterDay;
    }

    private function formatTransfer($mb)
    {
        $mb = (float)$mb;

        if ($mb >= 1000000) {
            return number_format($mb / 1000000, 2) . ' TB';
        }

        if ($mb >= 1000) {
            return number_format($mb / 1000, 2) . ' GB';
        }

        return number_format($mb, 1) . ' MB';
    }
  
    public function parse() {

        if (!$this->filePath || !file_exists($this->filePath)) {
            return $this->getEmptyResponse();
        }

        $html = @file_get_contents($this->filePath);

        if (empty($html)) {
            return $this->getEmptyResponse();
        }

        // usuwanie komentarzy
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/^\s*\/\/.*$/m', '', $html);

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();

        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $rows = $xpath->query('//tr[contains(@class, "table_tbody")]');

        $hosts = [];

        $sumRx = 0;
        $sumTx = 0;
        $sumAll = 0;
        $sumEvents = 0;

        foreach ($rows as $index => $row) {
             $events = []; 
            $cells = $row->getElementsByTagName('td');
            

            if ($cells->length < 10) {
                continue;
            }
            // DEBUG
   

            $ip = trim($cells->item(0)->textContent);

            $rx = (float)$this->cleanValue($cells->item(1)->textContent);
            $tx = (float)$this->cleanValue($cells->item(2)->textContent);
            $total = (float)$this->cleanValue($cells->item(3)->textContent);
            
            $hostnameRaw = trim($cells->item(5)->textContent);
            $hostname = preg_replace('/\s*\([0-9]+\)$/', '', $hostnameRaw);

            if (empty($hostname)) {
                $hostname = 'Brak nazwy (DHCP)';
            }

           $ipRaw = trim(html_entity_decode($cells->item(0)->textContent));

            preg_match('/^(.*?)\s*\(([0-9 ,]+)\)/', $ipRaw, $match);

            $ip = trim($match[1] ?? $ipRaw);

            $eventCount = (int)str_replace(
                [' ', ','],
                '',
                $match[2] ?? '0'
            );

            $countries = $this->extractComplexList($cells->item(8));
            $services = $this->extractComplexList($cells->item(9));
            $apps = $this->extractComplexList($cells->item(10));
            $destIps = $this->extractComplexList($cells->item(6));
            $timeGenerated = $this->extractComplexList($cells->item(12));
             

           

            foreach ($timeGenerated as $timeEntry) {

                $timeEntry = trim(preg_replace('/\s+/', ' ', $timeEntry));

                preg_match(
                    '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}).*?\((\d+)\)/',
                    $timeEntry,
                    $timeMatch
                );

                $time = trim($timeMatch[1] ?? '');
                $count = (int)($timeMatch[2] ?? 1);

                for ($i = 0; $i < $count; $i++) {

                    $events[] = [
                        'time' => $time
                    ];
                }
            }

            $sumRx += $rx;
            $sumTx += $tx;
            $sumAll += $total;
            $sumEvents += $eventCount;

            $hosts[] = [

                'pozycja' => $index + 1,

                'ip' => $ip,

                'opis' => $hostname,

                 'zdarzenia' => $eventCount, //'zdarzenia' => number_format($eventCount, 0, ' ', ' '),

                'rx' => $this->formatTransfer($rx),

                'tx' => $this->formatTransfer($tx),

                'suma' => $this->formatTransfer($total),

                'rx_raw' => $rx,

                'tx_raw' => $tx,

                'suma_raw' => $total,

                'procent_pasma' => 0,

                'kierunki' => $this->buildDirections($destIps),

                'geolokalizacja' => $this->buildCountries($countries),
                'time_raw' => $this->extractComplexList($cells->item(12)),
                'rozkład_godzinowy' => $this->buildHours($selectedHost['events'] ?? []),

                'events' => $events,

                'uslugi' => $this->buildServices($services),
                
                
                'aplikacje' => $this->buildServices($apps)
            ];
        }

        // sortowanie po transferze
        usort($hosts, function($a, $b) {
            return $b['suma_raw'] <=> $a['suma_raw'];
        });

        $max = 1;

        foreach ($hosts as $h) {
            if ($h['suma_raw'] > $max) {
                $max = $h['suma_raw'];
            }
        }

        foreach ($hosts as $k => $h) {

            $hosts[$k]['pozycja'] = $k + 1;

            $hosts[$k]['procent_pasma'] = round(
                ($h['suma_raw'] / $max) * 100,
                2
            );
        }

        $selectedHost = $hosts[0] ?? [];
//         echo '<pre>';
// var_dump($selectedHost['events'] ?? null);
// echo '</pre>';
// $test = $this->buildHours($selectedHost['events'] ?? []);
// var_dump($test);
// exit;

        if (!empty($_GET['active_ip'])) {

            foreach ($hosts as $h) {

                if ($h['ip'] === $_GET['active_ip']) {
                    $selectedHost = $h;
                    break;
                }
            }
        }
        

        return [

            'top_hosts' => $hosts,

            'selected_host' => [

                'ip' => $selectedHost['ip'] ?? '',
                'nazwa' => $selectedHost['opis'] ?? '',
                'domena' => 'DNS w DHCP',

                // HOST LEVEL
                'rx' => $selectedHost['rx'] ?? '0 MB',
                'tx' => $selectedHost['tx'] ?? '0 MB',
                'suma' => $selectedHost['suma'] ?? '0 MB',

                'rx_raw' => $selectedHost['rx_raw'] ?? 0,
                'tx_raw' => $selectedHost['tx_raw'] ?? 0,

                'zdarzenia' => $selectedHost['zdarzenia'] ?? 0,

                'kierunki' => $selectedHost['kierunki'] ?? [],
                'geolokalizacja' => $selectedHost['geolokalizacja'] ?? [],
                'uslugi' => $selectedHost['uslugi'] ?? [],
                'time_raw' => $selectedHost['time_raw'] ?? [],
                'rozkład_godzinowy' => $this->buildHours($selectedHost['events'] ?? []),
                'aplikacje' => $selectedHost['aplikacje'] ?? []
            ],

           

            'meta' => [

                'suma_transferu' => $this->formatTransfer($sumAll),

                'pobrane_rx' => $this->formatTransfer($sumRx),

                'wyslane_tx' => $this->formatTransfer($sumTx),

                'liczba_zdarzen' => number_format($sumEvents, 0, ',', ' '),

                'urzadzenie' => 'FortiGate (FG)',

                'available_days' => [
                    'all' => 'Łącznie (3 dni)',
                    '15' => '15 maj',
                    '16' => '16 maj',
                    '17' => '17 maj'
                ]
            ]
        ];
        
    }

//     private function buildDirections($ips) {

//         $out = [];

//         foreach ($ips as $ip) {

//             $out[] = [

//                 'ip' => $ip,

//                 'zdarzenia' => rand(50, 15000),

//                 'procent' => rand(10, 100),

//                 'whois_url' => 'https://www.whois.com/whois/' . urlencode($ip)
//             ];
//         }

//         return $out;
//     }

//     private function buildCountries($countries) {

//         $out = [];

//         foreach ($countries as $country) {

//             $parts = explode(' ', $country, 2);

//             $out[] = [

//                 'prefiks' => strtoupper(substr($country, 0, 2)),

//                 'kraj' => $country,

//                 'logi' => rand(50, 30000),

//                 'procent' => rand(10, 100)
//             ];
//         }

//         return $out;
//     }
// private function buildServices($services)
// {
//     $out = [];

//     foreach ($services as $service) {

//         preg_match('/^(.*?)\s*\((\d+)\)$/', $service, $match);

//         $nazwa = trim($match[1] ?? $service);
//         $zdarzenia = (int)($match[2] ?? 0);

//         $out[] = [
//             'nazwa' => $nazwa,
//             'zdarzenia' => $zdarzenia
//         ];
//     }

//     // suma wszystkich zdarzeń
//     $total = array_sum(array_column($out, 'zdarzenia'));

//     // procenty
//     foreach ($out as &$item) {
//         $item['procent'] = $total > 0
//             ? round(($item['zdarzenia'] / $total) * 100, 1)
//             : 0;
//     }

//     // sortowanie malejąco
//     usort($out, function ($a, $b) {
//         return $b['zdarzenia'] <=> $a['zdarzenia'];
//     });

//     return $out;
// }

//     private function buildHours() {

//         $hours = [];

//         for ($i = 0; $i < 8; $i++) {

//             $hours[] = [

//                 'godzina' => date('m-d H:i', strtotime("+$i hour")),

//                 'logi' => rand(1, 15)
//             ];
//         }

//         return $hours;
//     }

//     private function extractComplexList($tdNode) {

//         if (!$tdNode) {
//             return [];
//         }

//         $listItems = $tdNode->getElementsByTagName('li');

//         if ($listItems->length > 0) {

//             $items = [];

//             foreach ($listItems as $li) {

//                 $text = trim($li->textContent);

//                 if (!empty($text)) {
//                     $items[] = $text;
//                 }
//             }

//             return $items;
//         }

//         $text = trim($tdNode->textContent);

//         if (empty($text)) {
//             return [];
//         }

//         $lines = preg_split('/\r\n|\r|\n/', $text);

//         return array_values(array_filter(array_map('trim', $lines)));
//     }

//     private function cleanValue($text) {

//         return preg_replace(
//             '/[^0-9.]/',
//             '',
//             str_ireplace(
//                 [' mb', ' gb', ' kb', ' bytes', ' '],
//                 '',
//                 $text
//             )
//         );
//     }
private function buildDirections($ips)
{
    $out = [];

    foreach ($ips as $ipEntry) {

        // np. 192.168.1.1 (1234)
        preg_match('/^(.*?)\s*\((\d+)\)$/', trim($ipEntry), $match);

        $ip = trim($match[1] ?? $ipEntry);
        $zdarzenia = (int)($match[2] ?? 0);
        
    

        // typ ruchu
        if (
            filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )
        ) {
            $typ = 'zew.';
        } else {
            $typ = 'wew.';
        }

        $out[] = [
            'ip' => $ip,
            'typ' => $typ,
            'zdarzenia' => $zdarzenia,
            'whois_url' => 'https://www.whois.com/whois/' . urlencode($ip),
            
        ];
    }

    // suma zdarzeń
    $total = array_sum(array_column($out, 'zdarzenia'));

    // procenty
    foreach ($out as &$item) {

        $item['procent'] = $total > 0
            ? round(($item['zdarzenia'] / $total) * 100, 1)
            : 0;
    }

    unset($item);

    // sortowanie malejąco
    usort($out, function ($a, $b) {
        return $b['zdarzenia'] <=> $a['zdarzenia'];
    });

    return $out;
}

private function buildCountries($countries)
{
    $out = [];

    foreach ($countries as $countryEntry) {

        // np. Poland (1234)
        preg_match('/^(.*?)\s*\((\d+)\)$/', trim($countryEntry), $match);

        $kraj = trim($match[1] ?? $countryEntry);
        $logi = (int)($match[2] ?? 0);

        $out[] = [
            'prefiks' => strtoupper(substr($kraj, 0, 2)),
            'kraj' => $kraj,
            'logi' => $logi
        ];
    }

    // suma logów
    $total = array_sum(array_column($out, 'logi'));

    // procenty
    foreach ($out as &$item) {

        $item['procent'] = $total > 0
            ? round(($item['logi'] / $total) * 100, 1)
            : 0;
    }

    unset($item);

    // sortowanie malejąco
    usort($out, function ($a, $b) {
        return $b['logi'] <=> $a['logi'];
    });

    return $out;
}

private function buildServices($services)
{
    $out = [];

    foreach ($services as $service) {

        // np. SSL (1276)
        preg_match('/^(.*?)\s*\((\d+)\)$/', trim($service), $match);

        $nazwa = trim($match[1] ?? $service);
        $zdarzenia = (int)($match[2] ?? 0);

        $out[] = [
            'nazwa' => $nazwa,
            'zdarzenia' => $zdarzenia
        ];
    }

    // suma wszystkich zdarzeń
    $total = array_sum(array_column($out, 'zdarzenia'));

    // procenty
    foreach ($out as &$item) {

        $item['procent'] = $total > 0
            ? round(($item['zdarzenia'] / $total) * 100, 1)
            : 0;
    }

    unset($item);

    // sortowanie malejąco
    usort($out, function ($a, $b) {
        return $b['zdarzenia'] <=> $a['zdarzenia'];
    });

    return $out;
}

private function buildHours($events)
{
    $hours = array_fill(0, 24, 0);

    foreach ($events as $event) {

        if (empty($event['time'])) continue;

        $timestamp = strtotime(trim($event['time']));

        if (!$timestamp) continue;

        $hour = (int)date('H', $timestamp);

        $hours[$hour]++;
    }

    $out = [];

    foreach ($hours as $h => $count) {
        $out[] = [
            'godzina' => sprintf('%02d:00', $h),
            'logi' => $count
        ];
    }

    return $out;
}
// private function buildHours($events)
// {
//     $hours = array_fill(0, 24, 0);

//     foreach ($events as $event) {

//         if (empty($event['time'])) continue;

//         $hour = (int)date('H', strtotime($event['time']));

//         $hours[$hour]++;
//     }

//     $out = [];

//     foreach ($hours as $h => $count) {
//         $out[] = [
//             'godzina' => sprintf('%02d:00', $h),
//             'logi' => $count
//         ];
//     }

//     return $out;
// }

private function extractComplexList($tdNode)
{
    if (!$tdNode) {
        return [];
    }

    $listItems = $tdNode->getElementsByTagName('li');

    if ($listItems->length > 0) {

        $items = [];

        foreach ($listItems as $li) {

            $text = trim($li->textContent);

            if (!empty($text)) {
                $items[] = $text;
            }
        }

        return $items;
    }

    $text = trim($tdNode->textContent);

    if (empty($text)) {
        return [];
    }

    $lines = preg_split('/\r\n|\r|\n/', $text);

    return array_values(array_filter(array_map('trim', $lines)));
}


private function cleanValue($text)
{
    $text = strtolower(trim($text));

    $value = (float)preg_replace('/[^0-9.]/', '', $text);

    if (str_contains($text, 'tb')) {
        return $value * 1000000;
    }

    if (str_contains($text, 'gb')) {
        return $value * 1000;
    }

    if (str_contains($text, 'kb')) {
        return $value / 1000;
    }

    return $value; // MB domyślnie
}



    private function getEmptyResponse() {

        return [

            'top_hosts' => [],

            'selected_host' => [],

            'rozkład_godzinowy' => [],

            'meta' => [

                'suma_transferu' => '0 MB',

                'pobrane_rx' => '0 MB',

                'wyslane_tx' => '0 MB',

                'liczba_zdarzen' => 0,

                'urzadzenie' => 'FortiGate',

                'available_days' => [
                    'all' => 'Łącznie'
                ]
            ]
        ];
    }
}