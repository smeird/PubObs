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
    <link rel="stylesheet" href="tailwind.generated.css">
    <link rel="stylesheet" href="observatory.css">
    <script>
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
<body class="observatory-shell dashboard-density antialiased">
    <a href="#main-content" class="obs-skip-link">Skip to live observatory data</a>
    <div class="obs-frame">
        <header class="obs-dashboard-header">
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
            <section id="heroCard" class="obs-hero obs-hero--compact">
                <div class="obs-compact-hero-copy">
                    <p class="obs-kicker text-cyan-300">Live site status · 51.81° N</p>
                    <h1>Observatory conditions</h1>
                    <p>Nine local instruments, camera imagery, and observing history in one operational view.</p>
                </div>
                <div class="obs-compact-system-grid" aria-label="Observatory system overview">
                    <div><span class="obs-data-label text-cyan-300">CAM-01</span><strong>Roof camera</strong></div>
                    <div><span class="obs-data-label text-violet-300">UTC</span><strong id="utcClock">Synchronising</strong></div>
                    <a href="clear.php" class="obs-compact-system-link" aria-label="Open clear-sky archive">
                        <span class="obs-data-label text-cyan-300">Clear sky · 7d</span>
                        <strong><?= htmlspecialchars($last7SafeHoursDisplay, ENT_QUOTES, 'UTF-8'); ?><?php if ($last7SafeHours !== null): ?> h<?php endif; ?></strong>
                    </a>
                </div>
                <div id="observingStatus" class="obs-compact-safety" data-state="assessing" role="status" aria-live="polite">
                    <span class="obs-data-label">Observing state</span>
                    <strong id="observingStatusLabel">Assessing</strong>
                    <span id="observingStatusDetail">Awaiting safety sensor</span>
                </div>
            </section>
        </header>
        <main id="main-content">
            <div class="obs-dashboard-grid">
                <section class="obs-telemetry-section" aria-labelledby="telemetryHeading">
                    <div class="obs-compact-section-head">
                        <div><p class="obs-kicker">Atmospheric array · Live</p><h2 id="telemetryHeading">Instrument readings</h2></div>
                        <p><span class="font-mono"><?= count($topics); ?> channels</span> · Three-hour trends</p>
                    </div>
                    <div id="cards" class="obs-sensor-matrix"></div>
                </section>
                <aside class="obs-visual-stack" aria-label="Camera and analysis">
                    <section id="skyImageContainer" class="obs-panel obs-panel--compact">
                        <div class="obs-compact-panel-head">
                            <div><p class="obs-data-label">CAM-01 · Roof array</p><h2>All-sky camera</h2></div>
                            <button type="button" data-target="skyImageContainer" class="fullscreen-toggle obs-icon-button" aria-label="Toggle full screen for sky image">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" /></svg>
                            </button>
                        </div>
                        <div class="obs-camera-well obs-camera-well--compact">
                            <div id="skyImagePlaceholder" class="obs-camera-placeholder" aria-live="polite">
                                <span class="obs-reticle" aria-hidden="true"></span>
                                <span class="obs-data-label text-slate-500">Awaiting camera frame</span>
                            </div>
                            <img id="skyImage" alt="Latest image from the observatory roof camera" class="relative z-10 max-h-full w-full object-contain" hidden />
                        </div>
                    </section>
                    <section id="chartHub" class="obs-panel obs-panel--compact">
                        <div class="obs-compact-panel-head obs-analysis-head">
                            <div><p class="obs-data-label">ANL-02 · Signal analysis</p><h2>Conditions history</h2></div>
                            <div class="flex items-center gap-2">
                                <div role="tablist" aria-label="Chart selection" class="obs-segmented obs-segmented--compact">
                                    <button type="button" data-chart-tab="safe" class="chart-tab obs-tab" role="tab" aria-selected="true">30d</button>
                                    <button type="button" data-chart-tab="realtime" class="chart-tab obs-tab" role="tab" aria-selected="false">Live</button>
                                </div>
                                <button type="button" data-target="chartHub" class="fullscreen-toggle obs-icon-button" aria-label="Toggle full screen for charts">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" /></svg>
                                </button>
                            </div>
                        </div>
                        <div id="chartDisplay" class="obs-chart-well obs-chart-display--compact">
                            <div id="safeChart" class="absolute inset-0"></div>
                            <div id="envChart" class="absolute inset-0 hidden"></div>
                        </div>
                    </section>
                </aside>
            </div>
        </main>
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
    const observingStatus = document.getElementById('observingStatus');
    const observingStatusLabel = document.getElementById('observingStatusLabel');
    const observingStatusDetail = document.getElementById('observingStatusDetail');
    let heroState = 'assessing';

    function setHeroGradient(state) {
        if (!heroCard || state === heroState) return;
        heroCard.classList.toggle('obs-hero--safe', state === 'safe');
        heroCard.classList.toggle('obs-hero--unsafe', state === 'unsafe');
        heroState = state;
    }

    const thresholdedSensors = topicEntries.filter(([, cfg]) => {
        const threshold = parseFloat(cfg.green);
        const condition = typeof cfg.condition === 'string' ? cfg.condition.toLowerCase() : null;
        const hasThreshold = Number.isFinite(threshold);
        return hasThreshold && (condition === 'above' || condition === 'below');
    });
    const sensorStatus = new Map(thresholdedSensors.map(([name]) => [name, 'unknown']));
    const safetySensorName = topicEntries.find(([name]) => name.toLowerCase() === 'safe')?.[0] || null;

    const sensorNames = {
        rain: 'Rain sensor',
        safe: 'Observing safety',
        sqm: 'Sky quality',
        clouds: 'Cloud cover',
        dewpoint: 'Dew point'
    };

    function sensorLabel(name) {
        const label = sensorNames[name.toLowerCase()] || name.replace(/[_-]/g, ' ');
        return label.replace(/\b\w/g, character => character.toUpperCase());
    }

    function sensorConditionLabel(name, favorable) {
        const normalized = name.toLowerCase();
        if (normalized === 'safe') return favorable ? 'Permitted' : 'Blocked';
        if (normalized === 'rain') return favorable ? 'Dry' : 'Rain detected';
        return favorable ? 'Favorable' : 'Attention';
    }

    function sensorTargetLabel(name, cfg) {
        const normalized = name.toLowerCase();
        const threshold = parseFloat(cfg.green);
        const condition = typeof cfg.condition === 'string' ? cfg.condition.toLowerCase() : '';
        if (!Number.isFinite(threshold) || (condition !== 'above' && condition !== 'below')) {
            return 'Three-hour trend';
        }
        const direction = condition === 'above' ? 'above' : 'below';
        const value = `${cfg.green}${cfg.unit ? ` ${cfg.unit}` : ''}`;
        if (normalized === 'safe') return `Permitted ${direction} ${value}`;
        if (normalized === 'rain') return `Dry ${direction} ${value}`;
        return `Target ${direction} ${value}`;
    }

    function updateHeroState() {
        if (!heroCard || !observingStatus || !observingStatusLabel || !observingStatusDetail) return;
        const safetyState = safetySensorName ? sensorStatus.get(safetySensorName) : 'unknown';
        const warnings = thresholdedSensors
            .filter(([name]) => name !== safetySensorName && sensorStatus.get(name) === 'warning')
            .map(([name]) => sensorLabel(name));
        let state = 'assessing';
        let label = 'Assessing';
        let detail = 'Awaiting safety sensor';

        if (safetyState === 'favorable') {
            state = 'safe';
            label = 'Safe to observe';
            detail = warnings.length ? `${warnings[0]} needs attention` : 'Safety sensor permits observing';
        } else if (safetyState === 'warning') {
            state = 'unsafe';
            label = 'Unsafe to observe';
            detail = warnings.length
                ? `${warnings.slice(0, 2).join(' · ')}${warnings.length > 2 ? ` +${warnings.length - 2}` : ''}`
                : 'Safety sensor is blocking observation';
        }

        setHeroGradient(state);
        observingStatus.dataset.state = state;
        observingStatusLabel.textContent = label;
        observingStatusDetail.textContent = detail;
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
        silenceMiniChart(chart);
    }

    function silenceMiniChart(chart) {
        if (!chart || !chart.renderTo) return;
        chart.renderTo.setAttribute('aria-hidden', 'true');
        chart.renderTo.removeAttribute('role');
        chart.renderTo.removeAttribute('aria-label');
        const svg = chart.renderTo.querySelector('svg');
        if (svg) {
            svg.setAttribute('aria-hidden', 'true');
            svg.removeAttribute('role');
            svg.removeAttribute('aria-label');
        }
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
            accessibility: { enabled: false },
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
        container.setAttribute('aria-hidden', 'true');
        applyMiniChartTheme(miniCharts[name]);
        updateTrendSummary(name, cfg);
    }

    function refreshMiniChart(name) {
        const chart = miniCharts[name];
        if (!chart || !chart.series[0]) return;
        chart.series[0].setData((miniChartData[name] || []).slice(), true, false, false);
        silenceMiniChart(chart);
        updateTrendSummary(name, topics[name] || {});
    }

    function updateTrendSummary(name, cfg) {
        const summary = document.getElementById('trend-' + sanitize(name));
        if (!summary) return;
        const points = miniChartData[name] || [];
        if (points.length < 2) {
            summary.textContent = 'Three-hour trend unavailable.';
            return;
        }
        const first = points[0][1];
        const last = points[points.length - 1][1];
        const tolerance = Math.max(Math.abs(first) * 0.005, 0.01);
        const direction = Math.abs(last - first) <= tolerance ? 'steady' : (last > first ? 'rising' : 'falling');
        const unit = cfg.unit ? ` ${cfg.unit}` : '';
        summary.textContent = `Three-hour trend ${direction}, from ${first.toFixed(2)} to ${last.toFixed(2)}${unit}.`;
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
        card.className = 'obs-readout-card obs-readout-card--compact';
        const icon = icons[name] || 'SEN';
        const label = escapeHtml(sensorLabel(name));
        const unitMarkup = cfg.unit ? `<span class="obs-readout-unit">${escapeHtml(cfg.unit)}</span>` : '';
        const targetText = escapeHtml(sensorTargetLabel(name, cfg));
        card.innerHTML = `
            <div class="obs-compact-card-inner">
                <div class="obs-compact-card-head">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="obs-instrument-mark obs-instrument-mark--compact"><span class="obs-instrument-code">${icon}</span></div>
                        <div class="min-w-0">
                            <h3 class="truncate text-sm font-semibold capitalize text-slate-900 dark:text-slate-100">${label}</h3>
                            <p class="obs-compact-target">${targetText}</p>
                        </div>
                    </div>
                    <a href="historical.php?topic=${encodeURIComponent(name)}" class="obs-history-icon" aria-label="View ${label} history">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M6 15l4-4 3 3 7-7" /></svg>
                    </a>
                </div>
                <div class="obs-compact-reading">
                    <p class="obs-readout-value text-slate-900 dark:text-white"><span id="${id}">--</span>${unitMarkup}</p>
                    <span id="status-${sanitize(name)}" class="${statusBaseClasses} border-slate-300/60 bg-slate-500/5 text-slate-500 dark:border-slate-700 dark:text-slate-400">Awaiting</span>
                </div>
                <div class="obs-chart-well obs-mini-chart-well">
                    <div id="chart-${sanitize(name)}" class="absolute inset-0" aria-hidden="true"></div>
                </div>
                <p id="trend-${sanitize(name)}" class="sr-only">Three-hour trend loading.</p>
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
        const isLocalBroker = brokerHost === window.location.hostname || brokerHost === 'localhost' || brokerHost === '127.0.0.1';
        const protocol = location.protocol === 'https:' || !isLocalBroker ? 'wss' : 'ws';
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
            img.onload = () => {
                img.hidden = false;
                if (placeholder) placeholder.hidden = true;
            };
            img.src = skyImageUrl;
            return;
        }
        const rawValue = message.toString();
        const numericValue = parseFloat(rawValue);
        const hasNumericValue = Number.isFinite(numericValue);
        const defaultDisplayValue = hasNumericValue
            ? numericValue.toLocaleString(undefined, {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
                useGrouping: false
            })
            : rawValue;
        const entry = topicEntries.find(([, cfg]) => cfg.topic === topic);
        if (entry) {
            const [name, cfg] = entry;
            const normalizedName = name.toLowerCase();
            const id = 'value-' + sanitize(name);
            const el = document.getElementById(id);
            const statusEl = document.getElementById('status-' + sanitize(name));
            const condition = typeof cfg.condition === 'string' ? cfg.condition.toLowerCase() : null;
            const threshold = parseFloat(cfg.green);
            const hasThreshold = Number.isFinite(threshold) && (condition === 'above' || condition === 'below');
            const isTrackedSensor = sensorStatus.has(name);
            const match = hasNumericValue && hasThreshold
                ? (condition === 'above' ? numericValue > threshold : numericValue < threshold)
                : false;
            const displayValue = normalizedName === 'safe' && hasNumericValue
                ? (match ? 'Safe' : 'Unsafe')
                : defaultDisplayValue;
            if (el) { el.textContent = displayValue; }

            if (hasNumericValue && hasThreshold) {
                if (statusEl) {
                    if (match) {
                        statusEl.textContent = sensorConditionLabel(name, true);
                        statusEl.className = `${statusBaseClasses} border-emerald-400/30 bg-emerald-400/10 text-emerald-600 dark:text-emerald-300`;
                    } else {
                        statusEl.textContent = sensorConditionLabel(name, false);
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
            spacing: [8, 8, 8, 8],
            zooming: {
                type: 'x',
                mouseWheel: true
            },
            zoomType: 'x'
        },
        title: { text: null },
        subtitle: { text: null },
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
            title: { text: null },
            subtitle: { text: null },
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
