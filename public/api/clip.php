<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/MediaLibrary.php';
require dirname(__DIR__, 2) . '/src/ClipOptions.php';
require dirname(__DIR__, 2) . '/src/Ffmpeg.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    abort_request(405, 'Use the cutter form to create a clip.');
}

try {
    validate_local_request();
    $library = new MediaLibrary(media_root());
    $relative = trim((string) ($_POST['media'] ?? ''));
    $path = $library->resolve($relative);
    $ffmpeg = new Ffmpeg();
    $probe = $ffmpeg->probe($path);
    $options = ClipOptions::fromArray($_POST, $probe['duration']);
    $format = $options->formatDefinition();

    $base = safe_filename(pathinfo($path, PATHINFO_FILENAME));
    $timeRange = sprintf('%s-%s', (int) floor($options->start), (int) ceil($options->end));
    $downloadName = sprintf(
        '%s-clip-%s-%dp.%s',
        $base,
        $timeRange,
        $options->resolution,
        $format['extension']
    );

    disable_output_buffering();
    header('Content-Type: ' . $format['mime']);
    header("Content-Disposition: attachment; filename=\"{$downloadName}\"; filename*=UTF-8''" . rawurlencode($downloadName));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Accel-Buffering: no');
    header('Referrer-Policy: no-referrer');

    $exitCode = $ffmpeg->streamClip($path, $options);
    if ($exitCode !== 0) {
        http_response_code(500);
    }
} catch (InvalidArgumentException $exception) {
    abort_request(422, $exception->getMessage());
} catch (Throwable $exception) {
    abort_request(500, $exception->getMessage());
}
