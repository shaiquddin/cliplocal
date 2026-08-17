<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/MediaLibrary.php';
require dirname(__DIR__) . '/src/ClipOptions.php';
require dirname(__DIR__) . '/src/Ffmpeg.php';
require dirname(__DIR__) . '/src/Youtube.php';

$failures = [];

function expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliplocal-tests-' . bin2hex(random_bytes(4));
mkdir($testRoot);
file_put_contents($testRoot . DIRECTORY_SEPARATOR . 'sample.mp4', 'fixture');
file_put_contents($testRoot . DIRECTORY_SEPARATOR . 'ignored.txt', 'fixture');

try {
    $library = new MediaLibrary((string) realpath($testRoot));
    $items = $library->all();
    expect(count($items) === 1, 'MediaLibrary should list only supported video extensions.');
    expect($items[0]['path'] === 'sample.mp4', 'MediaLibrary should return a relative media path.');
    expect($library->resolve('sample.mp4') === realpath($testRoot . DIRECTORY_SEPARATOR . 'sample.mp4'), 'MediaLibrary should resolve an in-root file.');

    try {
        $library->resolve('../outside.mp4');
        expect(false, 'MediaLibrary should reject traversal outside the root.');
    } catch (InvalidArgumentException) {
        expect(true, 'Traversal rejected.');
    }

    $options = ClipOptions::fromArray([
        'media' => 'sample.mp4',
        'start' => '1.25',
        'end' => '5.75',
        'resolution' => '720',
        'format' => 'mp4',
    ], 10.0);
    expect(abs($options->duration() - 4.5) < 0.001, 'Clip duration should be calculated accurately.');
    expect($options->resolution === 720, 'Resolution should be parsed as an integer.');

    foreach ([
        ['start' => 5, 'end' => 5, 'resolution' => 720, 'format' => 'mp4'],
        ['start' => 0, 'end' => 11, 'resolution' => 720, 'format' => 'mp4'],
        ['start' => 0, 'end' => 5, 'resolution' => 1440, 'format' => 'mp4'],
        ['start' => 0, 'end' => 5, 'resolution' => 720, 'format' => 'exe'],
    ] as $invalid) {
        try {
            ClipOptions::fromArray(['media' => 'sample.mp4', ...$invalid], 10.0);
            expect(false, 'ClipOptions should reject invalid input.');
        } catch (InvalidArgumentException) {
            expect(true, 'Invalid clip input rejected.');
        }
    }

    $youtube = new Youtube(new Ffmpeg());
    foreach ([
        'https://www.youtube.com/watch?v=BaW_jenozKc',
        'https://youtu.be/BaW_jenozKc?t=1',
        'https://www.youtube.com/shorts/BaW_jenozKc',
    ] as $youtubeUrl) {
        $parsed = $youtube->parseUrl($youtubeUrl);
        expect($parsed['id'] === 'BaW_jenozKc', 'YouTube URLs should resolve to a strict video ID.');
        expect($parsed['url'] === 'https://www.youtube.com/watch?v=BaW_jenozKc', 'YouTube URLs should be canonicalized.');
    }

    foreach ([
        'https://example.com/watch?v=BaW_jenozKc',
        'file:///etc/passwd',
        'https://www.youtube.com/playlist?list=BaW_jenozKc',
        'https://youtube.com.evil.test/watch?v=BaW_jenozKc',
    ] as $invalidYoutubeUrl) {
        try {
            $youtube->parseUrl($invalidYoutubeUrl);
            expect(false, 'Non-video or non-YouTube URLs should be rejected.');
        } catch (InvalidArgumentException) {
            expect(true, 'Invalid YouTube URL rejected.');
        }
    }
} finally {
    unlink($testRoot . DIRECTORY_SEPARATOR . 'sample.mp4');
    unlink($testRoot . DIRECTORY_SEPARATOR . 'ignored.txt');
    rmdir($testRoot);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "All unit checks passed.\n";
