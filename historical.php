<?php
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$topics = $config['topics'] ?? [];
$key = $_GET['topic'] ?? '';
if (!array_key_exists($key, $topics)) {
    http_response_code(404);
    echo 'Unknown topic';
    exit;
}
$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare('SELECT timestamp, value FROM sensor_data WHERE topic = ? ORDER BY timestamp DESC LIMIT 100');
    $stmt->execute([$key]);
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <link href="https://unpkg.com/tabulator-tables@5.4.4/dist/css/tabulator.min.css" rel="stylesheet">
    <script src="https://unpkg.com/tabulator-tables@5.4.4/dist/js/tabulator.min.js"></script>
</head>
<body class="h-full bg-white text-gray-800 dark:bg-gray-900 dark:text-gray-100">
    <div class="container mx-auto p-4">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold">History: <?php echo htmlspecialchars($key); ?></h1>
            <button id="modeToggle" class="px-2 py-1 border rounded">Toggle Mode</button>
        </div>
        <div id="histChart" class="mb-6"></div>
        <div id="histTable"></div>
    </div>

    <script>
    const modeToggle = document.getElementById('modeToggle');
    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
    });
    const data = <?php echo json_encode($rows); ?>;
    const chartData = data.map(r => [Date.parse(r.timestamp), parseFloat(r.value)]).reverse();
    Highcharts.chart('histChart', {
        chart: { type: 'line' },
        title: { text: 'Historical Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: <?php echo json_encode($key); ?>, data: chartData }]
    });
    new Tabulator('#histTable', {
        data: data,
        layout: 'fitColumns',
        columns: [
            { title: 'Timestamp', field: 'timestamp' },
            { title: 'Value', field: 'value' }
        ]
    });
    </script>
</body>
</html>
