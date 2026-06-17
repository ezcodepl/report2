<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/db/importer.php';

function h($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fmt($n) { return number_format((float)$n, 0, ',', ' '); }
function fmtBytes($bytes) {
    $bytes = (float)$bytes;
    if ($bytes >= 1099511627776) return number_format($bytes / 1099511627776, 2, ',', ' ') . ' TB';
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', ' ') . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2, ',', ' ') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2, ',', ' ') . ' KB';
    return number_format($bytes, 0, ',', ' ') . ' B';
}

$period = isset($_GET['period']) ? $_GET['period'] : '7';
$allowed = ['3', '7', '30', 'all'];
if (!in_array($period, $allowed, true)) $period = '7';

$dbOk = false;
$errorMessage = null;
$pdo = null;
try {
    raport2_bootstrap_schema();
    $pdo = raport2_db();
    $dbOk = true;
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$where = '1=1';
$params = [];
if ($period !== 'all') {
    $where = 'report_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
    $params[] = (int)$period - 1;
}

function one(PDO $pdo, string $sql, array $params = [], $default = 0) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? $default : $value;
}
function rows(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
function topRows(PDO $pdo, string $field, string $where, array $params, int $limit = 100): array {
    $allowed = [
        'report_type','source_ip','source_host','source_user','source_country',
        'destination_ip','destination_host','destination_port','destination_country',
        'protocol_name','service_name','application_name','application_category','event_subtype','event_hour'
    ];
    if (!in_array($field, $allowed, true)) return [];
    $sql = "SELECT COALESCE(NULLIF(CAST($field AS CHAR), ''), 'Nieznany') AS label, SUM(events_count) AS total, COUNT(*) AS records, SUM(bytes_total) AS bytes_total
            FROM report_events
            WHERE $where AND $field IS NOT NULL AND CAST($field AS CHAR) <> ''
            GROUP BY label
            ORDER BY total DESC
            LIMIT $limit";
    return rows($pdo, $sql, $params);
}
function piePayload(array $items, int $limit = 8, string $valueField = 'total', string $unit = 'zd.'): array {
    $items = array_values($items);
    usort($items, fn($a, $b) => ((float)($b[$valueField] ?? 0)) <=> ((float)($a[$valueField] ?? 0)));
    $labels = [];
    $values = [];
    $other = 0;
    foreach ($items as $idx => $row) {
        $label = (string)($row['label'] ?? 'Nieznany');
        $value = (float)($row[$valueField] ?? 0);
        if ($value <= 0) continue;
        if ($idx < $limit) {
            $labels[] = $label;
            $values[] = $value;
        } else {
            $other += $value;
        }
    }
    if ($other > 0) {
        $labels[] = 'Pozostałe';
        $values[] = $other;
    }
    $sum = array_sum($values);
    return ['labels' => $labels, 'values' => $values, 'sum' => $sum, 'unit' => $unit];
}

function renderPieCard(string $canvasId, string $title, string $subtitle, string $icon = 'pie-chart'): void {
    ?>
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-start justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-extrabold uppercase tracking-wide text-slate-900"><i data-lucide="<?php echo h($icon); ?>" class="h-5 w-5 text-indigo-600"></i><?php echo h($title); ?></h3>
                <p class="mt-1 text-xs font-medium text-slate-400"><?php echo h($subtitle); ?></p>
            </div>
            <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-[10px] font-bold text-indigo-700">%</span>
        </div>
        <div class="mx-auto max-w-[420px]">
            <canvas id="<?php echo h($canvasId); ?>" height="250"></canvas>
        </div>
        <div id="legend-<?php echo h($canvasId); ?>" class="mt-5 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2"></div>
    </div>
    <?php
}

function renderTable(string $id, string $title, string $subtitle, array $items, string $icon = 'bar-chart-3', bool $bytes = false, ?string $pieId = null): void {
    $visible = 10;
    $max = 1;
    foreach ($items as $i) $max = max($max, (float)($i['total'] ?? 0));
    ?>
    <div class="rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-start justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <h3 class="flex items-center gap-2 text-sm font-extrabold uppercase tracking-wide text-slate-900"><i data-lucide="<?php echo h($icon); ?>" class="h-5 w-5 text-indigo-600"></i><?php echo h($title); ?></h3>
                <p class="mt-1 text-xs font-medium text-slate-400"><?php echo h($subtitle); ?></p>
            </div>
            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500"><?php echo fmt(count($items)); ?> rekordów</span>
        </div>
        <div class="grid grid-cols-1 gap-6 2xl:grid-cols-5">
            <div class="2xl:col-span-3">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                <th class="py-2 pr-3">#</th>
                                <th class="py-2 pr-3">Wartość</th>
                                <th class="py-2 pr-3 text-right">Zdarzenia</th>
                                <th class="py-2 pr-3 text-right">Udział</th>
                                <?php if ($bytes): ?><th class="py-2 text-right">Transfer</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="<?php echo $bytes ? 5 : 4; ?>" class="py-6 text-center text-xs font-semibold text-slate-400">Brak danych</td></tr>
                        <?php else: foreach ($items as $idx => $row):
                            $total = (float)($row['total'] ?? 0);
                            $pct = min(100, max(0, ($total / $max) * 100));
                            $hidden = $idx >= $visible;
                        ?>
                            <tr class="<?php echo $hidden ? 'hidden extra-' . h($id) : ''; ?> border-b border-slate-50 align-top">
                                <td class="py-3 pr-3 text-xs font-bold text-slate-400"><?php echo $idx + 1; ?></td>
                                <td class="max-w-[360px] py-3 pr-3">
                                    <div class="truncate font-mono text-xs font-bold text-slate-800" title="<?php echo h($row['label']); ?>"><?php echo h($row['label']); ?></div>
                                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-blue-500" style="width: <?php echo round($pct, 1); ?>%"></div></div>
                                </td>
                                <td class="py-3 pr-3 text-right text-xs font-extrabold text-indigo-600"><?php echo fmt($total); ?></td>
                                <td class="py-3 pr-3 text-right text-xs font-semibold text-slate-500"><?php echo number_format($pct, 1, ',', ' '); ?>%</td>
                                <?php if ($bytes): ?><td class="py-3 text-right text-xs font-bold text-slate-700"><?php echo fmtBytes($row['bytes_total'] ?? 0); ?></td><?php endif; ?>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($items) > $visible): ?>
                    <button type="button" data-target="extra-<?php echo h($id); ?>" onclick="toggleExtraRows(this)" class="mt-4 inline-flex items-center gap-2 rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-2 text-xs font-extrabold text-indigo-700 transition hover:bg-indigo-100">
                        <i data-lucide="chevrons-down" class="h-4 w-4"></i> Pokaż pozostałe
                    </button>
                <?php endif; ?>
            </div>
            <?php if ($pieId): ?>
                <div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4 2xl:col-span-2">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h4 class="flex items-center gap-2 text-xs font-extrabold uppercase tracking-wide text-slate-700"><i data-lucide="pie-chart" class="h-4 w-4 text-indigo-600"></i>Udział procentowy</h4>
                            <p class="mt-1 text-[11px] font-medium text-slate-400">Wykres dla tej tabeli, z grupą „Pozostałe”.</p>
                        </div>
                        <span class="rounded-full bg-white px-2.5 py-1 text-[10px] font-bold text-indigo-700 shadow-sm">%</span>
                    </div>
                    <canvas id="<?php echo h($pieId); ?>" height="230"></canvas>
                    <div id="legend-<?php echo h($pieId); ?>" class="mt-4 grid grid-cols-1 gap-2 text-xs"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$metrics = [
    'reports' => 0, 'events' => 0, 'hosts' => 0, 'users' => 0, 'source_ips' => 0, 'dest_ips' => 0, 'ports' => 0, 'bytes' => 0
];
$top = [];
$topTransfer = [];
$daily = [];
$recentReports = [];
if ($dbOk) {
    $metrics['reports'] = one($pdo, "SELECT COUNT(*) FROM reports WHERE $where", $params);
    $metrics['events'] = one($pdo, "SELECT COALESCE(SUM(events_total),0) FROM reports WHERE $where", $params);
    $metrics['hosts'] = one($pdo, "SELECT COUNT(DISTINCT source_host) FROM report_events WHERE $where AND source_host IS NOT NULL AND source_host <> ''", $params);
    $metrics['users'] = one($pdo, "SELECT COUNT(DISTINCT source_user) FROM report_events WHERE $where AND source_user IS NOT NULL AND source_user <> ''", $params);
    $metrics['source_ips'] = one($pdo, "SELECT COUNT(DISTINCT source_ip) FROM report_events WHERE $where AND source_ip IS NOT NULL AND source_ip <> ''", $params);
    $metrics['dest_ips'] = one($pdo, "SELECT COUNT(DISTINCT destination_ip) FROM report_events WHERE $where AND destination_ip IS NOT NULL AND destination_ip <> ''", $params);
    $metrics['ports'] = one($pdo, "SELECT COUNT(DISTINCT destination_port) FROM report_events WHERE $where AND destination_port IS NOT NULL AND destination_port <> ''", $params);
    $metrics['bytes'] = one($pdo, "SELECT COALESCE(SUM(bytes_total),0) FROM report_events WHERE $where", $params);

    foreach ([
        'report_type','source_ip','source_host','source_user','source_country','destination_ip','destination_port','destination_country',
        'protocol_name','service_name','application_name','application_category','event_subtype','event_hour'
    ] as $field) {
        $top[$field] = topRows($pdo, $field, $where, $params, 200);
    }

    $topTransfer = topRows($pdo, 'source_ip', $where . ' AND bytes_total > 0', $params, 200);
    $daily = rows($pdo, "SELECT report_date AS day, SUM(events_total) AS total FROM reports WHERE $where GROUP BY report_date ORDER BY report_date ASC", $params);
    $recentReports = rows($pdo, "SELECT * FROM reports WHERE $where ORDER BY created_at DESC LIMIT 50", $params);
}

$periodLabel = $period === 'all' ? 'cały okres' : 'ostatnie ' . $period . ' dni';
$chartLabels = array_map(fn($r) => $r['day'], $daily);
$chartValues = array_map(fn($r) => (int)$r['total'], $daily);
$pieCharts = [];
if ($dbOk) {
    $pieCharts = [
        'pieTypes' => piePayload($top['report_type'] ?? [], 7),
        'pieSourceCountry' => piePayload($top['source_country'] ?? [], 8),
        'pieDestCountry' => piePayload($top['destination_country'] ?? [], 8),
        'piePorts' => piePayload($top['destination_port'] ?? [], 8),
        'pieServices' => piePayload($top['service_name'] ?? [], 8),
        'pieApps' => piePayload($top['application_name'] ?? [], 8),
        'pieSubtypes' => piePayload($top['event_subtype'] ?? [], 8),
        'pieProtocols' => piePayload($top['protocol_name'] ?? [], 8),
        'pieAppCats' => piePayload($top['application_category'] ?? [], 8),
        'pieSrcIp' => piePayload($top['source_ip'] ?? [], 8),
        'pieSrcHost' => piePayload($top['source_host'] ?? [], 8),
        'pieUsers' => piePayload($top['source_user'] ?? [], 8),
        'pieDstIp' => piePayload($top['destination_ip'] ?? [], 8),
        'pieTransfer' => piePayload($topTransfer ?? [], 8, 'bytes_total', 'B'),
        'pieHours' => piePayload($top['event_hour'] ?? [], 8),
    ];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raporty 2.0 - Statystyki MySQL</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body{font-family:Inter,sans-serif;background:#f8fafc}.stat-card{background:linear-gradient(135deg,#fff,#f8fafc)}::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px}</style>
</head>
<body class="text-slate-800 antialiased">
<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/85 backdrop-blur-md">
    <div class="flex h-16 items-center justify-between px-6">
        <div class="flex items-center gap-3">
            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-md shadow-indigo-200"><i data-lucide="database-zap" class="h-6 w-6"></i></div>
            <div><h1 class="text-lg font-extrabold leading-none text-slate-900">Raporty 2.0 - Statystyki MySQL</h1><span class="text-xs font-semibold text-slate-400">Źródło: baza danych + archiwum HTML</span></div>
        </div>
        <div class="flex items-center gap-2">
            <a href="index.php" class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50"><i data-lucide="layout-dashboard" class="h-4 w-4"></i> Dashboard</a>
            <button id="pdf-export-btn" onclick="exportStatsPdf()" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-indigo-500"><i data-lucide="file-down" class="h-4 w-4"></i> Eksport PDF</button>
        </div>
    </div>
</header>

<main id="stats-root" class="mx-auto max-w-[1800px] px-4 py-6 sm:px-6 lg:px-8">
    <?php if (!$dbOk): ?>
        <div class="rounded-2xl border border-red-100 bg-red-50 p-6 text-red-800 shadow-sm">
            <h2 class="text-lg font-extrabold">Brak połączenia z MySQL</h2>
            <p class="mt-2 text-sm">Sprawdź plik .env, kontener raport2-db oraz dane połączenia. Szczegóły: <?php echo h($errorMessage); ?></p>
        </div>
    <?php else: ?>
        <section class="mb-6 flex flex-col justify-between gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm md:flex-row md:items-center">
            <div>
                <h2 class="text-xl font-extrabold text-slate-950">Centrum analityczne SOC</h2>
                <p class="mt-1 text-sm font-medium text-slate-500">Agregacja danych z importów zapisanych w MySQL za <?php echo h($periodLabel); ?>.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <?php foreach (['3' => '3 dni', '7' => '7 dni', '30' => '30 dni', 'all' => 'Całość'] as $p => $label): ?>
                    <a href="?period=<?php echo h($p); ?>" class="rounded-xl px-4 py-2 text-xs font-extrabold <?php echo $period === $p ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'; ?>"><?php echo h($label); ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mb-8 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <div class="stat-card rounded-2xl border border-slate-100 p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Raporty w bazie</p><h3 class="mt-2 text-3xl font-extrabold text-slate-900"><?php echo fmt($metrics['reports']); ?></h3></div>
            <div class="stat-card rounded-2xl border border-slate-100 p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Zdarzenia</p><h3 class="mt-2 text-3xl font-extrabold text-indigo-600"><?php echo fmt($metrics['events']); ?></h3></div>
            <div class="stat-card rounded-2xl border border-slate-100 p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Źródłowe IP / hosty</p><h3 class="mt-2 text-3xl font-extrabold text-blue-600"><?php echo fmt($metrics['source_ips']); ?> / <?php echo fmt($metrics['hosts']); ?></h3></div>
            <div class="stat-card rounded-2xl border border-slate-100 p-6 shadow-sm"><p class="text-xs font-bold uppercase tracking-wider text-slate-400">Transfer łączny</p><h3 class="mt-2 text-3xl font-extrabold text-emerald-600"><?php echo h(fmtBytes($metrics['bytes'])); ?></h3></div>
        </section>

        <section class="mb-8 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between"><div><h3 class="text-base font-extrabold text-slate-900">Trend zdarzeń dziennych</h3><p class="text-xs font-medium text-slate-400">Suma zdarzeń z tabeli reports według daty raportu.</p></div></div>
            <canvas id="dailyChart" height="90"></canvas>
        </section>

        <section class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <?php renderTable('types', 'Typy raportów', 'Liczba zdarzeń według typu raportu', $top['report_type'] ?? [], 'folder-kanban', false, 'pieTypes'); ?>
            <?php renderTable('srcip', 'TOP Source.IP', 'Najaktywniejsze adresy źródłowe', $top['source_ip'] ?? [], 'server', false, 'pieSrcIp'); ?>
            <?php renderTable('srchost', 'TOP Source.HostName', 'Najaktywniejsze hosty źródłowe', $top['source_host'] ?? [], 'monitor', false, 'pieSrcHost'); ?>
            <?php renderTable('users', 'TOP Source.UserName', 'Użytkownicy z największą liczbą zdarzeń', $top['source_user'] ?? [], 'users', false, 'pieUsers'); ?>
            <?php renderTable('srccountry', 'TOP Source.Country', 'Kraje źródłowe', $top['source_country'] ?? [], 'globe-2', false, 'pieSourceCountry'); ?>
            <?php renderTable('dstip', 'TOP Destination.IP', 'Najczęstsze adresy docelowe', $top['destination_ip'] ?? [], 'crosshair', false, 'pieDstIp'); ?>
            <?php renderTable('dstport', 'TOP Destination.Port', 'Najczęstsze porty docelowe', $top['destination_port'] ?? [], 'unplug', false, 'piePorts'); ?>
            <?php renderTable('dstcountry', 'TOP Destination.Country', 'Kraje docelowe', $top['destination_country'] ?? [], 'flag', false, 'pieDestCountry'); ?>
            <?php renderTable('protocols', 'TOP Protocol.Name', 'Protokoły sieciowe', $top['protocol_name'] ?? [], 'network', false, 'pieProtocols'); ?>
            <?php renderTable('services', 'TOP Service.Name', 'Usługi wykryte w raportach', $top['service_name'] ?? [], 'cpu', false, 'pieServices'); ?>
            <?php renderTable('apps', 'TOP Application.Name', 'Aplikacje wykryte w ruchu', $top['application_name'] ?? [], 'boxes', false, 'pieApps'); ?>
            <?php renderTable('appcats', 'TOP Application.Category', 'Kategorie aplikacji', $top['application_category'] ?? [], 'layers', false, 'pieAppCats'); ?>
            <?php renderTable('subtypes', 'TOP EventMap.SubType', 'Typy zdarzeń logowania/akcji', $top['event_subtype'] ?? [], 'activity', false, 'pieSubtypes'); ?>
            <?php renderTable('hours', 'TOP Godziny', 'Rozkład godzinowy zdarzeń', $top['event_hour'] ?? [], 'clock', false, 'pieHours'); ?>
            <?php renderTable('transfer', 'TOP Transfer', 'Hosty i rekordy z największym transferem', $topTransfer, 'hard-drive-download', true, 'pieTransfer'); ?>
        </section>

        <section class="mt-8 rounded-2xl border border-slate-100 bg-white p-6 shadow-sm">
            <div class="mb-4 border-b border-slate-100 pb-4"><h3 class="text-sm font-extrabold uppercase tracking-wide text-slate-900">Ostatnie importy</h3><p class="mt-1 text-xs font-medium text-slate-400">Lista ostatnich raportów zapisanych w MySQL.</p></div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead><tr class="border-b border-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="py-2 pr-3">Data</th><th class="py-2 pr-3">Typ</th><th class="py-2 pr-3">Plik</th><th class="py-2 pr-3 text-right">Zdarzenia</th><th class="py-2 pr-3">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentReports as $r): ?>
                        <tr class="border-b border-slate-50"><td class="py-3 pr-3 font-mono text-xs"><?php echo h($r['report_date']); ?></td><td class="py-3 pr-3 text-xs font-bold text-indigo-700"><?php echo h($r['report_type']); ?></td><td class="py-3 pr-3 text-xs text-slate-700"><?php echo h($r['stored_filename']); ?></td><td class="py-3 pr-3 text-right text-xs font-extrabold"><?php echo fmt($r['events_total']); ?></td><td class="py-3 pr-3"><span class="rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-700"><?php echo h($r['parser_status']); ?></span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>
<script>
const chartLabels = <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>;
const chartValues = <?php echo json_encode($chartValues, JSON_UNESCAPED_UNICODE); ?>;
const pieCharts = <?php echo json_encode($pieCharts, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK); ?>;
const piePalette = ['#4f46e5','#2563eb','#0891b2','#059669','#65a30d','#ca8a04','#ea580c','#dc2626','#9333ea','#475569','#0f766e','#be123c'];
const piePercentPlugin = {
    id: 'piePercentPlugin',
    afterDatasetsDraw(chart) {
        const data = chart.data.datasets[0]?.data || [];
        const total = data.reduce((a, b) => a + Number(b || 0), 0);
        if (!total) return;
        const {ctx} = chart;
        ctx.save();
        ctx.font = '700 11px Inter, sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        chart.getDatasetMeta(0).data.forEach((arc, index) => {
            const value = Number(data[index] || 0);
            const pct = value / total * 100;
            if (pct < 4) return;
            const pos = arc.tooltipPosition();
            ctx.fillText(pct.toFixed(1).replace('.', ',') + '%', pos.x, pos.y);
        });
        ctx.restore();
    }
};
function formatNumberPl(value){
    return new Intl.NumberFormat('pl-PL', {maximumFractionDigits: 0}).format(Number(value || 0));
}
function formatBytesPl(bytes){
    const value = Number(bytes || 0);
    if (value >= 1099511627776) return (value / 1099511627776).toLocaleString('pl-PL', {maximumFractionDigits: 2}) + ' TB';
    if (value >= 1073741824) return (value / 1073741824).toLocaleString('pl-PL', {maximumFractionDigits: 2}) + ' GB';
    if (value >= 1048576) return (value / 1048576).toLocaleString('pl-PL', {maximumFractionDigits: 2}) + ' MB';
    if (value >= 1024) return (value / 1024).toLocaleString('pl-PL', {maximumFractionDigits: 2}) + ' KB';
    return value.toLocaleString('pl-PL', {maximumFractionDigits: 0}) + ' B';
}
function formatPieValue(value, unit){
    return unit === 'B' ? formatBytesPl(value) : `${formatNumberPl(value)} ${unit || 'zd.'}`;
}
function renderPieLegend(canvasId, labels, values, unit){
    const el = document.getElementById('legend-' + canvasId);
    if (!el) return;
    const total = values.reduce((a,b) => a + Number(b || 0), 0);
    if (!total) {
        el.innerHTML = '<div class="col-span-full rounded-xl bg-slate-50 p-4 text-center font-semibold text-slate-400">Brak danych</div>';
        return;
    }
    el.innerHTML = labels.map((label, index) => {
        const value = Number(values[index] || 0);
        const pct = value / total * 100;
        const color = piePalette[index % piePalette.length];
        return `<div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2">
            <span class="flex min-w-0 items-center gap-2 font-semibold text-slate-700"><span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background:${color}"></span><span class="truncate" title="${String(label).replaceAll('"','&quot;')}">${label}</span></span>
            <span class="shrink-0 text-right font-extrabold text-indigo-700">${pct.toFixed(1).replace('.', ',')}%<br><span class="text-[10px] font-bold text-slate-400">${formatPieValue(value, unit)}</span></span>
        </div>`;
    }).join('');
}
function renderPieChart(canvasId, payload){
    const canvas = document.getElementById(canvasId);
    if (!canvas || !payload) return;
    const labels = payload.labels || [];
    const values = payload.values || [];
    const unit = payload.unit || 'zd.';
    renderPieLegend(canvasId, labels, values, unit);
    if (!values.length || values.reduce((a,b) => a + Number(b || 0), 0) <= 0) return;
    new Chart(canvas, {
        type: 'doughnut',
        data: {labels, datasets: [{data: values, backgroundColor: labels.map((_, i) => piePalette[i % piePalette.length]), borderWidth: 2, borderColor: '#ffffff'}]},
        options: {
            responsive: true,
            cutout: '58%',
            plugins: {
                legend: {display: false},
                tooltip: {callbacks: {label: (ctx) => {
                    const total = ctx.dataset.data.reduce((a,b) => a + Number(b || 0), 0);
                    const value = Number(ctx.raw || 0);
                    const pct = total ? value / total * 100 : 0;
                    return `${ctx.label}: ${formatPieValue(value, unit)} (${pct.toFixed(1).replace('.', ',')}%)`;
                }}}
            }
        },
        plugins: [piePercentPlugin]
    });
}
Object.entries(pieCharts || {}).forEach(([id, payload]) => renderPieChart(id, payload));
if (document.getElementById('dailyChart')) {
    new Chart(document.getElementById('dailyChart'), {type:'line',data:{labels:chartLabels,datasets:[{label:'Zdarzenia',data:chartValues,tension:.35,fill:true}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});
}
function toggleExtraRows(btn){
    const target = btn.getAttribute('data-target');
    const rows = document.querySelectorAll('.' + target);
    const hidden = Array.from(rows).some(r => r.classList.contains('hidden'));
    rows.forEach(r => r.classList.toggle('hidden', !hidden));
    btn.innerHTML = hidden ? '<i data-lucide="chevrons-up" class="h-4 w-4"></i> Ukryj pozostałe' : '<i data-lucide="chevrons-down" class="h-4 w-4"></i> Pokaż pozostałe';
    lucide.createIcons();
}
function showPdfStatus(message, type = 'info'){
    let box = document.getElementById('pdf-export-status');
    if (!box) {
        box = document.createElement('div');
        box.id = 'pdf-export-status';
        box.className = 'fixed right-6 top-20 z-[9999] max-w-md rounded-2xl border px-5 py-4 text-sm font-bold shadow-xl transition-all';
        document.body.appendChild(box);
    }
    const classes = {
        info: 'border-indigo-100 bg-indigo-600 text-white',
        success: 'border-emerald-100 bg-emerald-600 text-white',
        error: 'border-red-100 bg-red-600 text-white'
    };
    box.className = 'fixed right-6 top-20 z-[9999] max-w-md rounded-2xl border px-5 py-4 text-sm font-bold shadow-xl transition-all ' + (classes[type] || classes.info);
    box.textContent = message;
    box.classList.remove('hidden');
    if (type !== 'info') {
        window.setTimeout(() => box.classList.add('hidden'), 4500);
    }
}
function waitForPdfLibraries(){
    return new Promise((resolve, reject) => {
        const started = Date.now();
        const timer = setInterval(() => {
            if (window.html2canvas && window.jspdf && window.jspdf.jsPDF) {
                clearInterval(timer);
                resolve();
            } else if (Date.now() - started > 10000) {
                clearInterval(timer);
                reject(new Error('Nie udało się załadować bibliotek html2canvas/jsPDF. Sprawdź dostęp do Internetu/CDN.'));
            }
        }, 100);
    });
}
async function exportStatsPdf(){
    const btn = document.getElementById('pdf-export-btn');
    const root = document.getElementById('stats-root');
    if (!root) return;
    try {
        if (btn) {
            btn.disabled = true;
            btn.classList.add('opacity-60','cursor-not-allowed');
        }
        showPdfStatus('Generowanie PDF... proszę czekać.', 'info');
        await waitForPdfLibraries();
        await new Promise(resolve => setTimeout(resolve, 500));

        const previousScroll = window.scrollY || document.documentElement.scrollTop || 0;
        window.scrollTo(0, 0);

        const canvas = await html2canvas(root, {
            scale: 1.6,
            backgroundColor: '#f8fafc',
            useCORS: true,
            allowTaint: true,
            logging: false,
            windowWidth: document.documentElement.scrollWidth,
            windowHeight: root.scrollHeight
        });

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const margin = 6;
        const imgWidth = pageWidth - margin * 2;
        const imgHeight = canvas.height * imgWidth / canvas.width;
        const pageCanvas = document.createElement('canvas');
        const pageCtx = pageCanvas.getContext('2d');
        const pageHeightPx = Math.floor((pageHeight - margin * 2) * canvas.width / imgWidth);

        pageCanvas.width = canvas.width;
        let renderedHeight = 0;
        let pageIndex = 0;

        while (renderedHeight < canvas.height) {
            const sliceHeight = Math.min(pageHeightPx, canvas.height - renderedHeight);
            pageCanvas.height = sliceHeight;
            pageCtx.clearRect(0, 0, pageCanvas.width, pageCanvas.height);
            pageCtx.drawImage(canvas, 0, renderedHeight, canvas.width, sliceHeight, 0, 0, canvas.width, sliceHeight);
            const pageImg = pageCanvas.toDataURL('image/png', 0.98);
            const pageImgHeight = sliceHeight * imgWidth / canvas.width;
            if (pageIndex > 0) pdf.addPage();
            pdf.addImage(pageImg, 'PNG', margin, margin, imgWidth, pageImgHeight);
            renderedHeight += sliceHeight;
            pageIndex++;
        }

        const fileName = 'raporty-2.0-statystyki-' + new Date().toISOString().slice(0,10) + '.pdf';
        pdf.save(fileName);
        window.scrollTo(0, previousScroll);
        showPdfStatus('PDF został wygenerowany i zapisany do pliku.', 'success');
    } catch (error) {
        console.error(error);
        showPdfStatus('Nie udało się wygenerować PDF: ' + (error?.message || error), 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.classList.remove('opacity-60','cursor-not-allowed');
        }
    }
}
lucide.createIcons();
</script>
</body>
</html>
