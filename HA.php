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
<html class="h-full" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HA Display | Wheathampstead AstroPhotography Conditions</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' };
    </script>
</head>
<body class="h-full bg-slate-950 text-slate-100 font-sans">
    <main class="flex h-full min-h-screen flex-col items-center justify-center gap-12 px-8 py-8 text-center">
        <h1 class="text-3xl font-semibold tracking-wide text-slate-300">Observatory Conditions</h1>

        <section class="grid w-full max-w-6xl grid-cols-2 gap-8">
            <div class="rounded-3xl border border-slate-700 bg-slate-900/80 p-8 shadow-2xl">
                <p class="text-4xl font-medium uppercase tracking-[0.2em] text-slate-300">Clouds</p>
                <p class="mt-4 text-[6.8rem] font-bold leading-none sm:text-[8.5rem]" id="cloudsValue">--</p>
                <p class="text-4xl font-semibold text-slate-300"><?php echo htmlspecialchars($cloudsUnit, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>

            <div class="rounded-3xl border border-slate-700 bg-slate-900/80 p-8 shadow-2xl">
                <p class="text-4xl font-medium uppercase tracking-[0.2em] text-slate-300">SQM</p>
                <p class="mt-4 text-[6.8rem] font-bold leading-none sm:text-[8.5rem]" id="sqmValue">--</p>
                <p class="text-4xl font-semibold text-slate-300"><?php echo htmlspecialchars($sqmUnit, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </section>

        <p id="mqttStatus" class="text-2xl font-semibold text-amber-300">Connecting to MQTT...</p>
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
            statusEl.className = className;
        }

        function scheduleReconnect() {
            const delay = Math.min(1000 * Math.pow(2, connectAttempts), 30000);
            updateStatus('Reconnecting to MQTT...', 'text-2xl font-semibold text-amber-300');
            setTimeout(() => {
                connectAttempts++;
                connectClient();
            }, delay);
        }

        function connectClient() {
            if (!window.mqtt) {
                updateStatus('MQTT unavailable', 'text-2xl font-semibold text-rose-400');
                return;
            }

            const protocol = location.protocol === 'https:' ? 'wss' : 'ws';
            client = mqtt.connect(`${protocol}://${brokerHost}:${port}`, {
                reconnectPeriod: 0,
                clientId: 'ha-display-' + Math.random()
            });

            client.on('connect', () => {
                updateStatus('Connected', 'text-2xl font-semibold text-emerald-400');
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
                updateStatus('Disconnected', 'text-2xl font-semibold text-rose-400');
                scheduleReconnect();
            });

            client.on('error', () => {
                updateStatus('MQTT error', 'text-2xl font-semibold text-rose-400');
            });
        }

        function loadMQTT(urls, idx = 0) {
            if (idx >= urls.length) {
                updateStatus('MQTT unavailable', 'text-2xl font-semibold text-rose-400');
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
