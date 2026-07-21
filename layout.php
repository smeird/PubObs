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
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
        };
    </script>
    <?php echo $extraHead; ?>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased dark:bg-[#0b1120] dark:text-slate-100 font-sans">
    <div class="min-h-screen">
        <div class="max-w-7xl mx-auto px-5 py-6 sm:px-8 sm:py-8">
            <header class="space-y-5 mb-8">
                <div class="flex flex-wrap items-center justify-between gap-4 border border-slate-200 bg-white px-5 py-4 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                    <a href="index.php" class="flex items-center gap-3 text-slate-900 dark:text-slate-100 font-semibold hover:text-sky-700 dark:hover:text-sky-300 transition">
                        <img src="logo.svg" alt="Observatory telescope logo" class="w-9 h-9">
                        <span class="text-sm tracking-tight">Wheathampstead AstroPhotography Conditions</span>
                    </a>
                    <div class="flex items-center gap-3">
                        <?php if ($navActions): ?>
                            <?php echo $navActions; ?>
                        <?php endif; ?>
                        <button id="modeToggle" type="button" class="inline-flex items-center justify-center w-11 h-11 rounded-md border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 transition" aria-live="polite">
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
                <div class="border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-950 sm:p-8">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                        <div class="space-y-3">
                            <p class="text-sm font-semibold uppercase tracking-widest text-sky-700 dark:text-sky-300">Observatory Insights</p>
                            <h1 class="text-3xl font-semibold tracking-tight text-slate-950 dark:text-white"><?php echo htmlspecialchars($heroTitle); ?></h1>
                            <?php if ($heroSubtitle !== ''): ?>
                                <p class="max-w-2xl text-base leading-7 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($heroSubtitle); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($heroAside): ?>
                            <div class="flex-shrink-0 text-sm text-slate-600 dark:text-slate-300">
                                <?php echo $heroAside; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </header>
            <main class="space-y-10">
<?php
}

function layout_end(string $extraScripts = ''): void
{
    ?>
            </main>
        </div>
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
