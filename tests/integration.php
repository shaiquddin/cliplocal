<?php

declare(strict_types=1);

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8080', '/');

/** @return array<string, mixed> */
function getJson(string $url): array
{
    $body = file_get_contents($url);
    if ($body === false) {
        throw new RuntimeException('GET failed: ' . $url);
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function probeBytes(string $bytes): array
{
    $command = [
        '/usr/bin/ffprobe', '-v', 'error',
        '-count_packets',
        '-show_entries', 'format=duration,format_name:stream=codec_type,codec_name,width,height,nb_read_packets',
        '-of', 'json', 'pipe:0',
    ];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ffprobe.');
    }

    $offset = 0;
    while ($offset < strlen($bytes)) {
        $written = @fwrite($pipes[0], substr($bytes, $offset, 65_536));
        if ($written === false || $written === 0) {
            break;
        }
        $offset += $written;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException('Generated stream is invalid: ' . trim((string) $stderr));
    }

    return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
}

$page = file_get_contents($baseUrl . '/');
if ($page === false || !str_contains($page, 'ClipLocal')) {
    throw new RuntimeException('The application page did not load.');
}

$library = getJson($baseUrl . '/api/media.php');
if (($library['media'][0]['path'] ?? null) !== 'synthetic.mp4') {
    throw new RuntimeException('The synthetic media source was not discovered.');
}

$info = getJson($baseUrl . '/api/media-info.php?file=synthetic.mp4');
if (abs((float) ($info['duration'] ?? 0) - 6.0) > 0.25) {
    throw new RuntimeException('Media duration probing failed.');
}

$rangeContext = stream_context_create(['http' => [
    'method' => 'GET',
    'header' => "Range: bytes=0-99\r\n",
    'ignore_errors' => true,
]]);
$range = file_get_contents($baseUrl . '/media.php?file=synthetic.mp4', false, $rangeContext);
if ($range === false || strlen($range) !== 100) {
    throw new RuntimeException('Byte-range preview streaming failed.');
}

foreach (['mp4', 'webm', 'wmv', 'mkv'] as $format) {
    $payload = http_build_query([
        'media' => 'synthetic.mp4',
        'start' => '1.000',
        'end' => '2.500',
        'resolution' => '480',
        'format' => $format,
    ]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", [
            'Content-Type: application/x-www-form-urlencoded',
            'Origin: ' . $baseUrl,
            'Sec-Fetch-Site: same-origin',
            'Content-Length: ' . strlen($payload),
        ]) . "\r\n",
        'content' => $payload,
        'ignore_errors' => true,
        'timeout' => 120,
    ]]);

    $clip = file_get_contents($baseUrl . '/api/clip.php', false, $context);
    if ($clip === false || strlen($clip) < 1_000) {
        throw new RuntimeException(strtoupper($format) . ' clip streaming returned no usable data.');
    }

    $probe = probeBytes($clip);
    $duration = (float) ($probe['format']['duration'] ?? 0);
    $video = array_values(array_filter(
        $probe['streams'] ?? [],
        static fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'video'
    ))[0] ?? null;

    $durationValid = $format === 'wmv' || ($duration >= 1.3 && $duration <= 1.8);
    $packets = is_array($video) ? (int) ($video['nb_read_packets'] ?? 0) : 0;
    if (!$durationValid || !is_array($video) || (int) ($video['height'] ?? 0) > 480 || $packets < 1) {
        throw new RuntimeException(strtoupper($format) . ' output properties are incorrect.');
    }

    $durationLabel = $duration > 0 ? sprintf('%.2fs', $duration) : 'streamed duration';
    echo sprintf("%s stream passed (%d bytes, %s, %dp).\n", strtoupper($format), strlen($clip), $durationLabel, $video['height']);
    unset($clip);
}

echo "All integration checks passed without writing an output file.\n";
