<?php
/**
 * Klasa RaportSkanowanieParser służy do analizy i wyciągania szczegółowych
 * informacji o złośliwym skanowaniu portów przez hosty zewnętrzne z plików HTML.
 * Brak symulacji i statycznych danych demo - parser czyta wyłącznie zawartość pliku.
 */
class RaportSkanowanieParser {
    private $filePath;
    private $fileName;
    private $date;

    public function __construct($filePath) {
        $this->filePath = $filePath;
        $this->fileName = basename($filePath);
        $this->extractDate();
    }

    private function extractDate() {
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $this->fileName, $matches)) {
            $this->date = $matches[1];
        } else {
            $this->date = date('Y-m-d');
        }
    }

    /**
     * Zwraca ikonę flagi na podstawie nazwy państwa (angielskiej lub polskiej)
     */
    public function getCountryFlag($countryName) {
        $countryName = trim(strtolower($countryName));
        $countries = [
            'poland' => '🇵🇱',
            'polska' => '🇵🇱',
            'united states' => '🇺🇸',
            'usa' => '🇺🇸',
            'stany zjednoczone' => '🇺🇸',
            'germany' => '🇩🇪',
            'niemcy' => '🇩🇪',
            'russia' => '🇷🇺',
            'rosja' => '🇷🇺',
            'russian federation' => '🇷🇺',
            'china' => '🇨🇳',
            'chiny' => '🇨🇳',
            'netherlands' => '🇳🇱',
            'holandia' => '🇳🇱',
            'united kingdom' => '🇬🇧',
            'wielka brytania' => '🇬🇧',
            'france' => '🇫🇷',
            'francja' => '🇫🇷',
            'ukraine' => '🇺🇦',
            'ukraina' => '🇺🇦',
            'canada' => '🇨🇦',
            'kanada' => '🇨🇦'
        ];
        return $countries[$countryName] ?? '🏳️';
    }

    /**
     * Analizuje plik HTML i wyciąga wiersze tabeli z logami
     */
    public function parse() {
        $data = [
            'meta' => [
                'nazwa_pliku' => $this->fileName,
                'data_raportu' => $this->date,
                'suma_zdarzen' => 0,
                'unikalne_ip' => 0,
                'najbardziej_aktywny_ip' => 'Brak danych',
                'urzadzenie' => 'FortiGate (FG)'
            ],
            'scans' => []
        ];

        if (!file_exists($this->filePath)) {
            return $data;
        }

        $htmlContent = file_get_contents($this->filePath);

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Ładowanie z wymuszeniem kodowania UTF-8
        $dom->loadHTML('<?xml encoding="UTF-8">' . $htmlContent);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Szukamy tabel w pliku HTML
        $rows = $xpath->query('//table//tr');
        if ($rows->length === 0) {
            // Spróbujmy znaleźć elementy div udające tabele (częste w nowszych raportach)
            $rows = $xpath->query('//div[contains(@class, "row")] | //div[contains(@class, "tr")]');
        }

        $headers = [];
        $scansRaw = [];
        $uniqueSourceIps = [];
        $ipActivityCount = [];

        // Przebieg po wierszach w celu detekcji nagłówków i danych
        foreach ($rows as $rowIndex => $row) {
            $cols = $xpath->query('.//td | .//th | .//div[contains(@class, "td")] | .//div[contains(@class, "th")]', $row);

            if ($cols->length === 0) continue;

            // Pierwszy wiersz z dużą ilością kolumn zazwyczaj zawiera nagłówki
            if (empty($headers) && $cols->length > 5) {
                foreach ($cols as $colIndex => $col) {
                    $headerText = trim($col->nodeValue);
                    // Oczyszczanie nagłówków z ewentualnych liczb w nawiasach typu (Term) lub (Value)
                    $headerClean = preg_replace('/\s*\([^)]*\)/', '', $headerText);
                    $headers[$colIndex] = trim($headerClean);
                }
                continue;
            }

            // Jeśli mamy już zmapowane nagłówki, parsujemy dane
            if (!empty($headers) && $cols->length >= count($headers)) {
                $record = [];
                foreach ($cols as $colIndex => $col) {
                    if (isset($headers[$colIndex])) {
                        $key = $headers[$colIndex];
                        $val = trim($col->nodeValue);
                        $record[$key] = $val;
                    }
                }

                // Szukamy kluczowego pola Source.IP
                $sourceIp = null;
                foreach ($record as $key => $val) {
                    if (stripos($key, 'Source.IP') !== false || stripos($key, 'Source IP') !== false || stripos($key, 'IP źródłowy') !== false) {
                        // Wyciągamy sam adres IP (usuwamy ewentualne nawiasy z liczbą zdarzeń)
                        if (preg_match('/^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})/', $val, $ipMatches)) {
                            $sourceIp = $ipMatches[1];
                        }
                    }
                }

                if ($sourceIp) {
                    $scansRaw[] = $record;
                    $uniqueSourceIps[$sourceIp] = true;

                    // Zliczanie zdarzeń dla statystyki najaktywniejszego agresora
                    if (!isset($ipActivityCount[$sourceIp])) {
                        $ipActivityCount[$sourceIp] = 0;
                    }
                    $ipActivityCount[$sourceIp]++;
                }
            }
        }

        // Mapowanie surowych rekordów na ustrukturyzowany model danych wyjściowych
        foreach ($scansRaw as $raw) {
            $source_ip = '';
            $dest_ip = '';
            $dest_port = '';
            $protocol = 'TCP';
            $service = '';
            $application = '';
            $source_country = '';
            $dest_country = '';
            $event_info = '';
            $event_desc = '';
            $device = 'FG';
            $time_generated = '';
            $events_count = 1;

            foreach ($raw as $key => $val) {
                // Wyciąganie i czyszczenie danych (pozbywanie się statystyk w nawiasach np. "Poland (103595)")
                $cleanVal = preg_replace('/\s*\([^)]*\)/', '', $val);

                if (stripos($key, 'Source.IP') !== false || stripos($key, 'Source IP') !== false) {
                    $source_ip = $cleanVal;
                    // Jeśli w oryginalnym polu była liczba w nawiasie, np. "91.223.135.17 (103595)", wyciągamy ją jako liczbę zdarzeń
                    if (preg_match('/\(([\d\s]+)\)/', $val, $numMatches)) {
                        $events_count = (int)str_replace(' ', '', $numMatches[1]);
                    }
                } elseif (stripos($key, 'Destination.IP') !== false || stripos($key, 'Destination IP') !== false) {
                    $dest_ip = $cleanVal;
                } elseif (stripos($key, 'Destination.Port') !== false || stripos($key, 'Port') !== false) {
                    $dest_port = $cleanVal;
                } elseif (stripos($key, 'Protocol') !== false) {
                    $protocol = $cleanVal;
                } elseif (stripos($key, 'Service') !== false) {
                    $service = $cleanVal;
                } elseif (stripos($key, 'Application') !== false) {
                    $application = $cleanVal;
                } elseif (stripos($key, 'Source.Country') !== false || stripos($key, 'Source Country') !== false) {
                    $source_country = $cleanVal;
                } elseif (stripos($key, 'Destination.Country') !== false || stripos($key, 'Destination Country') !== false) {
                    $dest_country = $cleanVal;
                } elseif (stripos($key, 'EventMap.Info') !== false || stripos($key, 'Event Info') !== false) {
                    $event_info = $cleanVal;
                } elseif (stripos($key, 'EventSource.Description') !== false || stripos($key, 'Description') !== false) {
                    $event_desc = $cleanVal;
                } elseif (stripos($key, 'Time.Generated') !== false || stripos($key, 'Time') !== false) {
                    $time_generated = $cleanVal;
                }
            }

            // Określenie poziomu zagrożenia
            $danger_level = 'Medium';
            if (stripos($application, 'bruteforce') !== false || stripos($event_info, 'blocked') !== false || $events_count > 50000) {
                $danger_level = 'Critical';
            } elseif ($events_count > 1000) {
                $danger_level = 'High';
            }

            $data['scans'][] = [
                'source_ip' => $source_ip,
                'dest_ip' => $dest_ip ?: 'Dowolny',
                'dest_port' => $dest_port ?: 'Dowolny',
                'protocol' => $protocol,
                'service' => $service ?: 'Nieznana',
                'application' => $application ?: 'Skanowanie portów',
                'source_country' => $source_country ?: 'Unknown',
                'dest_country' => $dest_country ?: 'Unknown',
                'event_info' => $event_info ?: 'Connection Attempt',
                'event_desc' => $event_desc ?: 'Skanowanie portów z zewnątrz',
                'device' => $device,
                'time_generated' => $time_generated ?: date('Y-m-d H:i:s'),
                'events_count' => $events_count,
                'danger_level' => $danger_level,
                'abuse_url' => 'https://www.abuseipdb.com/check/' . urlencode($source_ip),
                'virustotal_url' => 'https://www.virustotal.com/gui/ip-address/' . urlencode($source_ip),
                'whois_url' => 'https://www.whois.com/whois/' . urlencode($source_ip)
            ];

            $data['meta']['suma_zdarzen'] += $events_count;
        }

        // Agregacja unikalnych hostów
        $data['meta']['unikalne_ip'] = count($uniqueSourceIps);

        // Znalezienie najbardziej aktywnego agresora
        if (!empty($ipActivityCount)) {
            arsort($ipActivityCount);
            $data['meta']['najbardziej_aktywny_ip'] = array_key_first($ipActivityCount);
        }

        return $data;
    }
}
