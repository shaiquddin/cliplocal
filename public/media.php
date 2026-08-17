<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/MediaLibrary.php';

try {
    validate_local_request();
    $library = new MediaLibrary(media_root());
    $path = $library->resolve(trim((string) ($_GET['file'] ?? '')));
} catch (InvalidArgumentException $exception) {
    abort_request(404, $exception->getMessage());
} catch (Throwable $exception) {
    abort_request(500, $exception->getMessage());
}

$size = filesize($path);
if ($size === false) {
    abort_request(500, 'The media size could not be determined.');
}

$start = 0;
$end = $size - 1;
$status = 200;
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');

if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches)) {
        header('Content-Range: bytes */' . $size);
        abort_request(416, 'The requested media range is invalid.');
    }

    if ($matches[1] === '' && $matches[2] !== '') {
        $suffix = min((int) $matches[2], $size);
        $start = $size - $suffix;
    } else {
        $start = (int) $matches[1];
        if ($matches[2] !== '') {
            $end = min((int) $matches[2], $size - 1);
        }
    }

    if ($start < 0 || $start >= $size || $end < $start) {
        header('Content-Range: bytes */' . $size);
        abort_request(416, 'The requested media range is outside the file.');
    }

    $status = 206;
}

$length = $end - $start + 1;
http_response_code($status);
header('Content-Type: ' . $library->mimeType($path));
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . safe_filename(basename($path)) . '"');
if ($status === 206) {
    header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

disable_output_buffering();
$stream = fopen($path, 'rb');
if ($stream === false || fseek($stream, $start) !== 0) {
    abort_request(500, 'The media stream could not be opened.');
}

$remaining = $length;
while ($remaining > 0 && !feof($stream) && !connection_aborted()) {
    $chunk = fread($stream, min(1_048_576, $remaining));
    if ($chunk === false || $chunk === '') {
        break;
    }
    echo $chunk;
    flush();
    $remaining -= strlen($chunk);
}
fclose($stream);
