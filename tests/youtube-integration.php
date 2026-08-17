<?php

declare(strict_types=1);

$baseUrl = rtrim(getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8080', '/');
$sourceUrl = trim(getenv('TEST_YOUTUBE_URL') ?: '');

if ($sourceUrl === '') {
    echo "YouTube integration check skipped; set TEST_YOUTUBE_URL to a permitted test video.\n";
    exit(0);
}

/** @return array<string, mixed> */
function youtubeGetJson(string $url): array
{
    $body = file_get_contents($url);
    if ($body === false) {
        throw new RuntimeException('GET failed: ' . $url);
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
}

/** @return array<string, mixed> */
function youtubeProbeBytes(string $bytes): array
{
    $process = proc_open([
        '/usr/bin/ffprobe', '-v', 'error', '-count_packets',
        '-show_entries', 'format=duration:stream=codec_type,height,nb_read_packets',
        '-of', 'json', 'pipe:0',
    ], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start ffprobe.');
    }

    fwrite($pipes[0], $bytes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new RuntimeException('YouTube stream is invalid: ' . trim((string) $stderr));
    }

    return json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
}

$info = youtubeGetJson($baseUrl . '/api/youtube-info.php?url=' . rawurlencode($sourceUrl));
$duration = (float) ($info['duration'] ?? 0);
if (!is_string($info['id'] ?? null)
    || !preg_match('/^[A-Za-z0-9_-]{11}$/', $info['id'])
    || $duration < 2
) {
    throw new RuntimeException('YouTube metadata lookup returned unexpected data.');
}

$payload = http_build_query([
    'url' => $sourceUrl,
    'source_duration' => (string) $duration,
    'title' => (string) ($info['title'] ?? 'YouTube test clip'),
    'start' => '0.500',
    'end' => '2.000',
    'resolution' => '480',
    'format' => 'mp4',
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
    'timeout' => 180,
]]);

$clip = file_get_contents($baseUrl . '/api/youtube-clip.php', false, $context);
if ($clip === false || strlen($clip) < 1_000) {
    throw new RuntimeException('YouTube clip streaming returned no usable data.');
}

$probe = youtubeProbeBytes($clip);
$video = array_values(array_filter(
    $probe['streams'] ?? [],
    static fn (array $stream): bool => ($stream['codec_type'] ?? null) === 'video'
))[0] ?? null;
$clipDuration = (float) ($probe['format']['duration'] ?? 0);

if (!is_array($video)
    || (int) ($video['height'] ?? 0) > 480
    || (int) ($video['nb_read_packets'] ?? 0) < 1
    || $clipDuration < 1.2
    || $clipDuration > 2.0
) {
    throw new RuntimeException('The YouTube clip has incorrect media properties.');
}

echo sprintf(
    "YouTube diskless stream passed (%d bytes, %.2fs, %dp).\n",
    strlen($clip),
    $clipDuration,
    $video['height']
);
