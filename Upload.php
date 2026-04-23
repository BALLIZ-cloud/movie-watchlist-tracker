<?php

declare(strict_types=1);

function json_response(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response([
            'ok' => false,
            'message' => 'Unsupported request method.',
        ], 405);
    }

    if (!isset($_FILES['file'])) {
        json_response([
            'ok' => false,
            'message' => 'No file was uploaded.',
        ], 422);
    }

    $file = $_FILES['file'];
    if (!is_array($file)) {
        json_response([
            'ok' => false,
            'message' => 'Invalid upload payload.',
        ], 422);
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response([
            'ok' => false,
            'message' => 'Upload failed. Please try again.',
        ], 422);
    }

    $maxBytes = 2 * 1024 * 1024;
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        json_response([
            'ok' => false,
            'message' => 'File size must be between 1 byte and 2 MB.',
        ], 422);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $originalName = (string) ($file['name'] ?? 'upload');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExtensions, true)) {
        json_response([
            'ok' => false,
            'message' => 'Only JPG, PNG, and WEBP files are allowed.',
        ], 422);
    }

    $mimeType = mime_content_type($tmpPath) ?: '';
    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        json_response([
            'ok' => false,
            'message' => 'Uploaded file type is not valid.',
        ], 422);
    }

    $uploadDir = __DIR__ . '/public/uploads';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $safeFilename = sprintf('%s.%s', bin2hex(random_bytes(12)), $extension);
    $targetPath = $uploadDir . '/' . $safeFilename;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        throw new RuntimeException('Could not store uploaded file.');
    }

    json_response([
        'ok' => true,
        'message' => 'File uploaded successfully.',
        'file' => [
            'name' => $safeFilename,
            'path' => '/public/uploads/' . $safeFilename,
            'size' => $size,
            'mime' => $mimeType,
        ],
    ]);
} catch (RuntimeException $exception) {
    json_response([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], 500);
} catch (Throwable $throwable) {
    json_response([
        'ok' => false,
        'message' => 'Unexpected upload error.',
    ], 500);
}
