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
    <!-- Highcharts -->
    <script src="https://code.highcharts.com/highcharts.js"></script>
    <!-- MQTT over WebSocket -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/paho-mqtt/1.1.0/mqttws31.min.js"></script>
</head>
<body class="h-full bg-white text-gray-800 dark:bg-gray-900 dark:text-gray-100">
    <div class="container mx-auto p-4">
        <div class="flex justify-between items-center mb-4">
            <h1 class="text-2xl font-bold">PubObs Live Data</h1>
            <button id="modeToggle" class="px-2 py-1 border rounded">Toggle Mode</button>
        </div>
        <div id="cards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4"></div>
        <div id="liveChart" class="mt-6"></div>
    </div>

    <script>
    const topics = <?php echo json_encode($topics); ?>;
    const host = <?php echo json_encode($host); ?>;
    const port = 8083; // default WebSocket port for MQTT
    const topicEntries = Object.entries(topics);
    let selectedName = topicEntries.length ? topicEntries[0][0] : '';
    let selectedTopic = topicEntries.length ? topicEntries[0][1] : null;

    const cardsContainer = document.getElementById('cards');
    const sanitize = name => name.replace(/[^a-zA-Z0-9_-]/g, '_');

    topicEntries.forEach(([name, topic]) => {
        const id = 'value-' + sanitize(name);
        const card = document.createElement('div');
        card.className = 'bg-gray-100 dark:bg-gray-800 p-4 rounded shadow';
        card.innerHTML = `
            <h2 class="text-xl font-semibold">${name}</h2>
            <p id="${id}" class="text-2xl mt-2">--</p>
            <div class="mt-2 flex space-x-2">
                <a href="historical.php?topic=${encodeURIComponent(name)}" class="text-blue-500">History</a>
                <button class="px-2 py-1 bg-blue-500 text-white rounded show-chart" data-topic="${topic}" data-name="${name}">Show</button>
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

    const client = new Paho.MQTT.Client(host, port, "webclient-" + Math.random());

    function onConnectionLost() {
        console.log('Connection lost');
    }
    function onMessageArrived(message) {
        const topic = message.destinationName;
        const value = parseFloat(message.payloadString);
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
    }

    client.onConnectionLost = onConnectionLost;
    client.onMessageArrived = onMessageArrived;

    client.connect({ onSuccess: () => {
        Object.values(topics).forEach(t => client.subscribe(t));
    }});

    const chart = Highcharts.chart('liveChart', {
        chart: { type: 'spline' },
        title: { text: selectedName ? 'Live Sensor Data: ' + selectedName : 'Live Sensor Data' },
        xAxis: { type: 'datetime' },
        series: [{ name: 'Value', data: [] }]
    });

    const modeToggle = document.getElementById('modeToggle');
    modeToggle.addEventListener('click', () => {
        document.documentElement.classList.toggle('dark');
    });
    </script>
</body>
</html>
