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
    // `safe` stores seconds of safe observing time per record.
    // Convert the summed seconds to hours for the chart.
    $stmt = $pdo->prepare("SELECT DATE(FROM_UNIXTIME(dateTime)) AS day, SUM(safe)/3600 AS hours FROM obs_weather WHERE dateTime >= UNIX_TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 30 DAY)) GROUP BY day ORDER BY day");
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
    <title>Wheathampstead AstroPhotography Conditions</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
    </script>
    <!-- Highcharts -->
    <script src="https://code.highcharts.com/highcharts.js"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-800 dark:to-gray-900 text-gray-800 dark:text-gray-100 font-sans">
    <div class="max-w-6xl mx-auto p-6">
        <div class="flex justify-between items-center mb-8 bg-white/70 dark:bg-gray-800/70 backdrop-blur p-4 rounded-lg shadow">
            <h1 class="text-2xl font-bold">Wheathampstead AstroPhotography Conditions</h1>
            <div class="flex items-center space-x-2">
                <span id="mqttStatus" class="text-sm text-yellow-600">Connecting...</span>
                <button id="modeToggle" class="px-3 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700">Switch to Dark Mode</button>
            </div>
        </div>
        <div id="cards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"></div>
        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div id="liveChartContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <div id="liveChart" class="h-64"></div>
                <button data-target="liveChartContainer" class="mt-2 px-2 py-1 bg-blue-500 text-white rounded fullscreen-btn">Full Screen</button>
            </div>
            <div id="safeChartContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <div id="safeChart" class="h-64"></div>
                <button data-target="safeChartContainer" class="mt-2 px-2 py-1 bg-blue-500 text-white rounded fullscreen-btn">Full Screen</button>
            </div>
            <div id="envChartContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <div id="envChart" class="h-64"></div>
                <button data-target="envChartContainer" class="mt-2 px-2 py-1 bg-blue-500 text-white rounded fullscreen-btn">Full Screen</button>
            </div>
        </div>
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

const envTopicNames = ['clouds', 'light', 'sqm'];
const envSeriesMap = {};
envTopicNames.forEach((name, idx) => {
    if (topics[name]) envSeriesMap[topics[name]] = idx;
});

    const cardsContainer = document.getElementById('cards');
    cardsContainer.innerHTML = '';
    const sanitize = name => name.replace(/[^a-zA-Z0-9_-]/g, '_');

    topicEntries.forEach(([name, topic]) => {
        const id = 'value-' + sanitize(name);
        const card = document.createElement('div');

        card.className = 'bg-gray-100 dark:bg-gray-800 p-4 rounded shadow h-32 flex';
        card.innerHTML = `
            <div class="flex flex-col justify-between w-1/2">
                <h2 class="text-xl font-semibold">${name}</h2>

                <div class="mt-2 flex space-x-2">
                    <a href="historical.php?topic=${encodeURIComponent(name)}" class="text-blue-500">History</a>
                    <button class="px-2 py-1 bg-blue-500 text-white rounded show-chart" data-topic="${topic}" data-name="${name}">Show</button>
                </div>
            </div>
            <div class="w-1/2 flex items-center justify-center">
                <p id="${id}" class="text-right text-6xl leading-none">--</p>
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
    let connectAttempts = 0;

    function updateStatus(text, cls) {
        statusEl.textContent = text;
        statusEl.className = 'text-sm ' + cls;
    }

    function scheduleReconnect() {
        const delay = Math.min(1000 * Math.pow(2, connectAttempts), 30000);
        updateStatus('Reconnecting...', 'text-yellow-600');
        setTimeout(() => {
            connectAttempts++;
            connectClient();
        }, delay);
    }

    function connectClient() {
        if (!window.mqtt) {
            console.warn('MQTT.js library is not loaded');
            updateStatus('MQTT unavailable', 'text-red-600');
            return;
        }
        const protocol = location.protocol === 'https:' ? 'wss' : 'ws';
        client = mqtt.connect(`${protocol}://${brokerHost}:${port}`, {
            reconnectPeriod: 0,
            clientId: 'webclient-' + Math.random()
        });
        client.on('connect', onConnect);
        client.on('message', onMessageArrived);
        client.on('close', onConnectionLost);
    }

    function onConnectionLost() {
        console.log('Connection lost');
        updateStatus('Disconnected', 'text-red-600');
        scheduleReconnect();
    }
    function onMessageArrived(topic, message) {
        const value = parseFloat(message.toString());
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
        const envIndex = envSeriesMap[topic];
        if (envIndex !== undefined) {
            const x = (new Date()).getTime();
            envChart.series[envIndex].addPoint([x, value], true, envChart.series[envIndex].data.length > 40);
        }
    }
    function onConnect() {
        updateStatus('Connected', 'text-green-600');
        connectAttempts = 0;
        Object.values(topics).forEach(t => client.subscribe(t));
    }

    function loadMQTT(urls, idx = 0) {
        if (idx >= urls.length) {
            console.warn('MQTT.js library failed to load');
            updateStatus('MQTT unavailable', 'text-red-600');
            return;
        }
        const script = document.createElement('script');
        script.src = urls[idx];
        script.onload = connectClient;
        script.onerror = () => loadMQTT(urls, idx + 1);
        document.head.appendChild(script);
    }

    loadMQTT([
        'https://unpkg.com/mqtt/dist/mqtt.min.js',
        'https://cdn.jsdelivr.net/npm/mqtt/dist/mqtt.min.js'
    ]);

    const chart = Highcharts.chart('liveChart', {
        chart: { type: 'spline' },
        title: { text: selectedName ? 'Live Sensor Data: ' + selectedName : 'Live Sensor Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: 'Value', data: [] }]
    });

    const safeCategories = safeData.map(r => r.day);
    const safeHours = safeData.map(r => parseFloat(r.hours));
    const safeChart = Highcharts.chart('safeChart', {
        chart: { type: 'column' },
        title: { text: 'Observable Hours (Last 30 Days)' },
        xAxis: { categories: safeCategories },
        yAxis: { title: { text: 'Hours' } },
        series: [{ name: 'Hours', data: safeHours }]
    });

    const envChart = Highcharts.chart('envChart', {
        chart: { type: 'spline' },
        title: { text: 'Realtime Clouds, Light, SQM' },
        xAxis: { type: 'datetime' },
        series: [
            { name: 'Clouds', data: [] },
            { name: 'Light', data: [] },
            { name: 'SQM', data: [] }
        ]
    });

    document.querySelectorAll('.fullscreen-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            if (document.fullscreenElement === target) {
                document.exitFullscreen();
            } else {
                target.requestFullscreen();
            }
        });
    });

    document.addEventListener('fullscreenchange', () => {
        document.querySelectorAll('.fullscreen-btn').forEach(btn => {
            const target = document.getElementById(btn.dataset.target);
            btn.textContent = document.fullscreenElement === target ? 'Exit Full Screen' : 'Full Screen';
        });
    });

    const modeToggle = document.getElementById('modeToggle');

    function updateChartsTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const bgColor = isDark ? '#1f2937' : '#FFFFFF';
        const gridColor = isDark ? '#374151' : '#e5e7eb';
        [chart, safeChart, envChart].forEach(c => c.update({
            chart: { backgroundColor: bgColor },
            title: { style: { color: textColor } },
            xAxis: { labels: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            yAxis: { labels: { style: { color: textColor } }, title: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            legend: { itemStyle: { color: textColor } }
        }, false));
        [chart, safeChart, envChart].forEach(c => c.redraw());
    }

    function updateModeText() {
        modeToggle.textContent = document.documentElement.classList.contains('dark') ? 'Switch to Light Mode' : 'Switch to Dark Mode';
    }
    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        updateModeText();
        updateChartsTheme();
    });

    updateModeText();
    updateChartsTheme();
    </script>
</body>
</html>
