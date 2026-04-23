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

function env_value(string $key, string $default = ''): string
{
    if (array_key_exists($key, $_ENV)) {
        return (string) $_ENV[$key];
    }

    if (array_key_exists($key, $_SERVER)) {
        return (string) $_SERVER[$key];
    }

    $value = getenv($key);
    if ($value === false || $value === null) {
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

function validate_search_query(array $input): string
{
    $query = trim((string) ($input['q'] ?? ''));
    if ($query === '' || mb_strlen($query) < 2) {
        throw new InvalidArgumentException('Search query must be at least 2 characters.');
    }

    return $query;
}

function poster_url(?string $posterPath): ?string
{
    if ($posterPath === null || trim($posterPath) === '') {
        return null;
    }

    return 'https://image.tmdb.org/t/p/w500' . $posterPath;
}

function map_search_results(mixed $results): array
{
    if (!is_array($results)) {
        return [];
    }

    $movies = [];
    foreach ($results as $result) {
        if (!is_array($result)) {
            continue;
        }

        $id = filter_var($result['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $title = trim((string) ($result['title'] ?? ''));
        if ($id === false || $title === '') {
            continue;
        }

        $releaseDate = trim((string) ($result['release_date'] ?? ''));
        $posterPath = trim((string) ($result['poster_path'] ?? ''));

        $movies[] = [
            'tmdb_id' => (int) $id,
            'title' => $title,
            'release_date' => $releaseDate !== '' ? $releaseDate : null,
            'release_year' => $releaseDate !== '' ? substr($releaseDate, 0, 4) : null,
            'poster_path' => $posterPath !== '' ? $posterPath : null,
            'poster_url' => poster_url($posterPath !== '' ? $posterPath : null),
            'overview' => trim((string) ($result['overview'] ?? '')) ?: null,
            'vote_average' => isset($result['vote_average']) ? round((float) $result['vote_average'], 1) : null,
        ];
    }

    return $movies;
}

try {
    load_env_file(__DIR__ . '/.env');
    $query = validate_search_query($_GET);
    $apiKey = env_value('TMDB_API_KEY', '');

    if ($apiKey === '') {
        throw new RuntimeException('TMDB API key is missing from the environment.');
    }

    $url = 'https://api.themoviedb.org/3/search/movie?' . http_build_query([
        'api_key' => $apiKey,
        'query' => $query,
        'include_adult' => 'false',
        'language' => 'en-US',
        'page' => 1,
    ]);

    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize API request.');
    }

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false) {
        $message = $curlError !== '' ? $curlError : 'TMDB request failed.';
        throw new RuntimeException($message);
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        throw new RuntimeException('TMDB returned an invalid response.');
    }

    if ($statusCode >= 400) {
        $message = isset($payload['status_message']) ? (string) $payload['status_message'] : 'TMDB request was rejected.';
        throw new RuntimeException($message);
    }

    json_response([
        'ok' => true,
        'movies' => map_search_results($payload['results'] ?? []),
    ]);
} catch (InvalidArgumentException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 422);
} catch (RuntimeException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 502);
} catch (Throwable $throwable) {
    json_response([
        'ok' => false,
        'message' => 'Unexpected API error.',
    ], 500);
}
