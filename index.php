<?php
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$host = $config['host'] ?? 'localhost';
$topics = $config['topics'] ?? [];

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

$safeData = [];
$todaySafeHours = null;
$today = date('Y-m-d');
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
        $rounded = round($hours, 2);
        $safeData[] = ['day' => $day, 'hours' => $rounded];
        if ($day === $today) {
            $todaySafeHours = $rounded;
        }
    }
} catch (Exception $e) {
    $safeData = [];
    $todaySafeHours = null;
}
$todaySafeHoursDisplay = $todaySafeHours !== null ? number_format($todaySafeHours, 2) : '--';
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
        <section class="relative mb-8 overflow-hidden rounded-3xl bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 text-white shadow-xl dark:from-indigo-600 dark:via-purple-700 dark:to-pink-700">
            <div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.7),transparent_60%)]"></div>
            <div class="relative px-6 py-10 sm:px-10">
                <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center gap-3">
                            <img src="favicon.svg" alt="" class="h-12 w-12 rounded-full bg-white/20 p-2 shadow-lg" />
                            <div>
                                <p class="text-xs uppercase tracking-[0.3em] text-white/80">Wheathampstead Observatory</p>
                                <h1 class="text-3xl font-semibold sm:text-4xl">Wheathampstead AstroPhotography Conditions</h1>
                            </div>
                        </div>
                        <p class="max-w-xl text-base text-white/90 sm:text-lg">Live observatory telemetry, safety insights, and the latest sky conditions to plan your next observing session.</p>
                    </div>
                    <div class="flex flex-col items-stretch gap-6">
                        <div class="flex flex-wrap items-center justify-start gap-3 lg:justify-end">
                            <span id="mqttStatus" class="inline-flex items-center gap-2 rounded-full bg-amber-100/90 px-4 py-2 text-sm font-semibold text-amber-800 shadow-sm ring-1 ring-white/50 backdrop-blur">Connecting...</span>
                            <button id="modeToggle" class="inline-flex items-center gap-2 rounded-full bg-white/20 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/30 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70 focus-visible:ring-offset-2 focus-visible:ring-offset-transparent" aria-label="Switch to Dark Mode">
                                🌙 <span class="hidden sm:inline">Dark mode</span>
                            </button>
                        </div>
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-stretch sm:justify-end">
                            <div class="rounded-2xl bg-white/15 px-6 py-5 text-white shadow-lg ring-1 ring-white/40 backdrop-blur">
                                <p class="text-xs font-semibold uppercase tracking-wider text-white/70">Today's safe observing time</p>
                                <p class="mt-2 text-4xl font-bold sm:text-5xl">
                                    <?= htmlspecialchars($todaySafeHoursDisplay, ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ($todaySafeHours !== null): ?>
                                        <span class="ml-1 text-lg font-semibold">hrs</span>
                                    <?php endif; ?>
                                </p>
                                <p class="mt-1 text-xs text-white/70">Automatically updated from observatory sensors.</p>
                            </div>
                            <nav aria-label="Quick links" class="flex flex-col gap-3 sm:items-end">
                                <a href="clear.php" class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/25 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">Clear by Month</a>
                                <a href="#safeChart" class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">Safe Hours Chart</a>
                                <a href="#envChart" class="inline-flex items-center gap-2 rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70">Environment Trends</a>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <div id="cards" class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 auto-rows-fr"></div>
        <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-6">
            <div id="skyImageContainer" class="bg-white/70 dark:bg-gray-800/70 p-4 rounded-xl shadow flex flex-col">
                <img id="skyImage" alt="Sky image" class="flex-1 object-contain min-h-[16rem] w-[120%]" />
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
let skyImageUrl = null;

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

    const gradientPalettes = [
        'from-indigo-500/20 via-white/80 to-white/50 dark:from-indigo-500/20 dark:via-slate-900/70 dark:to-slate-900/40',
        'from-sky-500/20 via-white/80 to-white/50 dark:from-sky-500/20 dark:via-slate-900/70 dark:to-slate-900/40',
        'from-purple-500/20 via-white/80 to-white/50 dark:from-purple-500/20 dark:via-slate-900/70 dark:to-slate-900/40',
        'from-emerald-500/20 via-white/80 to-white/50 dark:from-emerald-500/20 dark:via-slate-900/70 dark:to-slate-900/40'
    ];
    const statusBaseClasses = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide shadow-sm ring-1 ring-inset transition-colors backdrop-blur-sm';

    topicEntries.forEach(([name, cfg], idx) => {
        const id = 'value-' + sanitize(name);
        const card = document.createElement('div');
        const gradient = gradientPalettes[idx % gradientPalettes.length];

        card.id = 'card-' + sanitize(name);
        card.className = `relative flex h-full flex-col overflow-hidden rounded-3xl bg-gradient-to-br ${gradient} p-6 shadow-xl shadow-indigo-200/60 dark:shadow-black/40 ring-1 ring-white/40 dark:ring-white/10 transition`; 
        const icon = icons[name] || '📟';
        const label = name.replace(/[_-]/g, ' ');
        const unitMarkup = cfg.unit ? `<span class="ml-1 text-lg font-medium text-slate-500 dark:text-slate-300">${cfg.unit}</span>` : '';
        card.innerHTML = `
            <div class="pointer-events-none absolute -top-20 -right-10 h-48 w-48 rounded-full bg-white/40 dark:bg-white/10 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-16 h-56 w-56 rounded-full bg-indigo-200/30 dark:bg-indigo-500/10 blur-3xl"></div>
            <div class="relative flex h-full flex-col justify-between gap-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <span class="relative z-10 text-3xl sm:text-4xl">${icon}</span>
                            <span class="pointer-events-none absolute -inset-2 rounded-full bg-white/60 dark:bg-white/10 blur-lg"></span>
                        </div>
                        <div class="flex flex-col">
                            <h2 class="text-lg font-semibold capitalize text-slate-900 dark:text-slate-100">${label}</h2>
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Live sensor</p>
                        </div>
                    </div>
                    <span id="status-${sanitize(name)}" class="${statusBaseClasses} bg-slate-100/80 text-slate-600 ring-slate-200/70">Monitoring</span>
                </div>
                <div class="flex flex-col gap-4">
                    <p class="text-4xl font-semibold leading-tight text-slate-900 dark:text-white sm:text-5xl">
                        <span id="${id}">--</span>${unitMarkup}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="historical.php?topic=${encodeURIComponent(name)}" class="inline-flex items-center gap-2 rounded-full bg-indigo-500/90 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-indigo-600/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-200 dark:bg-indigo-500/80 dark:hover:bg-indigo-400/90" aria-label="View History">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M6 15l4-4 3 3 7-7" />
                            </svg>
                            View History
                        </a>
                    </div>
                </div>
            </div>
        `;
        cardsContainer.appendChild(card);
    });



    const statusEl = document.getElementById('mqttStatus');
    let client;
    let connectAttempts = 0;

    function updateStatus(text, cls) {
        statusEl.textContent = text;
        statusEl.className = 'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold shadow-sm ring-1 ring-white/50 backdrop-blur ' + cls;
    }

    function scheduleReconnect() {
        const delay = Math.min(1000 * Math.pow(2, connectAttempts), 30000);
        updateStatus('Reconnecting...', 'bg-amber-100/90 text-amber-800');
        setTimeout(() => {
            connectAttempts++;
            connectClient();
        }, delay);
    }

    function connectClient() {
        if (!window.mqtt) {
            console.warn('MQTT.js library is not loaded');
            updateStatus('MQTT unavailable', 'bg-red-100 text-red-700');
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
        updateStatus('Disconnected', 'bg-red-100 text-red-700');
        scheduleReconnect();
    }
    function onMessageArrived(topic, message) {
        if (topic === 'Observatory/skyimage') {
            const img = document.getElementById('skyImage');
            if (skyImageUrl) URL.revokeObjectURL(skyImageUrl);
            const blob = new Blob([message], { type: 'image/jpeg' });
            skyImageUrl = URL.createObjectURL(blob);
            img.src = skyImageUrl;
            return;
        }
        const value = parseFloat(message.toString());
        const entry = topicEntries.find(([, cfg]) => cfg.topic === topic);
        if (entry) {
            const [name, cfg] = entry;
            const id = 'value-' + sanitize(name);
            const el = document.getElementById(id);
            if (el) { el.textContent = value; }
            const statusEl = document.getElementById('status-' + sanitize(name));
            if (statusEl) {
                if (cfg.green !== undefined && cfg.condition) {
                    let match = false;
                    if (cfg.condition === 'above') match = value > cfg.green;
                    else if (cfg.condition === 'below') match = value < cfg.green;
                    if (match) {
                        statusEl.textContent = 'Favorable';
                        statusEl.className = `${statusBaseClasses} bg-emerald-100/90 text-emerald-700 ring-emerald-300/60`;
                    } else {
                        statusEl.textContent = 'Warning';
                        statusEl.className = `${statusBaseClasses} bg-rose-100/90 text-rose-700 ring-rose-300/60`;
                    }
                } else {
                    statusEl.textContent = 'Monitoring';
                    statusEl.className = `${statusBaseClasses} bg-slate-100/80 text-slate-600 ring-slate-200/70`;
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
        updateStatus('Connected', 'bg-emerald-100 text-emerald-700');
        connectAttempts = 0;
        Object.values(topics).forEach(cfg => client.subscribe(cfg.topic));
        client.subscribe('Observatory/skyimage');
    }

    function loadMQTT(urls, idx = 0) {
        if (idx >= urls.length) {
            console.warn('MQTT.js library failed to load');
            updateStatus('MQTT unavailable', 'bg-red-100 text-red-700');
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
