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
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Observing by Month - Wheathampstead AstroPhotography Conditions</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-50 to-indigo-100 dark:from-gray-800 dark:to-gray-900 text-gray-800 dark:text-gray-100 font-sans">
    <div class="max-w-4xl mx-auto p-6 bg-white/80 dark:bg-gray-800/80 backdrop-blur rounded-xl shadow-lg">
        <a href="index.php" class="mb-4 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Back to Home</a>
        <div class="flex justify-between items-center mb-6 bg-white/70 dark:bg-gray-800/70 backdrop-blur p-4 rounded-lg shadow">
            <h1 class="text-2xl font-bold">Clear Nights by Month</h1>
            <button id="modeToggle" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700">Switch to Dark Mode</button>
        </div>
        <form method="get" class="mb-6 flex items-end gap-4">
            <label class="flex flex-col">
                <span>Year</span>
                <select name="year" class="border rounded px-2 py-1 bg-white dark:bg-gray-700">
                    <?php foreach ($years as $y): ?>
                    <option value="<?= htmlspecialchars($y) ?>" <?= $y == $year ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700">Apply</button>
        </form>
        <div id="monthChart" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow"></div>
    </div>
    <script>
    const monthNames = <?php echo json_encode($monthNames); ?>;
    const monthData = <?php echo json_encode(array_values($monthHours)); ?>;
    const chart = Highcharts.chart('monthChart', {
        chart: { type: 'column' },
        title: { text: 'Safe Observing Hours in ' + <?php echo json_encode($year); ?> },
        xAxis: { categories: monthNames },
        yAxis: { title: { text: 'Hours' } },
        series: [{ name: 'Hours', data: monthData }]
    });
    const modeToggle = document.getElementById('modeToggle');
    function updateChartTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const bgColor = isDark ? '#1f2937' : '#FFFFFF';
        const gridColor = isDark ? '#374151' : '#e5e7eb';
        chart.update({
            chart: { backgroundColor: bgColor },
            title: { style: { color: textColor } },
            xAxis: { labels: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            yAxis: { labels: { style: { color: textColor } }, title: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            legend: { itemStyle: { color: textColor } }
        });
    }
    function updateModeText() {
        modeToggle.textContent = document.documentElement.classList.contains('dark') ? 'Switch to Light Mode' : 'Switch to Dark Mode';
    }
    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        updateModeText();
        updateChartTheme();
    });
    updateModeText();
    updateChartTheme();
    </script>
</body>
</html>
