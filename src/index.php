<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * Główny plik Dashboardu aplikacji.
 * Odpowiada za kontrolę żądań, wyświetlanie drzewa plików i dynamiczne dołączanie
 * odpowiedniego parsera oraz dedykowanego widoku z folderu /inc/.
 */

// Rejestracja parserów z folderu /parsers/
require_once __DIR__ . '/parsers/parser.php';
require_once __DIR__ . '/parsers/parser_skanowanie_wew.php';
require_once __DIR__ . '/parsers/parser_host_logowanie.php';
require_once __DIR__ . '/parsers/parser_skanowanie_zew.php';
require_once __DIR__ . '/parsers/parser_odrzuconych_polaczen_wew.php';
require_once __DIR__ . '/parsers/parser_odrzucownych_polaczen_zew.php';
require_once __DIR__ . '/parsers/parser_polaczen_niestandardowe_porty.php';
require_once __DIR__ . '/parsers/parser_uzytkownicy_bledne_logowanie.php';

$danePath = __DIR__ . '/dane/';
$selectedFile = isset($_GET['file']) ? $_GET['file'] : null;
$parsedData = null;
$reportType = 'transfer';

$filterDay = isset($_GET['filter_day']) ? $_GET['filter_day'] : 'all';
$activeIp = isset($_GET['active_ip']) ? $_GET['active_ip'] : '';

$archiveDateRaw = isset($_GET['archive_date']) ? trim($_GET['archive_date']) : '';
$archiveDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $archiveDateRaw) ? $archiveDateRaw : '';
$archiveDateFound = false;
$expandedDate = null;

if (!function_exists('convertToMb')) {
    function convertToMb($valueString) {
        $valueString = str_replace(' ', '', $valueString);
        $val = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', $valueString));
        if (stripos($valueString, 'GB') !== false) {
            return $val * 1024;
        } elseif (stripos($valueString, 'KB') !== false) {
            return $val / 1024;
        } elseif (stripos($valueString, 'B') !== false && stripos($valueString, 'MB') === false) {
            return $val / (1024 * 1024);
        }
        return $val;
    }
}

// Obsługa usuwania katalogu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_dir' && isset($_POST['dir_name'])) {
    $dirToDelete = $_POST['dir_name'];
    $targetDir = realpath($danePath . $dirToDelete);

    if ($targetDir && strpos($targetDir, realpath($danePath)) === 0 && is_dir($targetDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($targetDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($targetDir);

        header("Location: index.php?delete_success=" . urlencode($dirToDelete));
        exit;
    }
}

// Generowanie drzewa plików
$tree = [];
if (file_exists($danePath)) {
    $folders = array_diff(scandir($danePath), array('..', '.'));
    rsort($folders);
    foreach ($folders as $folder) {
        if (is_dir($danePath . $folder)) {
            $files = array_diff(scandir($danePath . $folder), array('..', '.'));
            if (!empty($files)) {
                $tree[$folder] = $files;
            }
        }
    }
}

// Jeśli użytkownik wybrał datę z archiwum, przenosimy znaleziony katalog na samą górę listy.
if ($archiveDate !== '' && isset($tree[$archiveDate])) {
    $archiveDateFound = true;
    $selectedDateFiles = $tree[$archiveDate];
    unset($tree[$archiveDate]);
    $tree = [$archiveDate => $selectedDateFiles] + $tree;
}

// Domyślny pierwszy plik z drzewa, jeśli żaden nie został wybrany.
// Po wyborze daty będzie to pierwszy raport z wybranego dnia.
if (!$selectedFile && !empty($tree)) {
    $firstFolder = array_key_first($tree);
    $firstFile = reset($tree[$firstFolder]);
    $selectedFile = $firstFolder . '/' . $firstFile;
}

if (!empty($tree)) {
    if ($archiveDateFound) {
        $expandedDate = $archiveDate;
    } elseif ($selectedFile && strpos($selectedFile, '/') !== false) {
        $expandedDate = explode('/', $selectedFile, 2)[0];
    } else {
        $expandedDate = array_key_first($tree);
    }
}

// Routing i parsowanie danych wybranego pliku HTML
if ($selectedFile) {
    $fullPath = realpath($danePath . $selectedFile);
    if ($fullPath && strpos($fullPath, realpath($danePath)) === 0 && file_exists($fullPath)) {
        $filename = basename($selectedFile);

        if (mb_stripos($filename, 'transfer') !== false || mb_stripos($filename, 'transfe') !== false) {
            $reportType = 'transfer';
            $parser = new RaportParser($fullPath, $filterDay);
        } elseif (mb_stripos($filename, 'wewnetrzne_skanujace_porty') !== false) {
            $reportType = 'skanowanie_port_host_wew';
            $parser = new RaportWewnSkanujaceParser($fullPath);
        } elseif (mb_stripos($filename, 'Hosty_z_bednymi_probami_logowania') !== false) {
            $reportType = 'host_logowanie';
            $parser = new RaportHostLogowanieParser($fullPath);
        } elseif (mb_stripos($filename, 'zewnetrzne_skanujace_porty') !== false) {
            $reportType = 'skanowanie_port_host_zew';
            $parser = new RaportZewnSkanujaceParser($fullPath);
        } elseif (mb_stripos($filename, 'Odrzucone_poaczenia_z_hostow_wewnetrznych') !== false) {
            $reportType = 'skanowanie_odrzucone_host_wew';
            $parser = new RaportOdrzuconeWewnParser($fullPath);
        } elseif (mb_stripos($filename, 'Odrzucone_poaczenia_z_hostow_zewnetrznych') !== false) {
            $reportType = 'skanowanie_odrzucone_host_zew';
            $parser = new RaportOdrzuconeZewnParser($fullPath);
        } elseif (mb_stripos($filename, 'Poaczenia_wychodzace') !== false) {
            $reportType = 'skanowanie_niestandardowe_porty';
            $parser = new RaportWychodzaceNiestandardoweParser($fullPath);
        } elseif (mb_stripos($filename, 'Uzytkownicy_z_bednymi_probami_logowania') !== false) {
            $reportType = 'uzytkownicy_logowanie';
            $parser = new RaportBedneLogowaniaUzytkownicyParser($fullPath);
        } else {
            $reportType = 'transfer';
            $parser = new RaportParser($fullPath, $filterDay);
        }

        $parsedData = $parser->parse();
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analityka Sieciowa - Panel Raportów</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .table-container { max-height: 520px; overflow-y: auto; }
    </style>
</head>
<body class="text-slate-800 antialiased">

    <!-- Górny pasek nawigacyjny -->
    <header class="sticky top-0 z-40 w-full border-b border-slate-200 bg-white/80 backdrop-blur-md">
        <div class="flex h-16 items-center justify-between px-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-600 text-white shadow-md shadow-blue-200">
                    <i data-lucide="shield-check" class="h-6 w-6"></i>
                </div>
                <div>
                    <h1 class="font-bold text-slate-900 leading-none text-lg">Raporty z alertów SOC system Logsign - ver. 2.0</h1>
                    <span class="text-xs text-slate-400 font-medium font-mono">Status: Aktywny</span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="stats.php" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-indigo-500">
                    <i data-lucide="chart-pie" class="h-4 w-4"></i>
                    Statystyki
                </a>
                <button onclick="document.getElementById('upload-modal').classList.remove('hidden')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 transition-colors">
                    <i data-lucide="upload-cloud" class="h-4 w-4"></i>
                    Wgraj paczkę ZIP
                </button>
            </div>
        </div>
    </header>

    <div class="flex min-h-[calc(100vh-4rem)]">

        <!-- Drzewo Archiwum Raportów (Sidebar) -->
        <aside class="w-80 border-r border-slate-200 bg-white p-6 shrink-0 hidden md:block">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-3">Archiwum Raportów</h2>

            <a href="stats.php" class="mb-4 flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-3 py-2.5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-indigo-500">
                <i data-lucide="chart-pie" class="h-4 w-4"></i>
                Statystyki zbiorcze
            </a>

            <form action="index.php" method="GET" class="mb-4 rounded-xl border border-slate-100 bg-slate-50/70 p-3">
                <label for="archive-date" class="mb-2 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                    <i data-lucide="calendar-search" class="h-3.5 w-3.5 text-blue-500"></i>
                    Przejdź do daty raportu
                </label>
                <div class="flex gap-2">
                    <input
                        type="date"
                        id="archive-date"
                        name="archive_date"
                        value="<?php echo htmlspecialchars($archiveDate); ?>"
                        class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                    >
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-3 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-blue-500" title="Pokaż raporty z wybranej daty">
                        <i data-lucide="search" class="h-4 w-4"></i>
                    </button>
                </div>
                <?php if ($archiveDate !== '' && !$archiveDateFound): ?>
                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] font-semibold text-amber-800">
                        Nie znaleziono raportów dla daty <?php echo htmlspecialchars($archiveDate); ?>.
                    </div>
                <?php elseif ($archiveDateFound): ?>
                    <div class="mt-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-semibold text-emerald-800">
                        Znaleziono datę <?php echo htmlspecialchars($archiveDate); ?> — raport jest na górze listy.
                    </div>
                <?php endif; ?>
            </form>

            <?php if (empty($tree)): ?>
                <div class="rounded-xl border border-dashed border-slate-200 p-6 text-center">
                    <i data-lucide="folder-open" class="mx-auto h-8 w-8 text-slate-300 mb-2"></i>
                    <p class="text-sm text-slate-500 font-medium">Brak wgranych raportów.</p>
                </div>
            <?php else: ?>
                <div class="space-y-3 max-h-[calc(100vh-10rem)] overflow-y-auto pr-1">
                    <?php foreach ($tree as $date => $files): ?>
                        <?php $isExpandedDate = ($expandedDate === $date); ?>
                        <div class="rounded-xl border <?php echo $isExpandedDate ? 'border-blue-100 bg-blue-50/30' : 'border-slate-100 bg-slate-50/50'; ?> p-2">
                            <div class="flex items-center justify-between p-2">
                                <button onclick="toggleFolder('folder-<?php echo htmlspecialchars($date); ?>')" class="flex items-center gap-2 font-semibold <?php echo $isExpandedDate ? 'text-blue-700' : 'text-slate-700'; ?> hover:text-blue-600 text-sm">
                                    <i data-lucide="calendar" class="h-4 w-4 text-blue-500"></i>
                                    <?php echo htmlspecialchars($date); ?>
                                </button>
                                <div class="flex items-center gap-1.5">
                                    <button onclick="confirmDeleteDir('<?php echo htmlspecialchars($date); ?>')" class="rounded-lg p-1 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors" title="Usuń ten katalog">
                                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                                    </button>
                                    <i data-lucide="chevron-down" class="h-4 w-4 text-slate-400 transition-transform duration-200 cursor-pointer" id="icon-folder-<?php echo htmlspecialchars($date); ?>" onclick="toggleFolder('folder-<?php echo htmlspecialchars($date); ?>')" style="<?php echo $isExpandedDate ? '' : 'transform: rotate(-90deg);'; ?>"></i>
                                </div>
                            </div>

                            <div class="mt-1 space-y-1 pl-6 pr-2 pb-1 <?php echo $isExpandedDate ? '' : 'hidden'; ?>" id="folder-<?php echo htmlspecialchars($date); ?>">
                                <?php foreach ($files as $file):
                                    $filePathValue = $date . '/' . $file;
                                    $isActive = ($selectedFile === $filePathValue);

                                    $isScanFile = true;
                                    if (mb_stripos($file, 'transfer') !== false || mb_stripos($file, 'transfe') !== false) {
                                        $isScanFile = false;
                                    }
                                ?>
                                    <a href="index.php?archive_date=<?php echo urlencode($date); ?>&file=<?php echo urlencode($filePathValue); ?>" class="group flex items-center justify-between rounded-lg p-2 text-xs font-medium transition-all <?php echo $isActive ? 'bg-blue-50 text-blue-700' : 'text-slate-500 hover:bg-slate-100/80 hover:text-slate-900'; ?>">
                                        <span class="truncate pr-2" title="<?php echo htmlspecialchars($file); ?>">
                                            <?php echo htmlspecialchars(strlen($file) > 28 ? substr($file, 0, 25) . '...' : $file); ?>
                                        </span>
                                        <i data-lucide="<?php echo $isScanFile ? 'shield-alert' : 'file-text'; ?>" class="h-3.5 w-3.5 shrink-0 <?php echo $isScanFile ? 'text-red-500 opacity-100' : 'text-slate-400 opacity-60'; ?>"></i>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </aside>

        <!-- Główny obszar roboczy -->
        <main class="flex-1 p-8 overflow-y-auto">

            <?php if (isset($_GET['delete_success'])): ?>
                <div class="mb-6 flex items-center gap-3 rounded-xl bg-amber-50 border border-amber-200 p-4 text-amber-800 shadow-sm">
                    <i data-lucide="trash-2" class="h-5 w-5 text-amber-600"></i>
                    <div>
                        <span class="font-semibold">Usunięto!</span> Katalog o dacie <b><?php echo htmlspecialchars($_GET['delete_success']); ?></b> wraz z raportami został pomyślnie skasowany.
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['upload_success'])): ?>
                <div class="mb-6 flex items-center gap-3 rounded-xl bg-emerald-50 border border-emerald-200 p-4 text-emerald-800 shadow-sm">
                    <i data-lucide="check-circle" class="h-5 w-5 text-emerald-600"></i>
                    <div>
                        <span class="font-semibold">Import ukończony!</span> Pomyślnie zaimportowano <?php echo intval($_GET['upload_success']); ?> raportów sieciowych.
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($parsedData): ?>

                <?php
                // DYNAMICZNE DOŁĄCZANIE SEKCYJNEGO WIDOKU RAPORTU Z FOLDERU /inc/
                switch ($reportType) {
                    case 'transfer':
                        include __DIR__ . '/inc/view_transfer.php';
                        break;
                    case 'uzytkownicy_logowanie':
                        include __DIR__ . '/inc/view_uzytkownicy_logowanie.php';
                        break;
                     case 'host_logowanie':
                        include __DIR__ . '/inc/view_host_logowanie.php';
                        break;
                    case 'skanowanie_port_host_wew':
                        include __DIR__ . '/inc/view_skanowanie_port_host_wew.php';
                        break;
                    case 'skanowanie_odrzucone_host_wew':
                        include __DIR__ . '/inc/view_polaczenia_host_wew.php';
                        break;
                    case 'skanowanie_odrzucone_host_zew':
                        include __DIR__ . '/inc/view_polaczenia_host_zew.php';
                        break;
                    case 'skanowanie_niestandardowe_porty':
                        include __DIR__ . '/inc/view_niestandardowe_porty.php';
                        break;
                    case 'skanowanie_port_host_zew':
                    default:
                        include __DIR__ . '/inc/view_skanowanie_port_host_zew.php';
                        break;
                }
                ?>

            <?php else: ?>
                <!-- Stan pusty (brak wgranych raportów) -->
                <div class="flex flex-col items-center justify-center min-h-[55vh] rounded-2xl border border-dashed border-slate-200 bg-white p-8 text-center">
                    <div class="rounded-2xl bg-blue-50 p-4 text-blue-600 mb-4">
                        <i data-lucide="folder-search" class="h-10 w-10"></i>
                    </div>
                    <h3 class="text-lg font-bold text-slate-950">Brak wgranych raportów sieciowych</h3>
                    <p class="text-sm text-slate-400 max-w-md mt-2 font-normal">Wgraj plik ZIP zawierający raporty HTML wygenerowane z systemu zabezpieczającego.</p>
                    <button onclick="document.getElementById('upload-modal').classList.remove('hidden')" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 transition-colors">
                        <i data-lucide="upload-cloud" class="h-4 w-4"></i>
                        Wgraj pierwszy plik ZIP
                    </button>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- Modal wgrywania ZIP -->
    <div id="upload-modal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-100">
            <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    <i data-lucide="upload-cloud" class="h-5 w-5 text-blue-600"></i>
                    Importuj nowy pakiet raportów
                </h3>
                <button onclick="document.getElementById('upload-modal').classList.add('hidden')" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
                    <i data-lucide="x" class="h-5 w-5"></i>
                </button>
            </div>

            <form action="upload.php" method="POST" enctype="multipart/form-data" class="mt-4">
                <div class="rounded-xl border border-dashed border-slate-200 p-8 text-center hover:bg-slate-50/50 transition-colors cursor-pointer" onclick="document.getElementById('zip-input').click()">
                    <i data-lucide="folder-archive" class="mx-auto h-12 w-12 text-slate-300"></i>
                    <p class="mt-3 text-sm font-semibold text-slate-700">Wybierz plik ZIP z dysku</p>
                    <p class="mt-1 text-xs text-slate-400">Maksymalny rozmiar pliku: 120 MB</p>
                    <input type="file" name="zip_file" id="zip-input" class="hidden" accept=".zip" required onchange="updateFileName(this)">
                    <div id="selected-file-name" class="mt-4 hidden text-xs font-bold text-blue-600 bg-blue-50 rounded-lg p-2 truncate"></div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" onclick="document.getElementById('upload-modal').classList.add('hidden')" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Anuluj</button>
                    <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">Rozpocznij import</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Potwierdzenia Usunięcia Katalogu -->
    <div id="delete-confirm-modal" class="fixed inset-0 z-50 hidden bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4">
        <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl border border-slate-100">
            <div class="flex items-center gap-3 text-red-600 mb-4">
                <i data-lucide="alert-triangle" class="h-6 w-6"></i>
                <h3 class="font-bold text-slate-900 text-lg">Potwierdź usunięcie katalogu</h3>
            </div>
            <p class="text-sm text-slate-500 leading-relaxed font-normal">
                Czy na pewno chcesz usunąć katalog <span id="delete-dir-name" class="font-bold text-slate-900"></span> wraz ze wszystkimi plikami raportów HTML? Ta operacja jest całkowicie nieodwracalna.
            </p>
            <form action="index.php" method="POST" class="mt-6 flex justify-end gap-3">
                <input type="hidden" name="action" value="delete_dir">
                <input type="hidden" name="dir_name" id="delete-input-dir" value="">
                <button type="button" onclick="document.getElementById('delete-confirm-modal').classList.add('hidden')" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Anuluj</button>
                <button type="submit" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-500 shadow-sm transition-all">Tak, usuń katalog</button>
            </form>
        </div>
    </div>

    <!-- Skrypty obsługi interfejsu -->
    <script>
        lucide.createIcons();

        function toggleFolder(id) {
            const el = document.getElementById(id);
            const icon = document.getElementById('icon-' + id);
            if (el.classList.contains('hidden')) {
                el.classList.remove('hidden');
                icon.style.transform = 'rotate(0deg)';
            } else {
                el.classList.add('hidden');
                icon.style.transform = 'rotate(-90deg)';
            }
        }

        function updateFileName(input) {
            const fileNameBox = document.getElementById('selected-file-name');
            if (input.files && input.files[0]) {
                fileNameBox.textContent = "Wybrano: " + input.files[0].name;
                fileNameBox.classList.remove('hidden');
            } else {
                fileNameBox.classList.add('hidden');
            }
        }

        function confirmDeleteDir(dirName) {
            document.getElementById('delete-dir-name').textContent = dirName;
            document.getElementById('delete-input-dir').value = dirName;
            document.getElementById('delete-confirm-modal').classList.remove('hidden');
        }

        function showAllHostsRows() {
            const rows = document.querySelectorAll('.host-row');
            rows.forEach(row => { row.classList.remove('hidden'); });
            const btn = document.getElementById('btn-show-more');
            if(btn) { btn.classList.add('hidden'); }
        }
    </script>
</body>
</html>
