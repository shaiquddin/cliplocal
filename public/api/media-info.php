<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/MediaLibrary.php';
require dirname(__DIR__, 2) . '/src/Ffmpeg.php';

try {
    validate_local_request();
    $relative = trim((string) ($_GET['file'] ?? ''));
    $library = new MediaLibrary(media_root());
    $path = $library->resolve($relative);
    $probe = (new Ffmpeg())->probe($path);

    json_response([
        'file' => $relative,
        'name' => basename($path),
        ...$probe,
    ]);
} catch (InvalidArgumentException $exception) {
    json_response(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    json_response(['error' => $exception->getMessage()], 500);
}
