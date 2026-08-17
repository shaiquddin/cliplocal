<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
require dirname(__DIR__, 2) . '/src/MediaLibrary.php';

try {
    validate_local_request();
    $library = new MediaLibrary(media_root());
    json_response(['media' => $library->all()]);
} catch (Throwable $exception) {
    json_response(['error' => $exception->getMessage()], 500);
}
