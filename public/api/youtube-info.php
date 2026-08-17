<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/ClipOptions.php';
require dirname(__DIR__, 2) . '/src/Ffmpeg.php';
require dirname(__DIR__, 2) . '/src/Youtube.php';

try {
    validate_local_request();
    $url = trim((string) ($_GET['url'] ?? ''));
    $youtube = new Youtube(new Ffmpeg());
    json_response($youtube->metadata($url));
} catch (InvalidArgumentException $exception) {
    json_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    json_response(['error' => $exception->getMessage()], 502);
}
