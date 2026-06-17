<?php
/**
 * Skrypt odpowiada za bezpieczne odebranie pliku ZIP, rozpakowanie go (również w przypadku ZIP-w-ZIP),
 * odczytanie daty z nazw plików HTML za pomocą wyrażenia regularnego,
 * przeniesienie ich do odpowiedniego katalogu w folderze /dane/ oraz posprzątanie po sobie.
 */

// Uruchomienie buforowania wyjścia, aby zapobiec problemom z nagłówkami przekierowania HTTP
ob_start();

require_once __DIR__ . '/db/importer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zip_file'])) {
    $zipFile = $_FILES['zip_file']['tmp_name'];

    $tempDir = __DIR__ . '/temp/';
    $daneDir = __DIR__ . '/dane/';

    // Tworzenie wymaganych folderów z wyciszeniem ewentualnych ostrzeżeń systemowych
    if (!file_exists($tempDir)) @mkdir($tempDir, 0777, true);
    if (!file_exists($daneDir)) @mkdir($daneDir, 0777, true);

    // Kopiujemy plik z $_FILES do temp, aby móc nim manipulować w pętli rozpakowującej
    $currentZipPath = $tempDir . 'upload_' . time() . '.zip';
    if (move_uploaded_file($zipFile, $currentZipPath)) {
        
        $zip = new ZipArchive;
        $extractionSuccess = false;

        // Pętla "odwijająca" – działa dopóki istnieje plik ZIP do rozpakowania
        while (file_exists($currentZipPath) && $zip->open($currentZipPath) === TRUE) {
            @$zip->extractTo($tempDir);
           
            $zip->close();
            
            // Usuwamy przetworzony plik ZIP, żeby nie zapętlić skryptu
            @unlink($currentZipPath);
            $extractionSuccess = true;

            // Sprawdzamy, czy w temp pojawił się kolejny, zagnieżdżony plik ZIP
            $currentZipPath = findNestedZip($tempDir);
        }
       
        if ($extractionSuccess) {
            $processedCount = 0;

            // Rekurencyjne przeszukiwanie folderu tymczasowego w celu znalezienia wszystkich plików .html
            if (file_exists($tempDir)) {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tempDir));

                foreach ($iterator as $file) {
                    if ($file->isFile() && strtolower($file->getExtension()) === 'html') {

                        $originalName = $file->getFilename();
                        
                        // Pobieramy czas modyfikacji pliku (z ZIPa), aby uzyskać dokładną godzinę i minuty
                        $fileMTime = $file->getMTime(); 
                        $exactTimestamp = date('Y-m-d_H-i-s', $fileMTime);

                        // Wyciągamy czystą nazwę bez prefiksu i bez starej daty
                        $filename = prepareFinalFilename($originalName, $exactTimestamp);

                        // Szukanie folderu daty (na podstawie daty modyfikacji pliku: RRRR-MM-DD)
                        $dateFolder = date('Y-m-d', $fileMTime);
                        $targetFolder = $daneDir . $dateFolder . '/';

                        if (!file_exists($targetFolder)) {
                            @mkdir($targetFolder, 0777, true);
                        }

                        // Zapis NOWEGO pliku z oczyszczoną nazwą i nową strukturą czasu
                        $content = file_get_contents($file->getRealPath());
                        file_put_contents($targetFolder . $filename, $content);

                        // Raporty 2.0: plik HTML pozostaje w archiwum, a wynik parsera trafia do MySQL.
                        try {
                            raport2_import_report_file($targetFolder . $filename, $originalName);
                        } catch (Throwable $e) {
                            // Import do bazy nie blokuje archiwizacji pliku. Szczegoly mozna odczytac w logach PHP.
                            error_log('Raporty 2.0 MySQL import error: ' . $e->getMessage());
                        }

                        $processedCount++;
                    }
                }
            }

            // Czyszczenie zawartości katalogu tymczasowego
            clearTempDirectoryContents($tempDir);

            // Wyczyszczenie bufora i bezpieczne przekierowanie do dashboardu
            ob_end_clean();
            header("Location: index.php?upload_success=" . $processedCount);
            exit;
        }
    }
    
    // Jeśli coś poszło nie tak z wypakowaniem lub plikiem
    clearTempDirectoryContents($tempDir);
    ob_end_clean();
    header("Location: index.php?upload_error=1");
    exit;

} else {
    ob_end_clean();
    header("Location: index.php");
    exit;
}

/**
 * POMOCNICZE FUNKCJE GLOBALNE
 */

function findNestedZip($dir) {
    if (!file_exists($dir)) return false;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'zip') {
            return $file->getRealPath();
        }
    }
    return false;
}

function clearTempDirectoryContents($dir) {
    if (!file_exists($dir)) return;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $fileinfo) {
        $realPath = $fileinfo->getRealPath();
        if ($fileinfo->isDir()) {
            @rmdir($realPath);
        } else {
            @unlink($realPath);
        }
    }
}

/**
 * Funkcja przygotowuje docelową nazwę pliku: usuwa prefiks, wycina starą datę,
 * dodaje dokładny timestamp (data_godzina) oraz normalizuje polskie znaki.
 */
/**
 * Ultra-bezpieczna funkcja przygotowująca docelową nazwę pliku.
 * Wymusza kodowanie UTF-8 i agresywnie czyści stary prefiks.
 */
/**
 * Bezpieczna funkcja przygotowująca docelową nazwę pliku.
 * Usuwa błędy kodowania za pomocą iconv i agresywnie odcina prefiks.
 */
/**
 * Ostateczna, pancerna funkcja przygotowująca docelową nazwę pliku.
 * Usuwa prefiks bez względu na uszkodzenia kodowania i znaki ukryte.
 */
/**
 * Precyzyjna funkcja przygotowująca docelową nazwę pliku.
 * Usuwa prefiks bez ryzyka uszkodzenia prawidłowych nazw.
 */
/**
 * Funkcja przygotowująca docelową nazwę pliku.
 * Gwarantuje usunięcie prefiksu oraz CAŁKOWITE usunięcie polskich znaków.
 */
/**
 * Funkcja przygotowująca docelową nazwę pliku.
 * Całkowicie USUWA (wycina) polskie znaki z nazwy pliku.
 */
/**
 * Funkcja przygotowująca docelową nazwę pliku.
 * Usuwa litery "ł/Ł", a pozostałe polskie znaki zamienia (ą->a, ę->e itp.).
 */
/**
 * Funkcja przygotowująca docelową nazwę pliku.
 * Usuwa wybrane litery (ł, ż, ó, ę), a resztę zamienia na łacińskie odpowiedniki.
 */
/**
 * Funkcja przygotowująca docelową nazwę pliku.
 * Usuwa litery Ł/ł, a pozostałe polskie znaki zamienia na łacińskie (ż->z, ó->o, ę->e).
 */
/**
 * Ostateczna funkcja przygotowująca docelową nazwę pliku.
 * Ręcznie naprawia specyficzne uszkodzenia znaków (krzaki z ZIP-a) 
 * oraz całkowicie usuwa literę "ł".
 */
/**
 * Ostateczna, w pełni poprawiona funkcja przygotowująca docelową nazwę pliku.
 * Usuwa prefiks, naprawia specyficzne krzaki z ZIP-a i kasuje literę "ł".
 */
function prepareFinalFilename($filename, $exactTimestamp) {
    
    // 1. Usunięcie prefiksu "SP...Koszalin" wraz z wszelkimi separatorami po nim
    $filename = preg_replace('/^SP[^A-Za-z0-9]*Koszalin[^A-Za-z0-9]*/i', '', $filename);
    $filename = str_ireplace(['SP_Koszalin_-_', 'SP Koszalin - '], '', $filename);

    // 2. Usunięcie starej daty w formacie RRRR-MM-DD z nazwy pliku
    $filename = preg_replace('/\d{4}-\d{2}-\d{2}/', '', $filename);

    // 3. Odcięcie rozszerzenia .html na czas operacji na tekście
    $pathInfo = pathinfo($filename);
    $justName = $pathInfo['filename'];

    // 4. SŁOWNIK NAPRAWCZY (Dokładne mapowanie krzaków z Twojego serwera)
    $krzakiMap = [
        'Uytkownicy'   => 'Uzytkownicy', // Naprawia Użytkownicy (gdzie ż zniknęło/zamieniło się w y)
        'uytkownicy'   => 'uzytkownicy',
        'PoZczenia'    => 'Polaczenia',  // Naprawia Połączenia (usuwa ł, zamienia ą na a)
        'poZczenia'    => 'polaczenia',
        'wychodzZce'   => 'wychodzace',  // Naprawia wychodzące
        'bdnymi'       => 'bednymi',     // Naprawia błędnymi (usuwa ł, zamienia ę na e)
        'prsbami'      => 'probami',     // Naprawia próbami (zamienia ó na o)
        'wewntrznych'  => 'wewnętrznych',// Wstępna naprawa wewnętrznych
        'hostsw'       => 'hostow',      // Naprawia hostów
    ];
    $justName = str_tr_safe($justName, $krzakiMap);

    // 5. CZYSZCZENIE KOŃCOWE (Dla liter, które mogły przetrwać lub powstać w punkcie 4)
    // Całkowicie kasujemy literę Ł/ł
    $justName = str_replace(['ł', 'Ł'], '', $justName);

    // Resztę polskich znaków zamieniamy na zwykłe
    $plMap = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ż' => 'Z', 'Ź' => 'Z'
    ];
    $justName = strtr($justName, $plMap);

    // 6. Zamiana spacji na podkreślenia
    $justName = str_replace(' ', '_', $justName);

    // 7. Usunięcie ewentualnych pozostałych dziwnych symboli
    $justName = preg_replace('/[^A-Za-z0-9_\-]/', '', $justName); 
    
    // 8. Czyszczenie podwójnych podkreśleń
    $justName = preg_replace('/_+/', '_', $justName);
    $justName = trim($justName, '_-');

    // 9. Składamy finalną nazwę
    return $justName . '_' . $exactTimestamp . '.html';
}

/**
 * Pomocnicza funkcja bezpiecznej zamiany ciągów znaków
 */
function str_tr_safe($string, $replacements) {
    foreach ($replacements as $search => $replace) {
        $string = str_replace($search, $replace, $string);
    }
    return $string;
}