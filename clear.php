<?php
$yearParam = $_GET['year'] ?? date('Y');
$year = (int)$yearParam;

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

$monthHours = array_fill(1, 12, 0.0);
$years = [];
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $years = $pdo->query("SELECT DISTINCT YEAR(dateTime) AS y FROM obs_weather ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    $start = $year . '-01-01 00:00:00';
    $end = ($year + 1) . '-01-01 00:00:00';

    // Aggregate safe minutes per month
    $stmt = $pdo->prepare("SELECT MONTH(dateTime) AS month, SUM(safe)/60 AS hours FROM obs_weather WHERE dateTime BETWEEN :start AND :end GROUP BY month");
    $stmt->execute(['start' => $start, 'end' => $end]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $m = (int)$row['month'];
        if (isset($monthHours[$m])) {
            $monthHours[$m] = (float)$row['hours'];
        }
    }

    // Include time from the last record to period end if still safe
    $periodEnd = min(strtotime($end), time());
    $lastStmt = $pdo->prepare("SELECT dateTime, safe FROM obs_weather WHERE dateTime BETWEEN :start AND :periodEnd ORDER BY dateTime DESC LIMIT 1");
    $lastStmt->execute(['start' => $start, 'periodEnd' => date('Y-m-d H:i:s', $periodEnd)]);
    $lastRow = $lastStmt->fetch(PDO::FETCH_ASSOC);
    if ($lastRow && (int)$lastRow['safe'] === 1) {
        $rangeStart = strtotime($start);
        $segmentStart = max(strtotime($lastRow['dateTime']), $rangeStart);
        $segmentEnd = $periodEnd;
        while ($segmentStart < $segmentEnd) {
            $month = (int)date('n', $segmentStart);
            if (!isset($monthHours[$month])) break;
            $monthStart = strtotime(date('Y-m-01', $segmentStart));
            $nextMonthStart = strtotime('+1 month', $monthStart);
            $boundary = min($segmentEnd, $nextMonthStart);
            $monthHours[$month] += ($boundary - $segmentStart) / 3600;
            $segmentStart = $boundary;
        }
    }
} catch (Exception $e) {
    $monthHours = array_fill(1, 12, 0.0);
}
// Round hours for output
$monthHours = array_map(function ($h) { return round($h, 2); }, $monthHours);
$monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

require_once 'layout.php';

$pageTitle = 'Clear Observing by Month - Wheathampstead AstroPhotography Conditions';
$heroTitle = 'Monthly Clear Sky Hours';
$heroSubtitle = 'Review the cumulative safe observing time recorded by the observatory for each month.';
$heroAside = '<div class="flex flex-col items-start gap-2">'
    . '<span class="text-xs font-semibold uppercase tracking-widest text-indigo-500 dark:text-indigo-300">Selected Year</span>'
    . '<span class="text-3xl font-bold text-gray-900 dark:text-gray-100">' . htmlspecialchars((string)$year, ENT_QUOTES) . '</span>'
    . '</div>';
$navActions = '<a href="historical.php?topic=safe" class="inline-flex items-center gap-2 rounded-full border border-indigo-200/70 bg-white/70 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:border-indigo-300 hover:text-indigo-700 dark:border-indigo-700/60 dark:bg-gray-800/60 dark:text-indigo-200 dark:hover:text-indigo-100">'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5">'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21h-2.5A2.75 2.75 0 0 1 3 18.25v-2.5m18 0v2.5A2.75 2.75 0 0 1 18.25 21h-2.5" />'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M3 5.75v-2A.75.75 0 0 1 3.75 3h2a.75.75 0 0 1 .75.75V6M18.75 3h1.5a.75.75 0 0 1 .75.75v1.5M21 18v.75a.75.75 0 0 1-.75.75H18" />'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M6 6h12v12H6z" />'
    . '</svg>'
    . '<span>Safe Trend</span>'
    . '</a>';

layout_start($pageTitle, $heroTitle, $heroSubtitle, [
    'extraHead' => '<script src="https://code.highcharts.com/highcharts.js"></script>',
    'navActions' => $navActions,
    'heroAside' => $heroAside,
]);
?>
<section>
    <div class="space-y-6 rounded-3xl bg-white/70 p-6 shadow dark:bg-gray-800/70">
        <div class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
            <div class="space-y-1">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Compare observing seasons</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">Select a year to chart monthly totals of safe observing hours.</p>
            </div>
            <form method="get" class="grid grid-cols-1 gap-3 sm:grid-cols-[auto_auto] sm:items-end">
                <label class="flex flex-col gap-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span>Observation year</span>
                    <select name="year" class="w-full rounded-xl border border-indigo-200 bg-white/80 px-3 py-2 text-base font-semibold text-gray-800 shadow-sm transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-200 dark:border-indigo-700/50 dark:bg-gray-900/60 dark:text-gray-100 dark:focus:border-indigo-400 dark:focus:ring-indigo-600/40">
                        <?php foreach ($years as $y): ?>
                            <option value="<?= htmlspecialchars($y) ?>" <?= $y == $year ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-full bg-indigo-500 px-5 py-2 text-sm font-semibold text-white shadow transition hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-500">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12h15m-15 0 4.5 4.5M4.5 12l4.5-4.5" />
                    </svg>
                    <span>Update</span>
                </button>
            </form>
        </div>
        <div id="monthChart" class="h-[28rem] w-full"></div>
    </div>
</section>
<?php
$monthNamesJson = json_encode($monthNames);
$monthDataJson = json_encode(array_values($monthHours));
$yearJson = json_encode($year);

$script = <<<SCRIPT
<script>
const monthNames = {$monthNamesJson};
const monthData = {$monthDataJson};
const chartYear = {$yearJson};

const chart = Highcharts.chart('monthChart', {
    chart: {
        type: 'column',
        backgroundColor: 'transparent',
        style: { fontFamily: 'inherit' }
    },
    title: { text: null },
    credits: { enabled: false },
    legend: { enabled: false },
    xAxis: {
        categories: monthNames,
        lineColor: 'transparent',
        tickColor: 'transparent'
    },
    yAxis: {
        title: { text: 'Safe observing hours' },
        gridLineColor: '#E5E7EB'
    },
    tooltip: {
        valueSuffix: ' hrs'
    },
    plotOptions: {
        column: {
            borderRadius: 6,
            pointPadding: 0.1,
            borderWidth: 0
        }
    },
    series: [{
        name: 'Safe hours in ' + chartYear,
        data: monthData,
        color: '#4F46E5'
    }]
});

function updateChartTheme() {
    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#F9FAFB' : '#1F2937';
    const gridColor = isDark ? '#374151' : '#E5E7EB';
    chart.update({
        xAxis: {
            labels: { style: { color: textColor } },
            lineColor: 'transparent',
            tickColor: 'transparent'
        },
        yAxis: {
            labels: { style: { color: textColor } },
            title: { style: { color: textColor } },
            gridLineColor: gridColor
        },
        tooltip: {
            backgroundColor: isDark ? 'rgba(17, 24, 39, 0.9)' : 'rgba(255, 255, 255, 0.9)',
            style: { color: textColor }
        },
        series: [{
            color: '#4F46E5'
        }]
    }, false);
    chart.redraw();
}

document.addEventListener('themechange', updateChartTheme);
updateChartTheme();
</script>
SCRIPT;

layout_end($script);
