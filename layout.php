<?php
function layout_start(string $pageTitle, string $heroTitle, string $heroSubtitle = '', array $options = []): void
{
    $extraHead = $options['extraHead'] ?? '';
    $navActions = $options['navActions'] ?? '';
    $heroAside = $options['heroAside'] ?? '';
    ?>
<!DOCTYPE html>
<html class="h-full" lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="observatory.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        };
        try {
            const storedTheme = localStorage.getItem('color-theme');
            if (storedTheme === 'dark' || (!storedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (error) {}
    </script>
    <?php echo $extraHead; ?>
</head>
<body class="observatory-shell antialiased">
    <a href="#main-content" class="obs-skip-link">Skip to observatory data</a>
    <div class="obs-frame">
            <header class="space-y-4 mb-8">
                <div class="obs-topbar">
                    <a href="index.php" class="obs-brand">
                        <img src="logo.svg" alt="Observatory telescope logo" class="obs-brand-mark">
                        <span class="obs-brand-copy">
                            <span class="obs-brand-kicker">WAC · Telemetry node</span>
                            <span class="obs-brand-title">Wheathampstead Observatory</span>
                        </span>
                    </a>
                    <div class="obs-topbar-actions">
                        <?php if ($navActions): ?>
                            <?php echo $navActions; ?>
                        <?php endif; ?>
                        <button id="modeToggle" type="button" class="obs-icon-button" aria-live="polite">
                            <span class="sr-only" id="modeToggleLabel">Toggle dark mode</span>
                            <svg id="modeIconSun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-5 w-5 hidden" fill="currentColor">
                                <path d="M12 4.75a.75.75 0 0 0 .75-.75V2a.75.75 0 0 0-1.5 0v2a.75.75 0 0 0 .75.75Zm5.25 7.25a5.25 5.25 0 1 1-10.5 0 5.25 5.25 0 0 1 10.5 0ZM4.75 12a.75.75 0 0 0-.75-.75H2a.75.75 0 0 0 0 1.5h2a.75.75 0 0 0 .75-.75Zm18 0a.75.75 0 0 0-.75-.75h-2a.75.75 0 0 0 0 1.5h2a.75.75 0 0 0 .75-.75ZM7.11 6.46a.75.75 0 0 0 0-1.06L5.7 4a.75.75 0 0 0-1.06 1.06l1.41 1.4a.75.75 0 0 0 1.06 0Zm12.25-.53 1.4-1.4A.75.75 0 1 0 19.7 3.47l-1.4 1.4a.75.75 0 1 0 1.06 1.06ZM12 19.25a.75.75 0 0 0-.75.75v2a.75.75 0 0 0 1.5 0v-2a.75.75 0 0 0-.75-.75Zm6.89-1.71a.75.75 0 0 0-1.06 0l-1.4 1.4a.75.75 0 0 0 1.06 1.06l1.4-1.4a.75.75 0 0 0 0-1.06ZM5.7 19.7a.75.75 0 1 0 1.06-1.06l-1.4-1.4a.75.75 0 0 0-1.06 1.06l1.4 1.4Z" />
                            </svg>
                            <svg id="modeIconMoon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="h-5 w-5 hidden" fill="currentColor">
                                <path d="M20.354 15.354a.75.75 0 0 0-.866-.18 6.5 6.5 0 0 1-8.662-8.662.75.75 0 0 0-.18-.866 8 8 0 1 0 9.708 9.708Z" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="obs-page-hero">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                        <div class="space-y-3">
                            <p class="obs-kicker text-cyan-300">Observatory archive · Mission data</p>
                            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-white"><?php echo htmlspecialchars($heroTitle); ?></h1>
                            <?php if ($heroSubtitle !== ''): ?>
                                <p class="max-w-2xl text-base leading-7 text-slate-300"><?php echo htmlspecialchars($heroSubtitle); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($heroAside): ?>
                            <div class="flex-shrink-0 text-sm text-slate-300">
                                <?php echo $heroAside; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            <main id="main-content" class="space-y-8">
<?php
}

function layout_end(string $extraScripts = ''): void
{
    ?>
            </main>
            <footer class="mt-10 flex flex-wrap items-center justify-between gap-3 border-t border-slate-400/20 py-6 text-xs text-slate-500 dark:text-slate-500">
                <span class="font-mono uppercase tracking-[0.15em]">WAC · Wheathampstead, UK</span>
                <span>Local observatory telemetry and sky-condition archive</span>
            </footer>
    </div>
    <script>
        (function() {
            const root = document.documentElement;
            const toggle = document.getElementById('modeToggle');
            if (!toggle) return;
            const label = document.getElementById('modeToggleLabel');
            const sun = document.getElementById('modeIconSun');
            const moon = document.getElementById('modeIconMoon');
            const storedPreference = localStorage.getItem('color-theme');
            if (storedPreference === 'dark' || (!storedPreference && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                root.classList.add('dark');
            } else {
                root.classList.remove('dark');
            }
            function syncState() {
                const isDark = root.classList.contains('dark');
                if (label) {
                    label.textContent = isDark ? 'Switch to light mode' : 'Switch to dark mode';
                }
                if (sun) {
                    sun.classList.toggle('hidden', !isDark);
                }
                if (moon) {
                    moon.classList.toggle('hidden', isDark);
                }
                localStorage.setItem('color-theme', isDark ? 'dark' : 'light');
                document.dispatchEvent(new CustomEvent('themechange', { detail: { dark: isDark } }));
            }
            toggle.addEventListener('click', () => {
                root.classList.toggle('dark');
                syncState();
            });
            syncState();
        })();
    </script>
    <?php echo $extraScripts; ?>
</body>
</html>
<?php
}
