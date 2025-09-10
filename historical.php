<?php
$key = $_GET['topic'] ?? '';

// Map friendly topic names to obs_weather columns
$columnMap = [
    'temperature' => 'temp',
    'rain' => 'rain',
    'light' => 'light',
    'clouds' => 'clouds',
    'safe' => 'safe',
    'humidity' => 'hum',
    'dewpoint' => 'dewp',
    'wind' => 'wind',
    'gust' => 'gust',
    'switch' => 'switch',
    'sqm' => 'light'
];

$column = $columnMap[$key] ?? null;
if (!$column) {
    http_response_code(404);
    echo 'Unknown topic';
    exit;
}
$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

// Determine requested date range; default to the last 7 days and cap span to one week
$endParam = $_GET['end'] ?? null;
$startParam = $_GET['start'] ?? null;
$end   = $endParam ?: date('Y-m-d');
$start = $startParam ?: date('Y-m-d', strtotime($end . ' -1 week'));

$startTs = strtotime($start);
$endTs   = strtotime($end);
// If the requested range exceeds one week, limit to the most recent seven days
if ($endTs - $startTs > 7 * 24 * 60 * 60) {
    $startTs = $endTs - 7 * 24 * 60 * 60;
    $start = date('Y-m-d', $startTs);
}

// Convert to timestamps compatible with the database
$startDate = $start . ' 00:00:00';
$endDate   = $end   . ' 23:59:59';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT dateTime AS timestamp, `$column` AS value FROM obs_weather WHERE dateTime BETWEEN :start AND :end ORDER BY dateTime ASC");
    $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History: <?php echo htmlspecialchars($key); ?></title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
</head>
<body class="h-full bg-white text-gray-800 dark:bg-gray-900 dark:text-gray-100">
    <div class="container mx-auto p-4">
        <a href="index.php" class="mb-4 inline-block text-blue-600 dark:text-blue-400 underline">&larr; Back to Home</a>
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold">History: <?php echo htmlspecialchars($key); ?></h1>
            <button id="modeToggle" class="px-2 py-1 border rounded">Switch to Dark Mode</button>
        </div>
        <form method="get" class="mb-4 flex flex-wrap items-end gap-2">
            <input type="hidden" name="topic" value="<?php echo htmlspecialchars($key); ?>">
            <label class="flex flex-col">
                <span>Start</span>
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="border rounded px-2 py-1">
            </label>
            <label class="flex flex-col">
                <span>End</span>
                <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="border rounded px-2 py-1">
            </label>
            <button type="submit" class="px-2 py-1 border rounded">Apply</button>
        </form>
        <div id="histChart" class="mb-6"></div>
        <button id="downloadCsv" class="px-2 py-1 border rounded">Download CSV</button>
    </div>

    <script>
    const modeToggle = document.getElementById('modeToggle');
    const data = <?php echo json_encode($rows); ?>;
    const chartData = data.map(r => [Date.parse(r.timestamp), parseFloat(r.value)]);
    const histChart = Highcharts.chart('histChart', {
        chart: { type: 'line' },
        title: { text: 'Historical Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: <?php echo json_encode($key); ?>, data: chartData }]
    });

    function updateChartTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const bgColor = isDark ? '#1f2937' : '#FFFFFF';
        const gridColor = isDark ? '#374151' : '#e5e7eb';
        histChart.update({
            chart: { backgroundColor: bgColor },
            title: { style: { color: textColor } },
            xAxis: { labels: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            yAxis: { labels: { style: { color: textColor } }, title: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor }
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

    document.getElementById('downloadCsv').addEventListener('click', () => {
        const csvRows = ['timestamp,value'];
        data.forEach(r => {
            csvRows.push(`${r.timestamp},${r.value}`);
        });
        const blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = <?php echo json_encode($key . '_history.csv'); ?>;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });
    </script>
</body>
</html>
