<?php
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$host = $config['host'] ?? 'localhost';
$topics = $config['topics'] ?? [];

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

$safeData = [];
$last7SafeHours = null;
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

    $sevenDayWindowStart = new DateTime('today -6 days');
    $sevenDayTotal = 0.0;
    for ($i = 0; $i < 7; $i++) {
        $d = clone $sevenDayWindowStart;
        $d->modify("+{$i} day");
        $key = $d->format('Y-m-d');
        if (isset($dayMap[$key])) {
            $sevenDayTotal += $dayMap[$key];
        }
    }
    $last7SafeHours = round($sevenDayTotal, 2);

    foreach ($dayMap as $day => $hours) {
        $rounded = round($hours, 2);
        $safeData[] = ['day' => $day, 'hours' => $rounded];
    }
} catch (Exception $e) {
    $safeData = [];
    $last7SafeHours = null;
}
$last7SafeHoursDisplay = $last7SafeHours !== null ? number_format($last7SafeHours, 2) : '--';
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
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-[#0b1120] dark:text-slate-100 font-sans">
    <div class="max-w-7xl mx-auto px-5 py-6 sm:px-8 sm:py-8">
        <header id="heroCard" class="mb-8 overflow-hidden border border-slate-800 bg-slate-950 text-white shadow-sm dark:border-slate-700">
            <div class="border-b border-white/10 px-6 py-4 sm:px-8">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <a href="index.php" class="flex items-center gap-3 font-semibold tracking-tight text-white">
                        <img src="logo.svg" alt="Observatory telescope logo" class="h-9 w-9" />
                        <span class="text-sm sm:text-base">Wheathampstead AstroPhotography Conditions</span>
                    </a>
                    <div class="flex items-center gap-3">
                        <span id="mqttStatus" class="inline-flex items-center gap-2 rounded-md border border-amber-300/30 bg-amber-50/10 px-3 py-1.5 text-xs font-semibold text-amber-100">Connecting...</span>
                        <button id="modeToggle" class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-white/15 text-slate-200 transition hover:bg-white/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300" aria-label="Switch to Dark Mode">
                            <span aria-hidden="true">◐</span><span class="sr-only">Toggle colour mode</span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="grid gap-8 px-6 py-9 sm:px-8 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.22em] text-sky-300">Live observatory dashboard</p>
                    <h1 class="max-w-3xl text-3xl font-semibold tracking-tight sm:text-5xl">Know when the sky is ready.</h1>
                    <p class="mt-4 max-w-2xl text-base leading-7 text-slate-300">A calm, focused view of current conditions, recent observations, and the roof camera — built for planning your next session.</p>
                </div>
                <div class="min-w-[15rem] border-l border-white/15 pl-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Safe observing · 7 days</p>
                    <p class="mt-2 text-4xl font-semibold tracking-tight">
                        <?= htmlspecialchars($last7SafeHoursDisplay, ENT_QUOTES, 'UTF-8'); ?><?php if ($last7SafeHours !== null): ?><span class="ml-1 text-base font-medium text-slate-400">hours</span><?php endif; ?>
                    </p>
                    <a href="clear.php" class="mt-4 inline-flex text-sm font-semibold text-sky-300 transition hover:text-sky-200">Review monthly conditions <span class="ml-2" aria-hidden="true">→</span></a>
                </div>
            </div>
        </header>
        <section class="mb-4 flex flex-wrap items-end justify-between gap-3">
            <div><p class="text-xs font-semibold uppercase tracking-[0.18em] text-sky-700 dark:text-sky-300">Current conditions</p><h2 class="mt-1 text-xl font-semibold tracking-tight">Live instrument readings</h2></div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Values update as messages arrive.</p>
        </section>
        <div id="cards" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 auto-rows-fr"></div>
        <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1.2fr] lg:items-stretch">
            <section id="skyImageContainer" class="relative flex flex-col overflow-hidden border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">Live Sky Camera</h2>
                    <button type="button" data-target="skyImageContainer" class="fullscreen-toggle inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800" aria-label="Toggle full screen for sky image">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" />
                        </svg>
                    </button>
                </div>
                <div class="mt-4 flex flex-1 items-center justify-center overflow-hidden rounded-lg bg-slate-950 p-2 shadow-inner dark:bg-slate-900 min-h-[18rem]">
                    <img id="skyImage" alt="Sky image" class="max-h-full w-full object-contain" />
                </div>
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">Updated continuously from the observatory roof camera.</p>
            </section>
            <section id="chartHub" class="relative flex flex-col overflow-hidden border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div role="tablist" aria-label="Chart selection" class="inline-flex rounded-md border border-slate-200 bg-slate-100 p-1 text-sm font-semibold text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300">
                        <button type="button" data-chart-tab="safe" class="chart-tab rounded-full px-4 py-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400" role="tab" aria-selected="true">Safe Hours</button>
                        <button type="button" data-chart-tab="realtime" class="chart-tab rounded-full px-4 py-2 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-200 dark:focus-visible:ring-indigo-400" role="tab" aria-selected="false">Realtime Trends</button>
                    </div>
                    <button type="button" data-target="chartHub" class="fullscreen-toggle inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-700 shadow-sm transition hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800" aria-label="Toggle full screen for charts">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" />
                        </svg>
                    </button>
                </div>
                <div id="chartDisplay" class="relative mt-4 flex-1 overflow-hidden rounded-lg bg-slate-50 p-2 dark:bg-slate-900/60 min-h-[18rem]">
                    <div id="safeChart" class="absolute inset-0"></div>
                    <div id="envChart" class="absolute inset-0 hidden"></div>
                </div>
                <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                    Compare long-term safe observing hours with live sensor readings using the tabs above.
                </p>
            </section>
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
const envSeriesLabels = envTopicNames.map(name => {
    const cfg = topics[name] || {};
    const unit = cfg.unit ? ` (${cfg.unit})` : '';
    return name.charAt(0).toUpperCase() + name.slice(1) + unit;
});
const envSeriesData = envTopicNames.map(() => []);
let envChart = null;

    const heroCard = document.getElementById('heroCard');
    const heroDefaultGradientClasses = ['bg-slate-950', 'dark:bg-slate-950'];
    const heroSafeGradientClasses = ['bg-emerald-950', 'dark:bg-emerald-950'];
    let heroState = 'default';

    function setHeroGradient(state) {
        if (!heroCard || state === heroState) return;
        if (state === 'safe') {
            heroCard.classList.remove(...heroDefaultGradientClasses);
            heroCard.classList.add(...heroSafeGradientClasses);
        } else {
            heroCard.classList.remove(...heroSafeGradientClasses);
            heroCard.classList.add(...heroDefaultGradientClasses);
        }
        heroState = state;
    }

    const thresholdedSensors = topicEntries.filter(([, cfg]) => {
        const threshold = parseFloat(cfg.green);
        const condition = typeof cfg.condition === 'string' ? cfg.condition.toLowerCase() : null;
        const hasThreshold = Number.isFinite(threshold);
        return hasThreshold && (condition === 'above' || condition === 'below');
    });
    const sensorStatus = new Map(thresholdedSensors.map(([name]) => [name, 'unknown']));

    function updateHeroState() {
        if (!heroCard || thresholdedSensors.length === 0) return;
        const allFavorable = thresholdedSensors.every(([name]) => sensorStatus.get(name) === 'favorable');
        setHeroGradient(allFavorable ? 'safe' : 'default');
    }

    updateHeroState();

    const cardsContainer = document.getElementById('cards');
    cardsContainer.innerHTML = '';
    const sanitize = name => name.replace(/[^a-zA-Z0-9_-]/g, '_');

    const icons = {
        temperature: 'TMP',
        rain: 'RAIN',
        light: 'LUX',
        clouds: 'CLD',
        safe: 'SAFE',
        sqm: 'SQM',
        humidity: 'RH',
        dewpoint: 'DPT'
    };

    const statusBaseClasses = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide shadow-sm ring-1 ring-inset transition-colors backdrop-blur-sm';

    const miniChartData = {};
    const miniCharts = {};
    const THREE_HOURS_MS = 3 * 60 * 60 * 1000;

    function formatDateTimeForQuery(date) {
        return date.toISOString().slice(0, 19).replace('T', ' ');
    }

    function parseTimestamp(value) {
        if (!value) return null;
        const normalized = value.replace(' ', 'T');
        const time = Date.parse(normalized);
        return Number.isNaN(time) ? null : time;
    }

    function getMiniChartPalette() {
        const isDark = document.documentElement.classList.contains('dark');
        if (isDark) {
            return {
                line: 'rgba(165, 180, 252, 0.9)',
                fillTop: 'rgba(129, 140, 248, 0.35)',
                fillBottom: 'rgba(129, 140, 248, 0.05)'
            };
        }
        return {
            line: 'rgba(79, 70, 229, 0.9)',
            fillTop: 'rgba(129, 140, 248, 0.25)',
            fillBottom: 'rgba(99, 102, 241, 0.04)'
        };
    }

    function applyMiniChartTheme(chart) {
        if (!chart) return;
        const palette = getMiniChartPalette();
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const tooltipBg = isDark ? '#111827' : '#EEF2FF';
        chart.update({

            chart: { backgroundColor: 'transparent', plotBackgroundColor: 'transparent' },

            tooltip: {
                backgroundColor: tooltipBg,
                style: { color: textColor },
                borderColor: 'transparent'
            }
        }, false);
        if (chart.series[0]) {
            chart.series[0].update({
                color: palette.line,
                fillColor: {
                    linearGradient: { x1: 0, y1: 0, x2: 0, y2: 1 },
                    stops: [
                        [0, palette.fillTop],
                        [1, palette.fillBottom]
                    ]
                }
            }, false);
        }
        chart.redraw();
    }

    function renderMiniChart(name, cfg) {
        const sanitized = sanitize(name);
        const container = document.getElementById('chart-' + sanitized);
        if (!container) return;
        const data = (miniChartData[name] || []).slice();
        miniCharts[name] = Highcharts.chart(container, {
            chart: {
                type: 'areaspline',
                backgroundColor: 'transparent',

                plotBackgroundColor: 'transparent',

                animation: false,
                spacing: [6, 6, 6, 6]
            },
            title: { text: null },
            credits: { enabled: false },
            legend: { enabled: false },
            xAxis: {
                type: 'datetime',
                labels: { enabled: false },
                tickLength: 0,
                lineWidth: 0
            },
            yAxis: {
                title: { text: null },
                labels: { enabled: false },
                gridLineWidth: 0
            },
            tooltip: {
                valueSuffix: cfg.unit ? ` ${cfg.unit}` : '',
                xDateFormat: '%H:%M'
            },
            plotOptions: {
                areaspline: {
                    lineWidth: 1.5,
                    marker: { enabled: false },
                    fillOpacity: 0.5
                }
            },
            series: [{ data }]
        });
        applyMiniChartTheme(miniCharts[name]);
    }

    function refreshMiniChart(name) {
        const chart = miniCharts[name];
        if (!chart || !chart.series[0]) return;
        chart.series[0].setData((miniChartData[name] || []).slice(), true, false, false);
    }

    function recordMiniChartPoint(name, numericValue) {
        if (!Number.isFinite(numericValue)) return;
        if (!miniChartData[name]) return;
        const now = Date.now();
        const points = miniChartData[name];
        points.push([now, numericValue]);
        const cutoff = now - THREE_HOURS_MS;
        while (points.length && points[0][0] < cutoff) {
            points.shift();
        }
        refreshMiniChart(name);
    }

    function initializeMiniChart(name, cfg) {
        miniChartData[name] = [];
        const now = new Date();
        const start = new Date(now.getTime() - THREE_HOURS_MS);
        const params = new URLSearchParams({
            topic: name,
            format: 'json',
            start: formatDateTimeForQuery(start),
            end: formatDateTimeForQuery(now)
        });
        fetch(`historical.php?${params.toString()}`)
            .then(response => response.ok ? response.json() : [])
            .then(rows => {
                if (!Array.isArray(rows)) return rows;
                const parsed = rows.map(row => {
                    const ts = parseTimestamp(row.timestamp);
                    const val = parseFloat(row.value);
                    if (ts === null || Number.isNaN(val)) return null;
                    return [ts, val];
                }).filter(Boolean);
                if (parsed.length) {
                    miniChartData[name] = parsed;
                }
            })
            .catch(() => {})
            .finally(() => {
                renderMiniChart(name, cfg);
            });
    }

    topicEntries.forEach(([name, cfg]) => {
        const id = 'value-' + sanitize(name);
        const card = document.createElement('div');

        card.id = 'card-' + sanitize(name);
        card.className = 'relative flex h-full flex-col overflow-hidden border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800 dark:bg-slate-950';
        const icon = icons[name] || 'SEN';
        const label = name.replace(/[_-]/g, ' ');
        const unitMarkup = cfg.unit ? `<span class="ml-1 text-lg font-medium text-slate-500 dark:text-slate-300">${cfg.unit}</span>` : '';
        card.innerHTML = `
            <div class="relative flex h-full flex-col justify-between gap-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-sky-50 text-[10px] font-bold tracking-wide text-sky-700 dark:bg-sky-950 dark:text-sky-300"><span>${icon}</span></div>
                        <div class="flex flex-col">
                            <h2 class="text-base font-semibold capitalize text-slate-900 dark:text-slate-100">${label}</h2>
                            <p class="text-[11px] font-medium uppercase tracking-[0.14em] text-slate-500 dark:text-slate-400">Live sensor</p>
                        </div>
                    </div>
                    <span id="status-${sanitize(name)}" class="${statusBaseClasses} bg-slate-100/80 text-slate-600 ring-slate-200/70">Monitoring</span>
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                        <p class="text-4xl font-semibold tracking-tight text-slate-900 dark:text-white">
                            <span id="${id}">--</span>${unitMarkup}
                        </p>
                        <div class="relative h-24 w-full overflow-hidden rounded-md bg-slate-50 ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800 sm:h-28 sm:w-auto sm:min-w-[10rem]">
                            <div id="chart-${sanitize(name)}" class="absolute inset-0"></div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="historical.php?topic=${encodeURIComponent(name)}" class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-300 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800" aria-label="View History">
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
        initializeMiniChart(name, cfg);
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
        const rawValue = message.toString();
        const numericValue = parseFloat(rawValue);
        const hasNumericValue = Number.isFinite(numericValue);
        const displayValue = hasNumericValue
            ? numericValue.toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
                useGrouping: false
            })
            : rawValue;
        const entry = topicEntries.find(([, cfg]) => cfg.topic === topic);
        if (entry) {
            const [name, cfg] = entry;
            const id = 'value-' + sanitize(name);
            const el = document.getElementById(id);
            if (el) { el.textContent = displayValue; }
            const statusEl = document.getElementById('status-' + sanitize(name));
            const condition = typeof cfg.condition === 'string' ? cfg.condition.toLowerCase() : null;
            const threshold = parseFloat(cfg.green);
            const hasThreshold = Number.isFinite(threshold) && (condition === 'above' || condition === 'below');
            const isTrackedSensor = sensorStatus.has(name);

            if (hasNumericValue && hasThreshold) {
                let match = false;
                if (condition === 'above') match = numericValue > threshold;
                else if (condition === 'below') match = numericValue < threshold;

                if (statusEl) {
                    if (match) {
                        statusEl.textContent = 'Favorable';
                        statusEl.className = `${statusBaseClasses} bg-emerald-100/90 text-emerald-700 ring-emerald-300/60`;
                    } else {
                        statusEl.textContent = 'Warning';
                        statusEl.className = `${statusBaseClasses} bg-rose-100/90 text-rose-700 ring-rose-300/60`;
                    }
                }

                if (isTrackedSensor) {
                    sensorStatus.set(name, match ? 'favorable' : 'warning');
                }
            } else {
                if (statusEl) {
                    statusEl.textContent = 'Monitoring';
                    statusEl.className = `${statusBaseClasses} bg-slate-100/80 text-slate-600 ring-slate-200/70`;
                }
                if (isTrackedSensor) {
                    sensorStatus.set(name, 'unknown');
                }
            }

            if (isTrackedSensor) {
                updateHeroState();
            }
            if (hasNumericValue) {
                recordMiniChartPoint(name, numericValue);
            }
        }
        const envIndex = envSeriesMap[topic];
        if (envIndex !== undefined && hasNumericValue) {
            const x = Date.now();
            const points = envSeriesData[envIndex];
            points.push([x, numericValue]);
            if (points.length > 40) points.shift();
            if (envChart) {
                const series = envChart.series[envIndex];
                if (series) {
                    const shouldShift = series.data.length >= 40;
                    series.addPoint([x, numericValue], true, shouldShift);
                }
            }
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
        chart: {
            type: 'column',
            backgroundColor: 'transparent',
            plotBackgroundColor: 'transparent',
            zooming: {
                type: 'x',
                mouseWheel: true
            },
            zoomType: 'x'
        },
        title: { text: 'Observable Hours (Last 30 Days)' },
        xAxis: { categories: safeCategories },
        yAxis: { title: { text: 'Hours' } },
        series: [{ name: 'Hours', data: safeHours }]
    });

    function ensureEnvChart() {
        if (envChart) return envChart;
        envChart = Highcharts.chart('envChart', {
            chart: {
                type: 'spline',
                backgroundColor: 'transparent',
                plotBackgroundColor: 'transparent',
                zooming: {
                    type: 'x',
                    mouseWheel: true
                },
                zoomType: 'x'
            },
            title: { text: 'Realtime Clouds, Light, SQM' },
            xAxis: { type: 'datetime' },
            series: envSeriesLabels.map((name, idx) => ({ name, data: envSeriesData[idx].slice() }))
        });
        updateChartsTheme();
        return envChart;
    }

    const chartTabs = document.querySelectorAll('.chart-tab');
    const safeChartContainer = document.getElementById('safeChart');
    const envChartContainer = document.getElementById('envChart');
    let activeChartTab = null;
    const activeTabClasses = ['bg-white', 'text-indigo-700', 'shadow', 'dark:bg-gray-800', 'dark:text-indigo-100'];
    const inactiveTabClasses = ['text-indigo-500', 'hover:text-indigo-700', 'dark:text-indigo-200', 'dark:hover:text-indigo-100'];

    function applyTabState(tab) {
        chartTabs.forEach(btn => {
            const isActive = btn.dataset.chartTab === tab;
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
            activeTabClasses.forEach(cls => btn.classList.toggle(cls, isActive));
            inactiveTabClasses.forEach(cls => btn.classList.toggle(cls, !isActive));
        });
    }

    function showSafeChart() {
        envChartContainer.classList.add('hidden');
        safeChartContainer.classList.remove('hidden');
        requestAnimationFrame(() => safeChart.reflow());
    }

    function showEnvChart() {
        safeChartContainer.classList.add('hidden');
        envChartContainer.classList.remove('hidden');
        const chart = ensureEnvChart();
        requestAnimationFrame(() => chart && chart.reflow());
    }

    function setActiveTab(tab) {
        if (!tab) return;
        if (tab !== activeChartTab) {
            activeChartTab = tab;
            applyTabState(tab);
        }
        if (tab === 'safe') {
            showSafeChart();
        } else {
            showEnvChart();
        }
    }

    chartTabs.forEach(btn => {
        btn.addEventListener('click', () => setActiveTab(btn.dataset.chartTab));
    });

    document.querySelectorAll('[data-chart-link]').forEach(link => {
        link.addEventListener('click', () => setActiveTab(link.dataset.chartLink));
    });

    setActiveTab('safe');

    const fullscreenButtons = document.querySelectorAll('.fullscreen-toggle');

    fullscreenButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const target = document.getElementById(btn.dataset.target);
            if (!target) return;
            if (document.fullscreenElement === target) {
                if (document.exitFullscreen) document.exitFullscreen();
            } else if (target.requestFullscreen) {
                target.requestFullscreen();
            }
        });
    });

    function syncFullscreenButtons() {
        fullscreenButtons.forEach(btn => {
            const target = document.getElementById(btn.dataset.target);
            const isActive = target && document.fullscreenElement === target;
            btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            btn.classList.toggle('ring-4', isActive);
            btn.classList.toggle('ring-indigo-200', isActive);
            btn.classList.toggle('dark:ring-indigo-400', isActive);
            btn.classList.toggle('shadow-lg', isActive);
        });
        requestAnimationFrame(() => {
            safeChart.reflow();
            if (envChart) envChart.reflow();
        });
    }

    document.addEventListener('fullscreenchange', syncFullscreenButtons);
    syncFullscreenButtons();

    const modeToggle = document.getElementById('modeToggle');

    function updateChartsTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const gridColor = isDark ? '#374151' : '#e5e7eb';
        const charts = [safeChart];
        if (envChart) charts.push(envChart);
        charts.forEach(c => {
            const resetZoomTheme = {
                fill: isDark ? '#1F2937' : '#EEF2FF',
                stroke: 'transparent',
                style: {
                    color: textColor,
                    fontWeight: '600'
                }
            };
            c.update({
                chart: {
                    backgroundColor: 'transparent',
                    plotBackgroundColor: 'transparent',
                    resetZoomButton: { theme: resetZoomTheme }
                },
                title: { style: { color: textColor } },
                xAxis: { labels: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
                yAxis: { labels: { style: { color: textColor } }, title: { style: { color: textColor } }, gridLineColor: gridColor, lineColor: textColor },
                legend: { itemStyle: { color: textColor } }
            }, false);
            c.redraw();
        });
        Object.values(miniCharts).forEach(applyMiniChartTheme);
    }

    function updateModeIcon() {
        const isDark = document.documentElement.classList.contains('dark');
        modeToggle.innerHTML = '<span aria-hidden="true">' + (isDark ? '◑' : '◐') + '</span><span class="sr-only">Toggle colour mode</span>';
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
