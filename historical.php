<?php
$key = $_GET['topic'] ?? '';
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$unit = $config['topics'][$key]['unit'] ?? '';

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
    'sqm' => 'light',
    'Wind Speed' => 'wind'
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
$format = $_GET['format'] ?? '';

function normalizeDateParam(?string $value, bool $isEnd = false): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    $trimmed = trim($value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
        return $trimmed . ($isEnd ? ' 23:59:59' : ' 00:00:00');
    }

    $normalized = str_replace('T', ' ', $trimmed);
    $normalized = preg_replace('/Z$/', '', $normalized);

    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $normalized)) {
        return $normalized . ':00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $normalized)) {
        return $normalized;
    }

    return $trimmed . ($isEnd ? ' 23:59:59' : ' 00:00:00');
}

// Determine requested date range; support optional start or end
$endParam   = $_GET['end']   ?? null;
$startParam = $_GET['start'] ?? null;

if ($format === 'json') {
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $conditions = [];
        $params = [];
        $normalizedStart = normalizeDateParam($startParam, false);
        $normalizedEnd = normalizeDateParam($endParam, true);

        if ($normalizedStart) {
            $conditions[] = 'dateTime >= :start';
            $params['start'] = $normalizedStart;
        }
        if ($normalizedEnd) {
            $conditions[] = 'dateTime <= :end';
            $params['end'] = $normalizedEnd;
        }

        $query = "SELECT dateTime AS timestamp, `$column` AS value FROM obs_weather";
        if ($conditions) {
            $query .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $query .= ' ORDER BY dateTime ASC';

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);

        header('Content-Type: application/json');
        echo '[';
        $first = true;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$first) {
                echo ',';
            }
            echo json_encode($row);
            $first = false;
        }
        echo ']';
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo '[]';
    }
    exit;
}

require_once 'layout.php';

$displayName = ucwords(str_replace(['-', '_'], ' ', $key));
$pageTitle = 'History: ' . $displayName . ($unit ? ' (' . $unit . ')' : '') . ' - Wheathampstead AstroPhotography Conditions';
$heroTitle = $displayName . ' telemetry';
$heroSubtitle = $unit
    ? 'Explore observatory records in ' . $unit . ' and focus on the ranges that matter most.'
    : 'Explore observatory records and focus on the ranges that matter most.';
$heroAside = '<div class="flex flex-col items-start gap-2 border-l border-cyan-300/30 pl-5">'
    . '<span class="obs-data-label text-cyan-300">Selected channel</span>'
    . '<span class="font-mono text-lg font-semibold text-white">' . htmlspecialchars($displayName, ENT_QUOTES) . '</span>';
if ($unit) {
    $heroAside .= '<span class="text-sm text-slate-400">Unit · ' . htmlspecialchars($unit, ENT_QUOTES) . '</span>';
}
$heroAside .= '</div>';

$navActions = '<a href="clear.php" class="obs-nav-link">'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5">'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.75h3.75v3.75M21 3 12.75 11.25" />'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M18.75 12v6a2.25 2.25 0 0 1-2.25 2.25h-9A2.25 2.25 0 0 1 5.25 18V9a2.25 2.25 0 0 1 2.25-2.25h6" />'
    . '</svg>'
    . '<span class="obs-nav-label">Monthly view</span>'
    . '</a>';

layout_start($pageTitle, $heroTitle, $heroSubtitle, [
    'extraHead' => '<script src="https://code.highcharts.com/stock/highstock.js"></script>'
        . '<script src="https://code.highcharts.com/modules/accessibility.js"></script>',
    'navActions' => $navActions,
    'heroAside' => $heroAside,
]);
?>
<section>
    <div class="obs-panel space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <p class="obs-data-label">ANL-01 · Signal history</p>
                <h2 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Trend explorer</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400">Use the preset buttons or drag the timeline below to refine the range.</p>
            </div>
            <button id="downloadCsv" type="button" class="obs-button obs-button--primary">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 3.5-3.5M12 15l-3.5-3.5M5 21h14" />
                </svg>
                <span>Download CSV</span>
            </button>
        </div>
        <div id="histChart" class="h-[28rem] w-full"></div>
    </div>
</section>
<?php
$unitJson = json_encode($unit);
$topicJson = json_encode($key);
$startJson = json_encode($startParam);
$endJson = json_encode($endParam);
$csvNameJson = json_encode($key . '_history.csv');

ob_start();
?>
<script>
const unit = <?= $unitJson ?>;
const topic = <?= $topicJson ?>;
const startParam = <?= $startJson ?>;
const endParam = <?= $endJson ?>;
let data = [];

function createButtonTheme(isDark) {
    return {
        fill: isDark ? '#0B1324' : '#ECFEFF',
        stroke: 'transparent',
        style: {
            color: isDark ? '#67E8F9' : '#0E7490',
            fontWeight: '600'
        },
        states: {
            hover: {
                fill: isDark ? '#155E75' : '#CFFAFE',
                style: {
                    color: isDark ? '#ECFEFF' : '#155E75'
                }
            },
            select: {
                fill: '#0891B2',
                style: {
                    color: '#FFFFFF'
                }
            }
        }
    };
}

const histChart = Highcharts.stockChart('histChart', {
    chart: {
        type: 'line',
        backgroundColor: 'transparent',
        style: { fontFamily: 'inherit' },
        zooming: {
            type: 'x',
            mouseWheel: true
        },
        zoomType: 'x',
        resetZoomButton: {
            theme: createButtonTheme(document.documentElement.classList.contains('dark'))
        }
    },
    title: { text: null },
    legend: { enabled: false },
    credits: { enabled: false },
    rangeSelector: {
        selected: 3,
        buttons: [
            { type: 'day', count: 1, text: '1d' },
            { type: 'day', count: 3, text: '3d' },
            { type: 'week', count: 1, text: '1w' },
            { type: 'month', count: 1, text: '1m' },
            { type: 'all', text: 'All' }
        ],
        buttonTheme: createButtonTheme(document.documentElement.classList.contains('dark')),
        labelStyle: { fontWeight: '600' },
        inputStyle: { fontWeight: '600' },
        inputBoxBorderColor: 'transparent',
        inputBoxBackgroundColor: 'transparent'
    },
    navigator: {
        maskFill: 'rgba(8, 145, 178, 0.2)',
        outlineColor: 'transparent'
    },
    scrollbar: { enabled: false },
    xAxis: { type: 'datetime' },
    yAxis: {
        title: { text: unit || undefined },
        lineWidth: 1
    },
    series: [{
        name: unit ? (topic + ' (' + unit + ')') : topic,
        data: [],
        color: '#0891B2',
        lineWidth: 2,
        tooltip: {
            valueSuffix: unit ? ' ' + unit : ''
        }
    }]
});

function loadData() {
    const params = new URLSearchParams({ topic, format: 'json' });
    if (startParam) params.append('start', startParam);
    if (endParam) params.append('end', endParam);
    fetch('historical.php?' + params.toString())
        .then(r => r.json())
        .then(rows => {
            data = rows;
            const chartData = data.map(r => [Date.parse(r.timestamp), parseFloat(r.value)]);
            histChart.series[0].setData(chartData, true, true, false);
        });
}

loadData();

function updateChartTheme() {
    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#F9FAFB' : '#1F2937';
    const gridColor = isDark ? 'rgba(148, 163, 184, 0.14)' : 'rgba(15, 23, 42, 0.09)';
    histChart.update({
        chart: {
            resetZoomButton: {
                theme: createButtonTheme(isDark)
            }
        },
        xAxis: {
            labels: { style: { color: textColor } },
            gridLineColor: gridColor,
            lineColor: gridColor
        },
        yAxis: {
            labels: { style: { color: textColor } },
            title: { style: { color: textColor } },
            gridLineColor: gridColor,
            lineColor: gridColor
        },
        navigator: {
            xAxis: {
                labels: { style: { color: textColor } }
            },
            outlineColor: gridColor
        },
        rangeSelector: {
            buttonTheme: createButtonTheme(isDark),
            labelStyle: { color: textColor, fontWeight: '600' },
            inputStyle: {
                color: textColor,
                backgroundColor: isDark ? '#111827' : '#FFFFFF',
                borderColor: gridColor
            },
            inputBoxBorderColor: gridColor,
            inputBoxBackgroundColor: isDark ? '#111827' : '#FFFFFF'
        }
    }, false);
    histChart.redraw();
}

document.addEventListener('themechange', updateChartTheme);
updateChartTheme();

document.getElementById('downloadCsv').addEventListener('click', () => {
    const csvRows = ['timestamp,value'];
    data.forEach(r => {
        csvRows.push(r.timestamp + ',' + r.value);
    });
    const blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = <?= $csvNameJson ?>;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
});
</script>
<?php
$script = ob_get_clean();

layout_end($script);
