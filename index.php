<?php
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$host = $config['host'] ?? 'localhost';
$topics = $config['topics'] ?? [];
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
    <!-- Tabulator -->
    <link href="https://unpkg.com/tabulator-tables@5.4.4/dist/css/tabulator.min.css" rel="stylesheet">
    <script src="https://unpkg.com/tabulator-tables@5.4.4/dist/js/tabulator.min.js"></script>
    <!-- MQTT over WebSocket -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/paho-mqtt/1.1.0/mqttws31.min.js"></script>
</head>
<body class="h-full bg-white text-gray-800 dark:bg-gray-900 dark:text-gray-100">
    <div class="container mx-auto p-4">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold">PubObs Live Data</h1>
            <button id="modeToggle" class="px-2 py-1 border rounded">Toggle Mode</button>
        </div>
        <div id="topic-table"></div>
        <div class="mt-6">
            <label for="topicSelect" class="block mb-2">Select Topic:</label>
            <select id="topicSelect" class="text-black rounded p-2">
            <?php foreach($topics as $key => $topic): ?>
                <option value="<?php echo htmlspecialchars($topic); ?>"><?php echo htmlspecialchars($key); ?></option>
            <?php endforeach; ?>
            </select>
        </div>
        <div id="liveChart" class="mt-6"></div>
    </div>

    <script>
    const topics = <?php echo json_encode($topics); ?>;
    const tableData = Object.keys(topics).map(key => ({topic: key, link: `<a class=\"text-blue-500\" href=\"historical.php?topic=${key}\">History</a>`}));
    const table = new Tabulator("#topic-table", {
        data: tableData,
        layout: "fitColumns",
        columns:[
            {title:"Topic", field:"topic"},
            {title:"Historical", field:"link", formatter:"html"}
        ]
    });

    const modeToggle = document.getElementById('modeToggle');
    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
    });

    const host = <?php echo json_encode($host); ?>;
    const port = 8083; // default WebSocket port for MQTT
    const client = new Paho.MQTT.Client(host, port, "webclient-" + Math.random());

    function onConnectionLost() {
        console.log('Connection lost');
    }
    function onMessageArrived(message) {
        const value = parseFloat(message.payloadString);
        const x = (new Date()).getTime();
        chart.series[0].addPoint([x, value], true, chart.series[0].data.length > 40);
    }

    client.onConnectionLost = onConnectionLost;
    client.onMessageArrived = onMessageArrived;

    client.connect({onSuccess: () => {
        const selectedTopic = document.getElementById('topicSelect').value;
        client.subscribe(selectedTopic);
    }});

    const chart = Highcharts.chart('liveChart', {
        chart: {
            type: 'spline'
        },
        title: { text: 'Live Sensor Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: 'Value', data: [] }]
    });

    document.getElementById('topicSelect').addEventListener('change', function(e){
        const newTopic = e.target.value;
        for (const t of Object.values(topics)) {
            client.unsubscribe(t);
        }
        chart.series[0].setData([]);
        client.subscribe(newTopic);
    });
    </script>
</body>
</html>
