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

    // Build map for last 30 days initialised to 0 hours
    $start = new DateTime('today -29 days');
    $dayMap = [];
    for ($i = 0; $i < 30; $i++) {
        $d = clone $start;
        $d->modify("+{$i} day");
        $dayMap[$d->format('Y-m-d')] = 0.0;
    }

    // Aggregate safe minutes per day
    $queryStart = $start->format('Y-m-d 00:00:00');
    $queryEnd = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("SELECT DATE(dateTime) AS day, SUM(safe)/60 AS hours FROM obs_weather WHERE dateTime BETWEEN :start AND :end GROUP BY day ORDER BY day");
    $stmt->execute(['start' => $queryStart, 'end' => $queryEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($dayMap[$row['day']])) {
            $dayMap[$row['day']] = (float)$row['hours'];
        }
    }

    // Include time from last record to now if still safe
    $rangeStart = strtotime('today -29 days');
    $lastRow = $pdo->query("SELECT dateTime, safe FROM obs_weather ORDER BY dateTime DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lastRow && (int)$lastRow['safe'] === 1) {
        $segmentStart = max(strtotime($lastRow['dateTime']), $rangeStart);
        $segmentEnd = time();
        while ($segmentStart < $segmentEnd) {
            $day = date('Y-m-d', $segmentStart);
            if (!isset($dayMap[$day])) break;
            $dayStart = strtotime($day);
            $dayEnd = $dayStart + 86400;
            $boundary = min($segmentEnd, $dayEnd);
            $dayMap[$day] += ($boundary - $segmentStart) / 3600;
            $segmentStart = $boundary;
        }
    }

    foreach ($dayMap as $day => $hours) {
        $safeData[] = ['day' => $day, 'hours' => round($hours, 2)];
    }
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
<body class="min-h-screen bg-gradient-to-br from-indigo-50 to-indigo-100 dark:from-gray-800 dark:to-gray-900 text-gray-800 dark:text-gray-100 font-sans">
    <div class="max-w-6xl mx-auto p-6">
        <div class="flex justify-between items-center mb-8 bg-white/70 dark:bg-gray-800/70 backdrop-blur p-4 rounded-lg shadow">
            <h1 class="flex items-center text-2xl font-bold">
                <img src="favicon.svg" alt="" class="w-8 h-8 mr-2">
                <span class="hidden sm:inline">Wheathampstead AstroPhotography Conditions</span>
                <span class="sm:hidden">WAPC</span>
            </h1>
            <div class="flex items-center space-x-2">
                <span id="mqttStatus" class="text-sm text-yellow-600">Connecting...</span>

                <a href="clear.php" class="text-indigo-600 dark:text-indigo-400 hover:underline">Clear by Month</a>

                <button id="modeToggle" class="p-2 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700" aria-label="Switch to Dark Mode">🌙</button>

            </div>
        </div>
        <div id="cards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6"></div>
        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div id="skyImageContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <img id="skyImage" alt="Sky image" class="flex-1 object-contain min-h-[16rem]" />
                <button data-target="skyImageContainer" class="mt-2 px-2 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700 fullscreen-btn">Full Screen</button>
            </div>
            <div id="safeChartContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <div id="safeChart" class="flex-1 min-h-[16rem]"></div>
                <button data-target="safeChartContainer" class="mt-2 px-2 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700 fullscreen-btn">Full Screen</button>
            </div>
            <div id="envChartContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <div id="envChart" class="flex-1 min-h-[16rem]"></div>
                <button data-target="envChartContainer" class="mt-2 px-2 py-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700 fullscreen-btn">Full Screen</button>
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

const envTopicNames = ['clouds', 'light', 'sqm'];
const envSeriesMap = {};
envTopicNames.forEach((name, idx) => {
    if (topics[name]) envSeriesMap[topics[name].topic] = idx;
});
const envSeries = envTopicNames.map(name => {
    const cfg = topics[name] || {};
    const unit = cfg.unit ? ` (${cfg.unit})` : '';
    return { name: name.charAt(0).toUpperCase() + name.slice(1) + unit, data: [] };
});

    const cardsContainer = document.getElementById('cards');
    cardsContainer.innerHTML = '';
    const sanitize = name => name.replace(/[^a-zA-Z0-9_-]/g, '_');

    const icons = {
        temperature: '🌡️',
        rain: '🌧️',
        light: '💡',
        clouds: '☁️',
        safe: '🛡️',
        sqm: '⭐',
        humidity: '💧',
        dewpoint: '❄️'
    };

    topicEntries.forEach(([name, cfg]) => {
        const topic = cfg.topic;
        const id = 'value-' + sanitize(name);
        const card = document.createElement('div');

        card.id = 'card-' + sanitize(name);
        card.className = 'bg-gray-100 dark:bg-gray-800 p-4 rounded shadow h-32 flex border-4 border-transparent';
        const icon = icons[name] || '📟';
        const unitMarkup = cfg.unit ? `<span class="text-2xl ml-1">${cfg.unit}</span>` : '';
        card.innerHTML = `
            <div class="flex flex-col justify-between w-1/2">
                <h2 class="text-xl font-semibold flex items-center"><span class="mr-2">${icon}</span>${name}</h2>

                <div class="mt-2 flex space-x-2">
                    <a href="historical.php?topic=${encodeURIComponent(name)}" class="p-1 rounded bg-indigo-500 text-white hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-700 inline-flex" aria-label="View History">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M6 15l4-4 3 3 7-7" />
                        </svg>
                    </a>
                </div>
            </div>
            <div class="w-1/2 flex items-center justify-center">
                <p class="text-right text-6xl leading-none flex items-baseline justify-end"><span id="${id}">--</span>${unitMarkup}</p>
            </div>

        `;
        cardsContainer.appendChild(card);
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
        if (topic === 'Observatory/skyimage') {
            const img = document.getElementById('skyImage');
            img.src = 'data:image/jpeg;base64,' + message.toString();
            return;
        }
        const value = parseFloat(message.toString());
        const entry = topicEntries.find(([, cfg]) => cfg.topic === topic);
        if (entry) {
            const [name, cfg] = entry;
            const id = 'value-' + sanitize(name);
            const el = document.getElementById(id);
            if (el) { el.textContent = value; }
            const card = document.getElementById('card-' + sanitize(name));
            if (card && cfg.green !== undefined && cfg.condition) {
                let match = false;
                if (cfg.condition === 'above') match = value > cfg.green;
                else if (cfg.condition === 'below') match = value < cfg.green;
                if (match) {
                    card.classList.remove('border-transparent');
                    card.classList.add('border-green-500');
                } else {
                    card.classList.remove('border-green-500');
                    card.classList.add('border-transparent');
                }
            }
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
        Object.values(topics).forEach(cfg => client.subscribe(cfg.topic));
        client.subscribe('Observatory/skyimage');
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
        series: envSeries
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
        Highcharts.charts.forEach(c => { if (c) c.reflow(); });
    });

    const modeToggle = document.getElementById('modeToggle');

    function updateChartsTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const bgColor = isDark ? '#1f2937' : '#FFFFFF';
        const gridColor = isDark ? '#374151' : '#e5e7eb';
        [safeChart, envChart].forEach(c => c.update({
            chart: { backgroundColor: bgColor },
            title: { style: { color: textColor } },
            xAxis: { labels: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            yAxis: { labels: { style: { color: textColor } }, title: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
            legend: { itemStyle: { color: textColor } }
        }, false));
        [safeChart, envChart].forEach(c => c.redraw());
    }

    function updateModeIcon() {
        const isDark = document.documentElement.classList.contains('dark');
        modeToggle.textContent = isDark ? '🌞' : '🌙';
        modeToggle.setAttribute('aria-label', isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode');
    }

    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
        updateModeIcon();
        updateChartsTheme();
    });

    updateModeIcon();
    updateChartsTheme();
    </script>
</body>
</html>
