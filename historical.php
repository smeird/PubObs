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
    'sqm' => 'light'
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

// Determine requested date range; support optional start or end
$endParam   = $_GET['end']   ?? null;
$startParam = $_GET['start'] ?? null;

if ($format === 'json') {
    try {
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $conditions = [];
        $params = [];
        if ($startParam) {
            $conditions[] = 'dateTime >= :start';
            $params['start'] = $startParam . ' 00:00:00';
        }
        if ($endParam) {
            $conditions[] = 'dateTime <= :end';
            $params['end'] = $endParam . ' 23:59:59';
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
$heroTitle = $displayName . ' History';
$heroSubtitle = $unit
    ? 'Explore observatory records in ' . $unit . ' and focus on the ranges that matter most.'
    : 'Explore observatory records and focus on the ranges that matter most.';
$heroAside = '<div class="flex flex-col items-start gap-2">'
    . '<span class="text-xs font-semibold uppercase tracking-widest text-indigo-500 dark:text-indigo-300">Selected Topic</span>'
    . '<span class="text-lg font-semibold text-gray-900 dark:text-gray-100">' . htmlspecialchars($displayName, ENT_QUOTES) . '</span>';
if ($unit) {
    $heroAside .= '<span class="text-sm text-gray-600 dark:text-gray-400">Unit: ' . htmlspecialchars($unit, ENT_QUOTES) . '</span>';
}
$heroAside .= '</div>';

$navActions = '<a href="clear.php" class="inline-flex items-center gap-2 rounded-full border border-indigo-200/70 bg-white/70 px-4 py-2 text-sm font-semibold text-indigo-600 transition hover:border-indigo-300 hover:text-indigo-700 dark:border-indigo-700/60 dark:bg-gray-800/60 dark:text-indigo-200 dark:hover:text-indigo-100">'
    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.5">'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 3.75h3.75v3.75M21 3 12.75 11.25" />'
    . '<path stroke-linecap="round" stroke-linejoin="round" d="M18.75 12v6a2.25 2.25 0 0 1-2.25 2.25h-9A2.25 2.25 0 0 1 5.25 18V9a2.25 2.25 0 0 1 2.25-2.25h6" />'
    . '</svg>'
    . '<span>Monthly View</span>'
    . '</a>';

layout_start($pageTitle, $heroTitle, $heroSubtitle, [
    'extraHead' => '<script src="https://code.highcharts.com/stock/highstock.js"></script>',
    'navActions' => $navActions,
    'heroAside' => $heroAside,
]);
?>
<section>
    <div class="space-y-6 rounded-3xl bg-white/70 p-6 shadow dark:bg-gray-800/70">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-1">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">Trend explorer</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">Use the preset buttons or drag the timeline below to refine the range.</p>
            </div>
            <button id="downloadCsv" type="button" class="inline-flex items-center gap-2 rounded-full bg-indigo-500 px-4 py-2 text-sm font-semibold text-white shadow transition hover:bg-indigo-600 dark:bg-indigo-600 dark:hover:bg-indigo-500">
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
        fill: isDark ? '#1F2937' : '#EEF2FF',
        stroke: 'transparent',
        style: {
            color: isDark ? '#E5E7EB' : '#312E81',
            fontWeight: '600'
        },
        states: {
            hover: {
                fill: isDark ? '#4338CA' : '#C7D2FE',
                style: {
                    color: isDark ? '#E0E7FF' : '#312E81'
                }
            },
            select: {
                fill: '#4F46E5',
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
        maskFill: 'rgba(99, 102, 241, 0.2)',
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
        color: '#4F46E5',
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
    const gridColor = isDark ? '#374151' : '#E5E7EB';
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
