<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/ClipOptions.php';
require dirname(__DIR__, 2) . '/src/Ffmpeg.php';
require dirname(__DIR__, 2) . '/src/Youtube.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    abort_request(405, 'Use the YouTube cutter form to create a clip.');
}

try {
    validate_local_request();
    $youtube = new Youtube(new Ffmpeg());
    $parsed = $youtube->parseUrl(trim((string) ($_POST['url'] ?? '')));
    $sourceDuration = filter_var($_POST['source_duration'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($sourceDuration === false || !is_finite($sourceDuration) || $sourceDuration <= 0 || $sourceDuration > 86_400) {
        throw new InvalidArgumentException('The YouTube video duration is invalid. Reload the video and try again.');
    }

    $options = ClipOptions::fromArray([
        ...$_POST,
        'media' => $parsed['url'],
    ], $sourceDuration);
    $format = $options->formatDefinition();
    $suppliedTitle = trim((string) ($_POST['title'] ?? ''));
    $base = safe_filename($suppliedTitle !== '' ? $suppliedTitle : 'youtube-' . $parsed['id']);
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

    $result = $youtube->streamClip($parsed['url'], $options);
    if ($result['downloader'] !== 0 || $result['encoder'] !== 0) {
        http_response_code(502);
    }
} catch (InvalidArgumentException $exception) {
    abort_request(422, $exception->getMessage());
} catch (Throwable $exception) {
    abort_request(502, $exception->getMessage());
}
