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
    $pdo = new PDO("pgsql:host=$dbHost;dbname=$dbName", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

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
    $stmt = $pdo->prepare("SELECT DATE(datetime) AS day, SUM(safe)::double precision/60 AS hours FROM obs_weather WHERE datetime BETWEEN :start AND :end GROUP BY day ORDER BY day");
    $stmt->execute(['start' => $queryStart, 'end' => $queryEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($dayMap[$row['day']])) {
            $dayMap[$row['day']] = (float)$row['hours'];
        }
    }

    // Include time from last record to now if still safe
    $rangeStart = strtotime('today -29 days');
    $lastRow = $pdo->query("SELECT datetime, safe FROM obs_weather ORDER BY datetime DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lastRow && (int)$lastRow['safe'] === 1) {
        $segmentStart = max(strtotime($lastRow['datetime']), $rangeStart);
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
    <script src="vendor/suncalc-1.9.0.js"></script>
    <script src="dashboard-logic.js"></script>
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
                    <p class="obs-kicker text-cyan-300">Live site status · 51.81° N · 0.29° W</p>
                    <h1>Observatory conditions</h1>
                    <p>Nine local instruments, camera imagery, and observing history in one operational view.</p>
                </div>
                <div class="obs-compact-system-grid" aria-label="Observatory system overview">
                    <div id="tonightSummary" title="Astronomical darkness for the approximate public location 51.81° N, 0.29° W">
                        <span id="tonightLabel" class="obs-data-label text-cyan-300">Tonight · Calculating</span>
                        <strong id="tonightWindow">Synchronising</strong>
                    </div>
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
                            <div class="obs-compact-panel-actions">
                                <span id="cameraFreshness" class="obs-frame-age" role="status">Awaiting frame</span>
                                <button type="button" data-target="skyImageContainer" class="fullscreen-toggle obs-icon-button" aria-label="Toggle full screen for sky image">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 3H5a2 2 0 0 0-2 2v3m0 8v3a2 2 0 0 0 2 2h3m8 0h3a2 2 0 0 0 2-2v-3m0-8V5a2 2 0 0 0-2-2h-3" /></svg>
                                </button>
                            </div>
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

    const dashboardLogic = window.ObservatoryDashboardLogic;
    const SENSOR_STALE_MS = 150 * 1000;
    const CAMERA_STALE_MS = 5 * 60 * 1000;
    const FRESHNESS_REFRESH_MS = 5 * 1000;
    const OBSERVATORY_LATITUDE = 51.81;
    const OBSERVATORY_LONGITUDE = -0.29;
    const OBSERVATORY_TIME_ZONE = 'Europe/London';
    const lastSeen = new Map();
    const latestValues = new Map();
    const sensorPresentation = new Map();
    const topicNameByNormalized = new Map(topicEntries.map(([name]) => [name.toLowerCase(), name]));
    const temperatureSensorName = topicNameByNormalized.get('temperature') || null;
    const dewPointSensorName = topicNameByNormalized.get('dewpoint') || null;
    let cameraLastSeen = null;
    let mqttConnectionState = 'connecting';
    let freshSafetySinceConnect = false;

    const heroCard = document.getElementById('heroCard');
    const observingStatus = document.getElementById('observingStatus');
    const observingStatusLabel = document.getElementById('observingStatusLabel');
    const observingStatusDetail = document.getElementById('observingStatusDetail');
    const tonightLabel = document.getElementById('tonightLabel');
    const tonightWindow = document.getElementById('tonightWindow');
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

    function sensorIsFresh(name, now) {
        if (!dashboardLogic) return false;
        return dashboardLogic.freshnessState(lastSeen.get(name), now, SENSOR_STALE_MS) === 'fresh';
    }

    function dewRiskContext(now = Date.now()) {
        if (!dashboardLogic || !temperatureSensorName || !dewPointSensorName) return null;
        const temperature = latestValues.get(temperatureSensorName);
        const dewPoint = latestValues.get(dewPointSensorName);
        const risk = dashboardLogic.classifyDewRisk(temperature, dewPoint);
        const temperatureSeen = lastSeen.get(temperatureSensorName);
        const dewPointSeen = lastSeen.get(dewPointSensorName);
        if (!risk || !Number.isFinite(temperatureSeen) || !Number.isFinite(dewPointSeen)) return null;
        const oldestReading = Math.min(temperatureSeen, dewPointSeen);
        return {
            ...risk,
            lastSeen: oldestReading,
            freshness: dashboardLogic.freshnessState(oldestReading, now, SENSOR_STALE_MS)
        };
    }

    function updateHeroState(now = Date.now()) {
        if (!heroCard || !observingStatus || !observingStatusLabel || !observingStatusDetail) return;
        if (!dashboardLogic) {
            setHeroGradient('unknown');
            observingStatus.dataset.state = 'unknown';
            observingStatusLabel.textContent = 'Status unknown';
            observingStatusDetail.textContent = 'Dashboard logic unavailable';
            return;
        }
        const safetyState = safetySensorName ? sensorStatus.get(safetySensorName) : 'unknown';
        const warnings = thresholdedSensors
            .filter(([name]) => name !== safetySensorName && sensorStatus.get(name) === 'warning' && sensorIsFresh(name, now))
            .map(([name]) => sensorLabel(name));
        const dewRisk = dewRiskContext(now);
        if (dewRisk && dewRisk.freshness === 'fresh' && (dewRisk.state === 'watch' || dewRisk.state === 'critical')) {
            warnings.unshift(`Condensation ${dewRisk.label.toLowerCase()}`);
        }
        const coreState = dashboardLogic.observingState({
            connectionState: mqttConnectionState,
            safetyReceived: freshSafetySinceConnect,
            safetyState,
            safetyLastSeen: safetySensorName ? lastSeen.get(safetySensorName) : null,
            now,
            staleAfterMs: SENSOR_STALE_MS
        });
        const state = coreState.state;
        const label = coreState.label;
        let detail = 'Awaiting safety sensor';

        if (coreState.reason === 'connecting') detail = 'Connecting to live telemetry';
        if (coreState.reason === 'disconnected') detail = 'Live telemetry disconnected';
        if (coreState.reason === 'unavailable') detail = 'Live telemetry unavailable';
        if (coreState.reason === 'awaiting') detail = 'Awaiting fresh safety sensor';
        if (coreState.reason === 'stale') {
            const safetySeen = safetySensorName ? lastSeen.get(safetySensorName) : null;
            detail = Number.isFinite(safetySeen)
                ? `Safety feed stale · ${dashboardLogic.formatAge(safetySeen, now)} ago`
                : 'Safety feed unavailable';
        }
        if (coreState.reason === 'invalid') detail = 'Safety feed is invalid';
        if (coreState.reason === 'permitted') {
            detail = warnings.length ? `${warnings[0]} needs attention` : 'Safety sensor permits observing';
        }
        if (coreState.reason === 'blocked') {
            detail = warnings.length
                ? `${warnings.slice(0, 2).join(' · ')}${warnings.length > 2 ? ` +${warnings.length - 2}` : ''}`
                : 'Safety sensor is blocking observation';
        }

        setHeroGradient(state);
        observingStatus.dataset.state = state;
        observingStatusLabel.textContent = label;
        observingStatusDetail.textContent = detail;
    }

    function updateObservingContext(now = new Date()) {
        if (!tonightLabel || !tonightWindow) return;
        if (!dashboardLogic || !window.SunCalc) {
            tonightLabel.textContent = 'Tonight · Unavailable';
            tonightWindow.textContent = 'No calculation';
            return;
        }

        const night = dashboardLogic.observingNight(now, window.SunCalc, OBSERVATORY_LATITUDE, OBSERVATORY_LONGITUDE);
        const illuminationDate = night ? night.midpoint : now;
        const moonPercent = dashboardLogic.moonIlluminationPercent(window.SunCalc, illuminationDate);
        const moonLabel = moonPercent === null ? 'Moon --' : `Moon ${moonPercent}%`;

        if (!night) {
            tonightLabel.textContent = `Tonight · ${moonLabel}`;
            tonightWindow.textContent = 'No full darkness';
            return;
        }

        const timeRange = dashboardLogic.formatTimeRange(night.start, night.end, OBSERVATORY_TIME_ZONE);
        tonightLabel.textContent = `Astro dark · ${moonLabel}`;
        tonightWindow.textContent = timeRange || 'Unavailable';
    }

    updateHeroState();
    updateObservingContext();
    window.setInterval(updateObservingContext, 60 * 1000);

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

    const sparklineData = {};
    const THREE_HOURS_MS = 3 * 60 * 60 * 1000;
    const SPARKLINE_WIDTH = 100;
    const SPARKLINE_HEIGHT = 32;
    const SPARKLINE_PADDING = 2;

    function formatDateTimeForQuery(date) {
        return date.toISOString().slice(0, 19).replace('T', ' ');
    }

    function parseTimestamp(value) {
        if (!value) return null;
        const normalized = value.replace(' ', 'T');
        const time = Date.parse(normalized);
        return Number.isNaN(time) ? null : time;
    }

    function buildSparklinePaths(points) {
        const validPoints = points.filter(point => Number.isFinite(point[0]) && Number.isFinite(point[1]));
        if (validPoints.length === 0) return { line: '', area: '' };

        const plotWidth = SPARKLINE_WIDTH - (SPARKLINE_PADDING * 2);
        const plotHeight = SPARKLINE_HEIGHT - (SPARKLINE_PADDING * 2);
        if (validPoints.length === 1) {
            const y = SPARKLINE_HEIGHT / 2;
            const line = `M ${SPARKLINE_PADDING} ${y} L ${SPARKLINE_WIDTH - SPARKLINE_PADDING} ${y}`;
            const area = `${line} L ${SPARKLINE_WIDTH - SPARKLINE_PADDING} ${SPARKLINE_HEIGHT - SPARKLINE_PADDING} L ${SPARKLINE_PADDING} ${SPARKLINE_HEIGHT - SPARKLINE_PADDING} Z`;
            return { line, area };
        }

        const firstTime = validPoints[0][0];
        const lastTime = validPoints[validPoints.length - 1][0];
        const timeSpan = Math.max(lastTime - firstTime, 1);
        const values = validPoints.map(point => point[1]);
        const minValue = Math.min(...values);
        const maxValue = Math.max(...values);
        const valueSpan = maxValue - minValue;
        const midpoint = (minValue + maxValue) / 2;
        const minimumDisplaySpan = Math.max(Math.abs(midpoint) * 0.05, 0.1);
        const displaySpan = Math.max(valueSpan * 1.2, minimumDisplaySpan);
        const displayMax = midpoint + (displaySpan / 2);
        const coordinates = validPoints.map(([time, value]) => {
            const x = SPARKLINE_PADDING + (((time - firstTime) / timeSpan) * plotWidth);
            const y = valueSpan === 0
                ? SPARKLINE_HEIGHT / 2
                : SPARKLINE_PADDING + (((displayMax - value) / displaySpan) * plotHeight);
            return [x, y];
        });
        const line = coordinates
            .map(([x, y], index) => `${index === 0 ? 'M' : 'L'} ${x.toFixed(2)} ${y.toFixed(2)}`)
            .join(' ');
        const firstX = coordinates[0][0].toFixed(2);
        const lastX = coordinates[coordinates.length - 1][0].toFixed(2);
        const baseline = SPARKLINE_HEIGHT - SPARKLINE_PADDING;
        return {
            line,
            area: `${line} L ${lastX} ${baseline} L ${firstX} ${baseline} Z`
        };
    }

    function renderSparkline(name) {
        const container = document.getElementById('sparkline-' + sanitize(name));
        if (!container) return;
        const paths = buildSparklinePaths(sparklineData[name] || []);
        const line = container.querySelector('[data-sparkline-line]');
        const area = container.querySelector('[data-sparkline-area]');
        if (line) line.setAttribute('d', paths.line);
        if (area) area.setAttribute('d', paths.area);
        updateTrendSummary(name, topics[name] || {});
    }

    function updateTrendSummary(name, cfg) {
        const summary = document.getElementById('trend-' + sanitize(name));
        if (!summary) return;
        const points = sparklineData[name] || [];
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

    function recordSparklinePoint(name, numericValue) {
        if (!Number.isFinite(numericValue)) return;
        if (!sparklineData[name]) return;
        const now = Date.now();
        const points = sparklineData[name];
        points.push([now, numericValue]);
        const cutoff = now - THREE_HOURS_MS;
        while (points.length && points[0][0] < cutoff) {
            points.shift();
        }
        renderSparkline(name);
    }

    function initializeSparkline(name) {
        sparklineData[name] = [];
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
                    sparklineData[name] = parsed.sort((a, b) => a[0] - b[0]);
                }
            })
            .catch(() => {})
            .finally(() => {
                renderSparkline(name);
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
                            <p id="target-${sanitize(name)}" class="obs-compact-target">${targetText}</p>
                        </div>
                    </div>
                    <a href="historical.php?topic=${encodeURIComponent(name)}" class="obs-history-icon" aria-label="View ${label} history">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M6 15l4-4 3 3 7-7" /></svg>
                    </a>
                </div>
                <div class="obs-compact-reading">
                    <p class="obs-readout-value text-slate-900 dark:text-white"><span id="${id}">--</span>${unitMarkup}</p>
                    <span id="status-${sanitize(name)}" class="${statusBaseClasses} obs-sensor-status obs-sensor-status--neutral">Awaiting</span>
                </div>
                <div class="obs-chart-well obs-mini-chart-well">
                    <div id="sparkline-${sanitize(name)}" class="absolute inset-0" aria-hidden="true">
                        <svg class="obs-native-sparkline" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                            <path data-sparkline-area class="obs-native-sparkline__area"></path>
                            <path data-sparkline-line class="obs-native-sparkline__line"></path>
                        </svg>
                    </div>
                </div>
                <p id="trend-${sanitize(name)}" class="sr-only">Three-hour trend loading.</p>
            </div>
        `;
        cardsContainer.appendChild(card);
        initializeSparkline(name);
    });

    function statusToneClass(tone) {
        const normalizedTone = ['good', 'bad', 'warn', 'live', 'stale'].includes(tone) ? tone : 'neutral';
        return `${statusBaseClasses} obs-sensor-status obs-sensor-status--${normalizedTone}`;
    }

    function exactReceiptTime(timestamp) {
        if (!Number.isFinite(timestamp)) return '';
        return new Intl.DateTimeFormat('en-GB', {
            timeZone: OBSERVATORY_TIME_ZONE,
            dateStyle: 'medium',
            timeStyle: 'medium'
        }).format(new Date(timestamp));
    }

    function renderSensorStatus(name, now = Date.now()) {
        const statusElement = document.getElementById('status-' + sanitize(name));
        const card = document.getElementById('card-' + sanitize(name));
        if (!statusElement || !card || !dashboardLogic) return;

        const timestamp = lastSeen.get(name);
        const freshness = dashboardLogic.freshnessState(timestamp, now, SENSOR_STALE_MS);
        const age = dashboardLogic.formatAge(timestamp, now);
        let label = 'Awaiting';
        let tone = 'neutral';
        let isStale = false;
        let effectiveTimestamp = timestamp;

        if (freshness === 'stale') {
            label = `Stale · ${age}`;
            tone = 'stale';
            isStale = true;
        } else if (freshness === 'fresh') {
            const presentation = sensorPresentation.get(name) || { label: 'Live', tone: 'live' };
            label = `${presentation.label} · ${age}`;
            tone = presentation.tone;
        }

        if (name === dewPointSensorName) {
            const targetElement = document.getElementById('target-' + sanitize(name));
            const dewRisk = dewRiskContext(now);
            if (dewRisk) {
                effectiveTimestamp = dewRisk.lastSeen;
                const combinedAge = dashboardLogic.formatAge(dewRisk.lastSeen, now);
                if (targetElement) targetElement.textContent = `Dew margin · ${dewRisk.margin.toFixed(1)} °C`;
                if (dewRisk.freshness === 'stale') {
                    label = `Stale · ${combinedAge}`;
                    tone = 'stale';
                    isStale = true;
                } else {
                    label = `${dewRisk.label} · ${combinedAge}`;
                    tone = dewRisk.state === 'clear' ? 'good' : (dewRisk.state === 'watch' ? 'warn' : 'bad');
                    isStale = false;
                }
            } else if (freshness === 'fresh') {
                if (targetElement) targetElement.textContent = 'Dew margin · Awaiting temperature';
                label = `Margin pending · ${age}`;
                tone = 'live';
            }
        }

        statusElement.textContent = label;
        statusElement.className = statusToneClass(tone);
        card.classList.toggle('obs-readout-card--stale', isStale);
        const receiptTime = exactReceiptTime(effectiveTimestamp);
        statusElement.title = receiptTime ? `Last received ${receiptTime}` : 'No live reading received';
    }

    function renderCameraFreshness(now = Date.now()) {
        const cameraStatus = document.getElementById('cameraFreshness');
        const cameraPanel = document.getElementById('skyImageContainer');
        if (!cameraStatus || !cameraPanel || !dashboardLogic) return;

        const freshness = dashboardLogic.freshnessState(cameraLastSeen, now, CAMERA_STALE_MS);
        const age = dashboardLogic.formatAge(cameraLastSeen, now);
        cameraStatus.classList.toggle('obs-frame-age--stale', freshness === 'stale');
        cameraPanel.classList.toggle('obs-panel--stale', freshness === 'stale');
        if (freshness === 'missing') {
            cameraStatus.textContent = 'Awaiting frame';
            cameraStatus.title = 'No camera frame received';
        } else if (freshness === 'stale') {
            cameraStatus.textContent = `Frame stale · ${age}`;
            cameraStatus.title = `Last frame received ${exactReceiptTime(cameraLastSeen)}`;
        } else {
            cameraStatus.textContent = `Frame · ${age}`;
            cameraStatus.title = `Last frame received ${exactReceiptTime(cameraLastSeen)}`;
        }
    }

    function renderFreshness(now = Date.now()) {
        topicEntries.forEach(([name]) => renderSensorStatus(name, now));
        renderCameraFreshness(now);
        updateHeroState(now);
    }

    renderFreshness();
    window.setInterval(renderFreshness, FRESHNESS_REFRESH_MS);



    const statusEl = document.getElementById('mqttStatus');
    let client;
    let connectAttempts = 0;

    function updateStatus(text, state = 'warn') {
        statusEl.textContent = text;
        statusEl.className = `obs-status-pill obs-status-pill--${state}`;
    }

    function scheduleReconnect() {
        const delay = Math.min(1000 * Math.pow(2, connectAttempts), 30000);
        mqttConnectionState = 'reconnecting';
        freshSafetySinceConnect = false;
        updateStatus('MQTT · Reconnecting', 'warn');
        updateHeroState();
        setTimeout(() => {
            connectAttempts++;
            connectClient();
        }, delay);
    }

    function connectClient() {
        if (!window.mqtt) {
            console.warn('MQTT.js library is not loaded');
            mqttConnectionState = 'unavailable';
            freshSafetySinceConnect = false;
            updateStatus('MQTT · Unavailable', 'bad');
            updateHeroState();
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
        mqttConnectionState = 'disconnected';
        freshSafetySinceConnect = false;
        updateStatus('MQTT · Disconnected', 'bad');
        updateHeroState();
        scheduleReconnect();
    }
    function onMessageArrived(topic, message) {
        if (topic === 'Observatory/skyimage') {
            const img = document.getElementById('skyImage');
            const placeholder = document.getElementById('skyImagePlaceholder');
            cameraLastSeen = Date.now();
            if (skyImageUrl) URL.revokeObjectURL(skyImageUrl);
            const blob = new Blob([message], { type: 'image/jpeg' });
            skyImageUrl = URL.createObjectURL(blob);
            img.onload = () => {
                img.hidden = false;
                if (placeholder) placeholder.hidden = true;
            };
            img.src = skyImageUrl;
            renderCameraFreshness(cameraLastSeen);
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
            const receivedAt = Date.now();
            const normalizedName = name.toLowerCase();
            const id = 'value-' + sanitize(name);
            const el = document.getElementById(id);
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
            lastSeen.set(name, receivedAt);
            if (hasNumericValue) {
                latestValues.set(name, numericValue);
            } else {
                latestValues.delete(name);
            }

            if (hasNumericValue && hasThreshold) {
                sensorPresentation.set(name, {
                    label: sensorConditionLabel(name, match),
                    tone: match ? 'good' : 'bad'
                });
                if (isTrackedSensor) {
                    sensorStatus.set(name, match ? 'favorable' : 'warning');
                }
            } else {
                sensorPresentation.set(name, {
                    label: hasNumericValue ? 'Live' : 'Signal',
                    tone: hasNumericValue ? 'live' : 'neutral'
                });
                if (isTrackedSensor) {
                    sensorStatus.set(name, 'unknown');
                }
            }

            if (name === safetySensorName) {
                freshSafetySinceConnect = hasNumericValue;
            }
            renderSensorStatus(name, receivedAt);
            if (name === temperatureSensorName || name === dewPointSensorName) {
                if (temperatureSensorName) renderSensorStatus(temperatureSensorName, receivedAt);
                if (dewPointSensorName) renderSensorStatus(dewPointSensorName, receivedAt);
            }
            updateHeroState(receivedAt);
            if (hasNumericValue) {
                recordSparklinePoint(name, numericValue);
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
        mqttConnectionState = 'connected';
        freshSafetySinceConnect = false;
        updateStatus('MQTT · Connected', 'ok');
        connectAttempts = 0;
        Object.values(topics).forEach(cfg => client.subscribe(cfg.topic));
        client.subscribe('Observatory/skyimage');
        updateHeroState();
    }

    function loadMQTT(urls, idx = 0) {
        if (idx >= urls.length) {
            console.warn('MQTT.js library failed to load');
            mqttConnectionState = 'unavailable';
            freshSafetySinceConnect = false;
            updateStatus('MQTT · Unavailable', 'bad');
            updateHeroState();
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
