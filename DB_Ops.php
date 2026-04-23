<?php

declare(strict_types=1);

use PDO;

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

function env_value(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function db_connection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        env_value('DB_HOST', '127.0.0.1'),
        (int) env_value('DB_PORT', '3306'),
        env_value('DB_NAME', 'movie_watchlist')
    );

    $pdo = new PDO(
        $dsn,
        env_value('DB_USER', 'movie_user'),
        env_value('DB_PASS', 'movie_pass'),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $pdo;
}

function poster_url(?string $posterPath): ?string
{
    if ($posterPath === null || trim($posterPath) === '') {
        return null;
    }

    return 'https://image.tmdb.org/t/p/w500' . $posterPath;
}

function map_row(array $row): array
{
    $posterPath = isset($row['poster_path']) ? (string) $row['poster_path'] : null;

    return [
        'tmdb_id' => (int) $row['tmdb_id'],
        'title' => (string) $row['title'],
        'release_date' => $row['release_date'] ?: null,
        'release_year' => $row['release_year'] ?: null,
        'poster_path' => $posterPath ?: null,
        'poster_url' => poster_url($posterPath ?: null),
        'overview' => $row['overview'] ?: null,
        'vote_average' => $row['vote_average'] !== null ? (float) $row['vote_average'] : null,
        'watched' => (bool) $row['watched'],
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function list_movies(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT tmdb_id, title, release_date, release_year, poster_path, overview, vote_average, watched, created_at, updated_at
         FROM watchlist_movies
         ORDER BY watched ASC, created_at DESC'
    );

    $rows = $statement->fetchAll();
    return array_map(static fn (array $row): array => map_row($row), $rows);
}

function validate_movie_id(mixed $value): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new InvalidArgumentException('A valid movie id is required.');
    }

    return (int) $id;
}

function validate_watchlist_payload(mixed $input): array
{
    if (!is_array($input)) {
        throw new InvalidArgumentException('Movie payload is required.');
    }

    $tmdbId = validate_movie_id($input['tmdb_id'] ?? null);
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '' || mb_strlen($title) > 255) {
        throw new InvalidArgumentException('Movie title is required and must be <= 255 characters.');
    }

    $releaseDate = trim((string) ($input['release_date'] ?? ''));
    if ($releaseDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $releaseDate)) {
        throw new InvalidArgumentException('Release date must use YYYY-MM-DD.');
    }

    $releaseYear = $releaseDate !== '' ? substr($releaseDate, 0, 4) : trim((string) ($input['release_year'] ?? ''));
    if ($releaseYear !== '' && !preg_match('/^\d{4}$/', $releaseYear)) {
        throw new InvalidArgumentException('Release year must contain 4 digits.');
    }

    $posterPath = trim((string) ($input['poster_path'] ?? ''));
    if ($posterPath !== '' && !str_starts_with($posterPath, '/')) {
        $posterPath = '/' . ltrim($posterPath, '/');
    }
    if ($posterPath !== '' && mb_strlen($posterPath) > 255) {
        throw new InvalidArgumentException('Poster path must be <= 255 characters.');
    }

    $overview = trim((string) ($input['overview'] ?? ''));
    if ($overview !== '' && mb_strlen($overview) > 65535) {
        throw new InvalidArgumentException('Overview is too long.');
    }

    $voteAverage = $input['vote_average'] ?? null;
    if ($voteAverage !== null && $voteAverage !== '') {
        if (!is_numeric($voteAverage)) {
            throw new InvalidArgumentException('Vote average must be numeric.');
        }
        $voteAverage = max(0, min(10, round((float) $voteAverage, 1)));
    } else {
        $voteAverage = null;
    }

    return [
        'tmdb_id' => $tmdbId,
        'title' => $title,
        'release_date' => $releaseDate !== '' ? $releaseDate : null,
        'release_year' => $releaseYear !== '' ? $releaseYear : null,
        'poster_path' => $posterPath !== '' ? $posterPath : null,
        'overview' => $overview !== '' ? $overview : null,
        'vote_average' => $voteAverage,
    ];
}

/**
 * @return array<string, mixed>
 */
function request_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Request body must be valid JSON.');
    }

    return $decoded;
}

try {
    load_env_file(__DIR__ . '/.env');
    $pdo = db_connection();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response([
            'ok' => true,
            'movies' => list_movies($pdo),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response([
            'ok' => false,
            'message' => 'Unsupported request method.',
        ], 405);
    }

    $payload = request_payload();
    $action = (string) ($payload['action'] ?? '');

    if ($action === 'add') {
        $movie = validate_watchlist_payload($payload['movie'] ?? []);
        $statement = $pdo->prepare(
            'INSERT INTO watchlist_movies (tmdb_id, title, release_date, release_year, poster_path, overview, vote_average)
             VALUES (:tmdb_id, :title, :release_date, :release_year, :poster_path, :overview, :vote_average)
             ON DUPLICATE KEY UPDATE
               title = VALUES(title),
               release_date = VALUES(release_date),
               release_year = VALUES(release_year),
               poster_path = VALUES(poster_path),
               overview = VALUES(overview),
               vote_average = VALUES(vote_average)'
        );
        $statement->execute($movie);

        $find = $pdo->prepare(
            'SELECT tmdb_id, title, release_date, release_year, poster_path, overview, vote_average, watched, created_at, updated_at
             FROM watchlist_movies
             WHERE tmdb_id = :tmdb_id
             LIMIT 1'
        );
        $find->execute(['tmdb_id' => $movie['tmdb_id']]);
        $saved = $find->fetch();
        if (!is_array($saved)) {
            throw new RuntimeException('Movie could not be loaded after saving.');
        }

        json_response([
            'ok' => true,
            'movie' => map_row($saved),
            'movies' => list_movies($pdo),
        ], 201);
    }

    if ($action === 'toggle') {
        $movieId = validate_movie_id($payload['tmdb_id'] ?? null);
        $statement = $pdo->prepare(
            'UPDATE watchlist_movies
             SET watched = CASE WHEN watched = 1 THEN 0 ELSE 1 END
             WHERE tmdb_id = :tmdb_id'
        );
        $statement->execute(['tmdb_id' => $movieId]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Movie not found in watchlist.');
        }

        $find = $pdo->prepare(
            'SELECT tmdb_id, title, release_date, release_year, poster_path, overview, vote_average, watched, created_at, updated_at
             FROM watchlist_movies
             WHERE tmdb_id = :tmdb_id
             LIMIT 1'
        );
        $find->execute(['tmdb_id' => $movieId]);
        $updated = $find->fetch();
        if (!is_array($updated)) {
            throw new RuntimeException('Movie could not be loaded after updating.');
        }

        json_response([
            'ok' => true,
            'movie' => map_row($updated),
            'movies' => list_movies($pdo),
        ]);
    }

    if ($action === 'delete') {
        $movieId = validate_movie_id($payload['tmdb_id'] ?? null);
        $delete = $pdo->prepare('DELETE FROM watchlist_movies WHERE tmdb_id = :tmdb_id');
        $delete->execute(['tmdb_id' => $movieId]);
        $deleted = $delete->rowCount() > 0;

        if (!$deleted) {
            throw new RuntimeException('Movie not found in watchlist.');
        }

        json_response([
            'ok' => true,
            'movies' => list_movies($pdo),
        ]);
    }

    json_response([
        'ok' => false,
        'message' => 'Unknown DB action.',
    ], 422);
} catch (InvalidArgumentException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 422);
} catch (RuntimeException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 500);
} catch (Throwable $throwable) {
    json_response([
        'ok' => false,
        'message' => 'Unexpected database error.',
    ], 500);
}
