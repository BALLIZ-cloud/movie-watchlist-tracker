<?php

declare(strict_types=1);

function load_env_file(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv($key . '=' . $value);
    }
}

load_env_file(__DIR__ . '/.env');
$apiKey = $_ENV['TMDB_API_KEY'] ?? $_SERVER['TMDB_API_KEY'] ?? getenv('TMDB_API_KEY');
$apiKeyMissing = $apiKey === false || $apiKey === null || trim((string) $apiKey) === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movie Watchlist Tracker</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;700&family=Space+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/public/assets/styles.css">
</head>
<body>
    <?php include __DIR__ . '/header.php'; ?>
    <main class="shell">
        <section class="hero">
            <div class="hero-copy">
                <p class="eyebrow">PHP + TMDB + MySQL</p>
                <h1>Build a watchlist that feels like a mini movie desk.</h1>
                <p class="hero-text">
                    Search TMDB, save films instantly, keep your watched status current, and manage everything.
                </p>
            </div>
            <div class="hero-panel">
                <div class="panel-row">
                    <span class="panel-label">Saved titles</span>
                    <strong id="saved-count">0</strong>
                </div>
                <div class="panel-row">
                    <span class="panel-label">Watched</span>
                    <strong id="watched-count">0</strong>
                </div>
            </div>
        </section>

        <section class="search-panel">
            <div class="search-panel__header">
                <div>
                    <p class="section-kicker">Discover</p>
                    <h2>Search for a movie</h2>
                </div>
                <p class="section-copy">Try a title, franchise, or actor-led hit.</p>
            </div>

            <?php if ($apiKeyMissing): ?>
                <div class="banner banner-error">TMDB API key is missing. Add it in <code>.env</code> before searching.</div>
            <?php endif; ?>

            <form id="search-form" class="search-form" novalidate>
                <label class="search-input">
                    <span class="sr-only">Movie title</span>
                    <input
                        id="search-input"
                        type="text"
                        name="query"
                        placeholder="Search for Dune, The Matrix, Whiplash..."
                        autocomplete="off"
                    >
                </label>
                <button id="search-button" type="submit">Search</button>
            </form>
            <p id="search-error" class="field-error" aria-live="polite"></p>
            <div id="status-banner" class="banner hidden" aria-live="polite"></div>
            <div id="results-grid" class="card-grid" aria-live="polite"></div>
        </section>

        <section class="watchlist-panel">
            <div class="search-panel__header">
                <div>
                    <p class="section-kicker">Your Shelf</p>
                    <h2>Saved watchlist</h2>
                </div>
                <p class="section-copy">Mark titles as watched or remove them in place.</p>
            </div>
            <div id="watchlist-grid" class="card-grid" aria-live="polite"></div>
        </section>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>

    <script>
        window.APP_CONFIG = {
            apiKeyMissing: <?= $apiKeyMissing ? 'true' : 'false' ?>
        };
    </script>
    <script src="/API_Ops.js" defer></script>
    <script src="/public/assets/app.js" defer></script>
</body>
</html>
