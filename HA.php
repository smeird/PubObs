<?php
$config = json_decode(file_get_contents('mqtt_config.json'), true);
$host = $config['host'] ?? 'localhost';
$topics = $config['topics'] ?? [];
$cloudsTopic = $topics['clouds']['topic'] ?? 'Observatory/clouds';
$sqmTopic = $topics['sqm']['topic'] ?? 'Observatory/sqm';
$cloudsUnit = $topics['clouds']['unit'] ?? '';
$sqmUnit = $topics['sqm']['unit'] ?? '';
?>
<!DOCTYPE html>
<html class="h-full dark" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HA Display | Wheathampstead AstroPhotography Conditions</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="tailwind.generated.css">
    <link rel="stylesheet" href="observatory.css">
</head>
<body class="observatory-shell h-full text-slate-100">
    <main class="relative z-10 flex h-full min-h-screen flex-col items-center justify-center gap-9 px-8 py-8 text-center">
        <header>
            <p class="obs-kicker text-cyan-300">WAC · Operations display</p>
            <h1 class="mt-3 text-3xl font-semibold tracking-tight text-slate-100">Observatory Conditions</h1>
        </header>

        <section class="grid w-full max-w-6xl grid-cols-1 gap-5 md:grid-cols-2 md:gap-8">
            <div class="obs-panel p-8">
                <p class="obs-data-label text-cyan-300">ATM-04 · Cloud sensor</p>
                <p class="mt-5 text-3xl font-medium uppercase tracking-[0.18em] text-slate-300">Clouds</p>
                <p class="mt-5 font-mono text-[4.8rem] font-semibold leading-none tracking-[-0.08em] text-cyan-200 sm:text-[6.8rem] lg:text-[8.5rem]" id="cloudsValue">--</p>
                <p class="mt-2 font-mono text-3xl font-semibold text-slate-400"><?php echo htmlspecialchars($cloudsUnit, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="obs-panel p-8">
                <p class="obs-data-label text-violet-300">SKY-06 · Quality meter</p>
                <p class="mt-5 text-3xl font-medium uppercase tracking-[0.18em] text-slate-300">SQM</p>
                <p class="mt-5 font-mono text-[4.8rem] font-semibold leading-none tracking-[-0.08em] text-violet-200 sm:text-[6.8rem] lg:text-[8.5rem]" id="sqmValue">--</p>
                <p class="mt-2 font-mono text-3xl font-semibold text-slate-400"><?php echo htmlspecialchars($sqmUnit, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </section>

        <p id="mqttStatus" class="obs-status-pill text-base">MQTT · Connecting</p>
    </main>

    <script>
        const host = <?php echo json_encode($host); ?>;
        const cloudsTopic = <?php echo json_encode($cloudsTopic); ?>;
        const sqmTopic = <?php echo json_encode($sqmTopic); ?>;
        const port = 8083;
        const brokerHost = (host === 'localhost' || host === '127.0.0.1') ? window.location.hostname : host;

        const cloudsEl = document.getElementById('cloudsValue');
        const sqmEl = document.getElementById('sqmValue');
        const statusEl = document.getElementById('mqttStatus');

        let client = null;
        let connectAttempts = 0;

        function updateStatus(message, className) {
            statusEl.textContent = message;
            statusEl.className = 'obs-status-pill text-base ' + className;
        }

        function scheduleReconnect() {
            const delay = Math.min(1000 * Math.pow(2, connectAttempts), 30000);
            updateStatus('MQTT · Reconnecting', 'obs-status-pill--warn');
            setTimeout(() => {
                connectAttempts++;
                connectClient();
            }, delay);
        }

        function connectClient() {
            if (!window.mqtt) {
                updateStatus('MQTT · Unavailable', 'obs-status-pill--bad');
                return;
            }

            const isLocalBroker = brokerHost === window.location.hostname || brokerHost === 'localhost' || brokerHost === '127.0.0.1';
            const protocol = location.protocol === 'https:' || !isLocalBroker ? 'wss' : 'ws';
            client = mqtt.connect(`${protocol}://${brokerHost}:${port}`, {
                reconnectPeriod: 0,
                clientId: 'ha-display-' + Math.random()
            });

            client.on('connect', () => {
                updateStatus('MQTT · Connected', 'obs-status-pill--ok');
                connectAttempts = 0;
                client.subscribe(cloudsTopic);
                client.subscribe(sqmTopic);
            });

            client.on('message', (topic, message) => {
                const rawValue = message.toString();
                const numericValue = parseFloat(rawValue);
                const display = Number.isFinite(numericValue)
                    ? numericValue.toLocaleString(undefined, {
                        minimumFractionDigits: 0,
                        maximumFractionDigits: 2,
                        useGrouping: false
                    })
                    : rawValue;

                if (topic === cloudsTopic) cloudsEl.textContent = display;
                if (topic === sqmTopic) sqmEl.textContent = display;
            });

            client.on('close', () => {
                updateStatus('MQTT · Disconnected', 'obs-status-pill--bad');
                scheduleReconnect();
            });

            client.on('error', () => {
                updateStatus('MQTT · Error', 'obs-status-pill--bad');
            });
        }

        function loadMQTT(urls, idx = 0) {
            if (idx >= urls.length) {
                updateStatus('MQTT · Unavailable', 'obs-status-pill--bad');
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
    </script>
</body>
</html>
