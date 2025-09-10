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
<html lang="en" data-theme="dark" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History: <?php echo htmlspecialchars($key); ?> - Wheathampstead AstroPhotography Conditions</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <script>
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', systemTheme);
    </script>
    <!-- Tailwind CSS with daisyUI -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/daisyui@4.6.0/dist/full.min.js"></script>
    <script>
        tailwind.config = {
            plugins: [daisyui],
            daisyui: {
                themes: ["light", "dark", "dracula"],
                darkTheme: "dark",
            },
        }
    </script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans">
    <div class="max-w-4xl mx-auto p-6 bg-base-100/80 backdrop-blur rounded-xl shadow-lg">
        <a href="index.php" class="mb-4 inline-block text-primary hover:underline">&larr; Back to Home</a>
        <div class="flex justify-between items-center mb-6 bg-base-100/70 backdrop-blur p-4 rounded-lg shadow">
            <h1 class="text-2xl font-bold">History: <?php echo htmlspecialchars($key); ?></h1>
            <button id="themeToggle" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600">Switch to Dracula Theme</button>
        </div>
        <form method="get" class="mb-6 flex flex-wrap items-end gap-4">
            <input type="hidden" name="topic" value="<?php echo htmlspecialchars($key); ?>">
            <label class="flex flex-col">
                <span>Start</span>
                <input type="date" name="start" value="<?php echo htmlspecialchars($start); ?>" class="border rounded px-2 py-1 bg-base-100">
            </label>
            <label class="flex flex-col">
                <span>End</span>
                <input type="date" name="end" value="<?php echo htmlspecialchars($end); ?>" class="border rounded px-2 py-1 bg-base-100">
            </label>
            <button type="submit" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600">Apply</button>
        </form>
        <div id="histChart" class="mb-6 bg-base-100/70 p-4 rounded-xl shadow"></div>
        <button id="downloadCsv" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600">Download CSV</button>
    </div>

    <script>
    const themeToggle = document.getElementById('themeToggle');
    const data = <?php echo json_encode($rows); ?>;
    const chartData = data.map(r => [Date.parse(r.timestamp), parseFloat(r.value)]);
    const histChart = Highcharts.chart('histChart', {
        chart: { type: 'line' },
        title: { text: 'Historical Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: <?php echo json_encode($key); ?>, data: chartData }]
    });

    const themeColors = {
        light: { text: '#1F2937', bg: '#FFFFFF', grid: '#e5e7eb', series: ['#2563EB'] },
        dark: { text: '#F9FAFB', bg: '#1f2937', grid: '#374151', series: ['#3b82f6'] },
        dracula: { text: '#F8F8F2', bg: '#282A36', grid: '#44475a', series: ['#BD93F9'] }
    };

    function updateChartTheme() {
        const theme = document.documentElement.getAttribute('data-theme') || 'dark';
        const colors = themeColors[theme] || themeColors.dark;
        histChart.update({
            chart: { backgroundColor: colors.bg },
            title: { style: { color: colors.text } },
            xAxis: { labels: { style: { color: colors.text } }, gridLineColor: colors.grid, lineColor: colors.text },
            yAxis: { labels: { style: { color: colors.text } }, title: { style: { color: colors.text } }, gridLineColor: colors.grid, lineColor: colors.text }
        }, false);
        histChart.series[0].update({ color: colors.series[0] }, false);
        histChart.redraw();
    }

    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        updateToggleText();
        updateChartTheme();
    }

    function updateToggleText() {
        const current = document.documentElement.getAttribute('data-theme');
        themeToggle.textContent = current === 'dracula' ? 'Switch to Dark Theme' : 'Switch to Dracula Theme';
    }

    themeToggle.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme');
        if (current === 'light') {
            setTheme('dark');
        } else if (current === 'dark') {
            setTheme('dracula');
        } else {
            setTheme('dark');
        }
    });

    updateToggleText();
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
