<?php
$key = $_GET['topic'] ?? '';
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$unit = $config['topics'][$key]['unit'] ?? '';

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
$format = $_GET['format'] ?? '';

// Determine requested date range; if none provided, return all data
$endParam   = $_GET['end']   ?? null;
$startParam = $_GET['start'] ?? null;

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($startParam && $endParam) {
        $startDate = $startParam . ' 00:00:00';
        $endDate   = $endParam   . ' 23:59:59';
        $stmt = $pdo->prepare("SELECT dateTime AS timestamp, `$column` AS value FROM obs_weather WHERE dateTime BETWEEN :start AND :end ORDER BY dateTime ASC");
        $stmt->execute(['start' => $startDate, 'end' => $endDate]);
    } else {
        $stmt = $pdo->prepare("SELECT dateTime AS timestamp, `$column` AS value FROM obs_weather ORDER BY dateTime ASC");
        $stmt->execute();
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

if ($format === 'json') {
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History: <?php echo htmlspecialchars($key); ?><?php if ($unit) echo ' (' . htmlspecialchars($unit) . ')'; ?> - Wheathampstead AstroPhotography Conditions</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <script src="https://code.highcharts.com/stock/highstock.js"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-indigo-50 to-indigo-100 dark:from-gray-800 dark:to-gray-900 text-gray-800 dark:text-gray-100 font-sans">
    <div class="max-w-4xl mx-auto p-6 bg-white/80 dark:bg-gray-800/80 backdrop-blur rounded-xl shadow-lg">
        <a href="index.php" class="mb-4 inline-block text-indigo-600 dark:text-indigo-400 hover:underline">&larr; Back to Home</a>
        <div class="flex justify-between items-center mb-6 bg-white/70 dark:bg-gray-800/70 backdrop-blur p-4 rounded-lg shadow">

            <h1 class="text-2xl font-bold">History: <?php echo htmlspecialchars($key); ?></h1>
            <button id="modeToggle" class="p-2 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700" aria-label="Switch to Dark Mode">🌙</button>

        </div>
        
        <div id="histChart" class="mb-6 bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow"></div>
        <button id="downloadCsv" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700">Download CSV</button>
    </div>

    <script>
    const modeToggle = document.getElementById('modeToggle');
    let data = <?php echo json_encode($rows); ?>;
    const unit = <?php echo json_encode($unit); ?>;
    const chartData = data.map(r => [Date.parse(r.timestamp), parseFloat(r.value)]);

    const histChart = Highcharts.stockChart('histChart', {
        chart: { type: 'line' },
        title: { text: 'Historical Data' },
        rangeSelector: {
            selected: 3,
            buttons: [
                { type: 'day', count: 1, text: '1d' },
                { type: 'day', count: 3, text: '3d' },
                { type: 'week', count: 1, text: '1w' },
                { type: 'all', text: 'All' }
            ]
        },
        xAxis: { type: 'datetime' },
        yAxis: { title: { text: unit } },
        series: [{ name: <?php echo json_encode($key . ($unit ? ' (' . $unit . ')' : '')); ?>, data: chartData }]
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
            yAxis: { labels: { style: { color: textColor } }, title: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            rangeSelector: {
                inputStyle: { color: textColor },
                labelStyle: { color: textColor },
                buttonTheme: { style: { color: textColor } }
            },
            navigator: {
                xAxis: { labels: { style: { color: textColor } } }
            }
        });
    }

    function updateModeIcon() {
        const isDark = document.documentElement.classList.contains('dark');
        modeToggle.textContent = isDark ? '🌞' : '🌙';
        modeToggle.setAttribute('aria-label', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    }

    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        updateModeIcon();
        updateChartTheme();
    });

    updateModeIcon();
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
