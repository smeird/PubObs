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
    <link rel="stylesheet" href="observatory.css">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        }
        try {
            const storedTheme = localStorage.getItem('color-theme');
            if (storedTheme === 'dark' || (!storedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (error) {}
    </script>
    <!-- Highcharts -->
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <script src="https://code.highcharts.com/modules/accessibility.js"></script>
</head>
<body class="observatory-shell antialiased">
    <a href="#main-content" class="obs-skip-link">Skip to live observatory data</a>
    <div class="obs-frame">
        <header class="mb-8 space-y-4">
            <div class="obs-topbar">
                    <a href="index.php" class="obs-brand">
                        <img src="logo.svg" alt="Observatory telescope logo" class="obs-brand-mark" />
                        <span class="obs-brand-copy">
                            <span class="obs-brand-kicker">WAC · Telemetry node</span>
                            <span class="obs-brand-title">Wheathampstead Observatory</span>
                        </span>
                    </a>
                    <div class="obs-topbar-actions">
                        <a href="clear.php" class="obs-nav-link"><span class="obs-nav-label">Sky archive</span><span aria-hidden="true">↗</span></a>
                        <a href="HA.php" class="obs-nav-link"><span class="obs-nav-label">Wall display</span><span aria-hidden="true">▣</span></a>
                        <span id="mqttStatus" class="obs-status-pill">Connecting</span>
                        <button id="modeToggle" class="obs-icon-button" aria-label="Switch to Dark Mode">
                            <span aria-hidden="true">◐</span><span class="sr-only">Toggle colour mode</span>
                        </button>
                    </div>
            </div>
            <section id="heroCard" class="obs-hero">
            <div class="obs-hero-grid">
                <div>
                    <p class="obs-kicker mb-5 text-cyan-300">Live observatory intelligence · 51.81° N</p>
                    <h1 class="obs-hero-title">Read the atmosphere.<br>Catch the clear window.</h1>
                    <p class="obs-hero-copy">A live view of the sky above Wheathampstead—combining local weather instruments, roof-camera imagery, and historical observing conditions in one precise workspace.</p>
                </div>
                <div class="obs-hero-metric">
                    <p class="obs-data-label text-cyan-300">Clear-sky yield · Rolling 7d</p>
                    <p class="obs-metric-value">
                        <?= htmlspecialchars($last7SafeHoursDisplay, ENT_QUOTES, 'UTF-8'); ?><?php if ($last7SafeHours !== null): ?><span class="obs-metric-unit">hours</span><?php endif; ?>
                    </p>
                    <p class="mt-3 text-sm leading-6 text-slate-400">Accumulated time when local instruments reported safe observing conditions.</p>
                    <a href="clear.php" class="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-cyan-300 transition hover:text-cyan-100">Open sky archive <span aria-hidden="true">→</span></a>
                </div>
            </div>
            <div class="obs-hero-footer" aria-label="Observatory system overview">
                <div class="obs-hero-footer-item"><span class="obs-signal-dot obs-signal-dot--live"></span><span>Telemetry · MQTT stream</span></div>
                <div class="obs-hero-footer-item"><span class="font-mono text-cyan-300">CAM-01</span><span>Roof all-sky camera</span></div>
                <div class="obs-hero-footer-item"><span class="font-mono text-violet-300">UTC</span><span id="utcClock">Synchronising clock</span></div>
            </div>
            </section>
        </header>
        <main id="main-content">
        <section class="obs-section-head">
            <div><p class="obs-kicker">Atmospheric array · Live</p><h2 class="obs-section-title">Current instrument readings</h2></div>
            <p class="obs-section-note"><span class="font-mono"><?= count($topics); ?> channels</span> · Values update as packets arrive</p>
        </section>
        <div id="cards" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 auto-rows-fr"></div>
        <div class="mt-8 grid grid-cols-1 gap-4 lg:grid-cols-[1.08fr_1fr] lg:items-stretch">
            <section id="skyImageContainer" class="obs-panel relative flex flex-col">
                <div class="flex items-center justify-between gap-3">
                    <div><p class="obs-data-label">CAM-01 · Roof array</p><h2 class="mt-1 text-lg font-semibold">All-sky camera</h2></div>
                    <button type="button" data-target="skyImageContainer" class="fullscreen-toggle obs-icon-button" aria-label="Toggle full screen for sky image">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" />
                        </svg>
                    </button>
                </div>
                <div class="obs-camera-well mt-4 flex flex-1 items-center justify-center p-2">
                    <div id="skyImagePlaceholder" class="obs-camera-placeholder" aria-live="polite">
                        <span class="obs-reticle" aria-hidden="true"></span>
                        <span class="obs-data-label text-slate-500">Awaiting next camera frame</span>
                    </div>
                    <img id="skyImage" alt="Latest image from the observatory roof camera" class="relative z-10 max-h-full w-full object-contain" />
                </div>
                <div class="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500 dark:text-slate-400"><span>Updated continuously via MQTT</span><span class="font-mono uppercase tracking-wider">JPEG · LIVE</span></div>
            </section>
            <section id="chartHub" class="obs-panel relative flex flex-col">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="obs-data-label">ANL-02 · Signal analysis</p>
                        <div role="tablist" aria-label="Chart selection" class="obs-segmented mt-2">
                        <button type="button" data-chart-tab="safe" class="chart-tab obs-tab" role="tab" aria-selected="true">Clear-sky history</button>
                        <button type="button" data-chart-tab="realtime" class="chart-tab obs-tab" role="tab" aria-selected="false">Live environment</button>
                        </div>
                    </div>
                    <button type="button" data-target="chartHub" class="fullscreen-toggle obs-icon-button" aria-label="Toggle full screen for charts">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" />
                        </svg>
                    </button>
                </div>
                <div id="chartDisplay" class="obs-chart-well relative mt-4 flex-1 p-2 min-h-[22rem]">
                    <div id="safeChart" class="absolute inset-0"></div>
                    <div id="envChart" class="absolute inset-0 hidden"></div>
                </div>
                <p class="mt-4 text-xs leading-5 text-slate-500 dark:text-slate-400">Thirty-day observing conditions and live environmental signals share a common analysis surface.</p>
            </section>
        </div>
        </main>
        <footer class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-slate-400/20 py-6 text-xs text-slate-500 dark:text-slate-500">
            <span class="font-mono uppercase tracking-[0.15em]">WAC · Wheathampstead, UK</span>
            <span>Local instruments · Local sky intelligence</span>
        </footer>
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
    let heroState = 'default';

    function setHeroGradient(state) {
        if (!heroCard || state === heroState) return;
        heroCard.classList.toggle('obs-hero--safe', state === 'safe');
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
    const escapeHtml = value => String(value).replace(/[&<>'"]/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[character]);

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

    const statusBaseClasses = 'inline-flex items-center rounded-full border px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-[0.12em] transition-colors';

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
                line: 'rgba(103, 232, 249, 0.95)',
                fillTop: 'rgba(34, 211, 238, 0.28)',
                fillBottom: 'rgba(34, 211, 238, 0.02)'
            };
        }
        return {
            line: 'rgba(8, 145, 178, 0.95)',
            fillTop: 'rgba(6, 182, 212, 0.2)',
            fillBottom: 'rgba(6, 182, 212, 0.02)'
        };
    }

    function applyMiniChartTheme(chart) {
        if (!chart) return;
        const palette = getMiniChartPalette();
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#F9FAFB' : '#1F2937';
        const tooltipBg = isDark ? '#0B1324' : '#FFFFFF';
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
        card.className = 'obs-readout-card flex h-full flex-col';
        const icon = icons[name] || 'SEN';
        const label = escapeHtml(name.replace(/[_-]/g, ' '));
        const unitMarkup = cfg.unit ? `<span class="obs-readout-unit">${escapeHtml(cfg.unit)}</span>` : '';
        card.innerHTML = `
            <div class="relative flex h-full flex-col justify-between gap-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div class="obs-instrument-mark"><span class="obs-instrument-code">${icon}</span></div>
                        <div class="flex flex-col">
                            <h3 class="text-base font-semibold capitalize text-slate-900 dark:text-slate-100">${label}</h3>
                            <p class="obs-data-label mt-1">Instrument channel</p>
                        </div>
                    </div>
                    <span id="status-${sanitize(name)}" class="${statusBaseClasses} border-slate-300/60 bg-slate-500/5 text-slate-500 dark:border-slate-700 dark:text-slate-400">Awaiting</span>
                </div>
                <div class="flex flex-col gap-4">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                        <p class="obs-readout-value text-slate-900 dark:text-white">
                            <span id="${id}">--</span>${unitMarkup}
                        </p>
                        <div class="obs-chart-well relative h-24 w-full sm:h-28 sm:w-auto sm:min-w-[10rem]">
                            <div id="chart-${sanitize(name)}" class="absolute inset-0"></div>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="historical.php?topic=${encodeURIComponent(name)}" class="obs-button" aria-label="View ${label} history">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M6 15l4-4 3 3 7-7" />
                            </svg>
                            Open history
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

    function updateStatus(text, state = 'warn') {
        statusEl.textContent = text;
        statusEl.className = `obs-status-pill obs-status-pill--${state}`;
    }

    function scheduleReconnect() {
        const delay = Math.min(1000 * Math.pow(2, connectAttempts), 30000);
        updateStatus('MQTT · Reconnecting', 'warn');
        setTimeout(() => {
            connectAttempts++;
            connectClient();
        }, delay);
    }

    function connectClient() {
        if (!window.mqtt) {
            console.warn('MQTT.js library is not loaded');
            updateStatus('MQTT · Unavailable', 'bad');
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
        updateStatus('MQTT · Disconnected', 'bad');
        scheduleReconnect();
    }
    function onMessageArrived(topic, message) {
        if (topic === 'Observatory/skyimage') {
            const img = document.getElementById('skyImage');
            const placeholder = document.getElementById('skyImagePlaceholder');
            if (skyImageUrl) URL.revokeObjectURL(skyImageUrl);
            const blob = new Blob([message], { type: 'image/jpeg' });
            skyImageUrl = URL.createObjectURL(blob);
            img.onload = () => { if (placeholder) placeholder.hidden = true; };
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
                        statusEl.className = `${statusBaseClasses} border-emerald-400/30 bg-emerald-400/10 text-emerald-600 dark:text-emerald-300`;
                    } else {
                        statusEl.textContent = 'Warning';
                        statusEl.className = `${statusBaseClasses} border-rose-400/30 bg-rose-400/10 text-rose-600 dark:text-rose-300`;
                    }
                }

                if (isTrackedSensor) {
                    sensorStatus.set(name, match ? 'favorable' : 'warning');
                }
            } else {
                if (statusEl) {
                    statusEl.textContent = 'Live';
                    statusEl.className = `${statusBaseClasses} border-cyan-400/25 bg-cyan-400/5 text-cyan-700 dark:text-cyan-300`;
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
        updateStatus('MQTT · Connected', 'ok');
        connectAttempts = 0;
        Object.values(topics).forEach(cfg => client.subscribe(cfg.topic));
        client.subscribe('Observatory/skyimage');
    }

    function loadMQTT(urls, idx = 0) {
        if (idx >= urls.length) {
            console.warn('MQTT.js library failed to load');
            updateStatus('MQTT · Unavailable', 'bad');
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
            spacing: [22, 16, 12, 12],
            zooming: {
                type: 'x',
                mouseWheel: true
            },
            zoomType: 'x'
        },
        title: { text: 'Observable window · last 30 days', align: 'left' },
        subtitle: { text: 'Hours reported safe by the local sensor array', align: 'left' },
        credits: { enabled: false },
        legend: { enabled: false },
        xAxis: { categories: safeCategories },
        yAxis: { title: { text: 'Hours' } },
        plotOptions: {
            column: {
                borderWidth: 0,
                borderRadius: 3,
                color: '#0891b2'
            }
        },
        series: [{ name: 'Safe hours', data: safeHours }]
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
            title: { text: 'Live atmospheric signals', align: 'left' },
            subtitle: { text: 'Cloud temperature, ambient light and sky quality', align: 'left' },
            credits: { enabled: false },
            xAxis: { type: 'datetime' },
            colors: ['#22d3ee', '#a78bfa', '#34d399'],
            series: envSeriesLabels.map((name, idx) => ({ name, data: envSeriesData[idx].slice(), lineWidth: 2 }))
        });
        updateChartsTheme();
        return envChart;
    }

    const chartTabs = document.querySelectorAll('.chart-tab');
    const safeChartContainer = document.getElementById('safeChart');
    const envChartContainer = document.getElementById('envChart');
    let activeChartTab = null;

    function applyTabState(tab) {
        chartTabs.forEach(btn => {
            const isActive = btn.dataset.chartTab === tab;
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
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
            btn.classList.toggle('ring-cyan-200', isActive);
            btn.classList.toggle('dark:ring-cyan-400', isActive);
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
        const gridColor = isDark ? 'rgba(148, 163, 184, 0.14)' : 'rgba(15, 23, 42, 0.09)';
        const mutedColor = isDark ? '#91A2B8' : '#64748B';
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
                title: { style: { color: textColor, fontSize: '15px', fontWeight: '650' } },
                subtitle: { style: { color: mutedColor, fontSize: '11px' } },
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
        localStorage.setItem('color-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
        updateModeIcon();
        updateChartsTheme();
    });

    const utcClock = document.getElementById('utcClock');
    function updateUtcClock() {
        if (!utcClock) return;
        utcClock.textContent = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'UTC', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        }).format(new Date()) + ' · System clock';
    }
    updateUtcClock();
    window.setInterval(updateUtcClock, 1000);

    updateModeIcon();
    updateChartsTheme();
    </script>
</body>
</html>
