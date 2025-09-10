<?php
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$host = $config['host'] ?? 'localhost';
$topics = $config['topics'] ?? [];

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

$safeData = [];
try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("SELECT DATE(FROM_UNIXTIME(dateTime)) AS day, SUM(safe)/60 AS hours FROM obs_weather WHERE dateTime >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 30 DAY)) GROUP BY day ORDER BY day");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $start = new DateTime('-29 days');
    for ($i = 0; $i < 30; $i++) {
        $d = clone $start;
        $d->modify("+{$i} day");
        $safeData[$d->format('Y-m-d')] = 0;
    }
    foreach ($rows as $row) {
        $safeData[$row['day']] = round((float)$row['hours'], 2);
    }
    $tmp = [];
    foreach ($safeData as $day => $hours) {
        $tmp[] = ['day' => $day, 'hours' => $hours];
    }
    $safeData = $tmp;
} catch (Exception $e) {
    $safeData = [];
}
?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PubObs Live Data</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <!-- Highcharts -->
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <!-- MQTT over WebSocket -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/paho-mqtt/1.1.0/mqttws31.min.js"></script>
</head>
<body class="h-full bg-white text-gray-800 dark:bg-gray-900 dark:text-gray-100">
    <div class="container mx-auto p-4">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold">PubObs Live Data</h1>
            <div class="flex items-center space-x-2">
                <span id="mqttStatus" class="text-sm text-yellow-600">Connecting...</span>
                <button id="modeToggle" class="px-2 py-1 border rounded">Toggle Mode</button>
            </div>
        </div>
        <div id="cards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
        <div id="liveChart" class="mt-6"></div>
        <div id="safeChart" class="mt-6"></div>
    </div>

    <script>
    const topics = <?php echo json_encode($topics); ?>;
    const host = <?php echo json_encode($host); ?>;
    const safeData = <?php echo json_encode($safeData); ?>;
    const port = 8083; // default WebSocket port for MQTT
    const brokerHost = (host === 'localhost' || host === '127.0.0.1') ? window.location.hostname : host;
    const topicEntries = Object.entries(topics);
    let selectedName = topicEntries.length ? topicEntries[0][0] : '';
    let selectedTopic = topicEntries.length ? topicEntries[0][1] : null;

    const cardsContainer = document.getElementById('cards');
    const sanitize = name => name.replace(/[^a-zA-Z0-9_-]/g, '_');
const icons = {
        temperature: "🌡️",
        rain: "🌧️",
        light: "💡",
        clouds: "☁️",
        safe: "✅",
        sqm: "🌌",
        humidity: "💧",
        dewpoint: "🧊"
    };


    topicEntries.forEach(([name, topic]) => {
        const id = 'value-' + sanitize(name);
        const card = document.createElement('div');
        card.className = 'bg-gray-100 dark:bg-gray-800 p-4 rounded shadow';
        card.innerHTML = `
            <div class="flex items-center space-x-2">
                <span class="text-2xl">${icons[name] || '📈'}</span>
                <h2 class="text-xl font-semibold">${name}</h2>
            </div>
            <p id="${id}" class="text-2xl mt-2">--</p>
            <div class="mt-2 flex space-x-2">
                <a href="historical.php?topic=${encodeURIComponent(name)}" class="text-blue-500">History</a>
                <button class="px-2 py-1 bg-blue-500 text-white rounded show-chart" data-topic="${topic}" data-name="${name}">Show</button>
            </div>
        `;
        cardsContainer.appendChild(card);
    });

    document.addEventListener('click', e => {
        if (e.target.classList.contains('show-chart')) {
            selectedTopic = e.target.dataset.topic;
            selectedName = e.target.dataset.name;
            chart.series[0].setData([]);
            chart.setTitle({ text: 'Live Sensor Data: ' + selectedName });
        }
    });

    const statusEl = document.getElementById('mqttStatus');
    let client;

    function onConnectionLost() {
        console.log('Connection lost');
        statusEl.textContent = 'Disconnected';
        statusEl.className = 'text-sm text-red-600';
    }
    function onMessageArrived(message) {
        const topic = message.destinationName;
        const value = parseFloat(message.payloadString);
        const entry = topicEntries.find(([, t]) => t === topic);
        if (entry) {
            const id = 'value-' + sanitize(entry[0]);
            const el = document.getElementById(id);
            if (el) { el.textContent = value; }
        }
        if (topic === selectedTopic) {
            const x = (new Date()).getTime();
            chart.series[0].addPoint([x, value], true, chart.series[0].data.length > 40);
        }
    }

    function onConnect() {
        statusEl.textContent = 'Connected';
        statusEl.className = 'text-sm text-green-600';
        Object.values(topics).forEach(t => client.subscribe(t));
    }
    function onFail() {
        statusEl.textContent = 'Disconnected';
        statusEl.className = 'text-sm text-red-600';
    }

    if (window.Paho?.MQTT?.Client) {
        client = new Paho.MQTT.Client(brokerHost, port, "webclient-" + Math.random());
        client.onConnectionLost = onConnectionLost;
        client.onMessageArrived = onMessageArrived;
        client.connect({ onSuccess: onConnect, onFailure: onFail, useSSL: location.protocol === 'https:' });
    } else {
        console.error('Paho MQTT library is not loaded');
        statusEl.textContent = 'MQTT unavailable';
        statusEl.className = 'text-sm text-red-600';
    }

    const chart = Highcharts.chart('liveChart', {
        chart: { type: 'spline' },
        title: { text: selectedName ? 'Live Sensor Data: ' + selectedName : 'Live Sensor Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: 'Value', data: [] }]
    });

    const safeCategories = safeData.map(r => r.day);
    const safeHours = safeData.map(r => parseFloat(r.hours));
    Highcharts.chart('safeChart', {
        chart: { type: 'column' },
        title: { text: 'Observable Hours (Last 30 Days)' },
        xAxis: { categories: safeCategories },
        yAxis: { title: { text: 'Hours' } },
        series: [{ name: 'Hours', data: safeHours }]
    });

    const modeToggle = document.getElementById('modeToggle');
    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
    });
    </script>
</body>
</html>
