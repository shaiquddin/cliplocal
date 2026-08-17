<?php

declare(strict_types=1);

final class Youtube
{
    private readonly string $ytDlp;
    private readonly string $deno;

    public function __construct(private readonly Ffmpeg $ffmpeg)
    {
        $this->ytDlp = getenv('YTDLP_BIN') ?: 'yt-dlp';
        $this->deno = getenv('DENO_BIN') ?: 'deno';
    }

    /** @return array{id: string, url: string} */
    public function parseUrl(string $value): array
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2_048) {
            throw new InvalidArgumentException('Enter a valid YouTube video URL.');
        }

        $parts = parse_url($value);
        if (!is_array($parts) || !isset($parts['host'])) {
            throw new InvalidArgumentException('Enter a complete YouTube video URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($scheme !== 'https' && $scheme !== 'http') {
            throw new InvalidArgumentException('Only HTTP and HTTPS YouTube URLs are accepted.');
        }

        $id = null;
        if ($host === 'youtu.be') {
            $segments = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));
            $id = $segments[0] ?? null;
        } elseif (in_array($host, [
            'youtube.com',
            'www.youtube.com',
            'm.youtube.com',
            'music.youtube.com',
            'youtube-nocookie.com',
            'www.youtube-nocookie.com',
        ], true)) {
            $path = (string) ($parts['path'] ?? '');
            if ($path === '/watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $id = is_string($query['v'] ?? null) ? $query['v'] : null;
            } elseif (preg_match('#^/(?:shorts|embed|live)/([A-Za-z0-9_-]{11})(?:/|$)#', $path, $matches)) {
                $id = $matches[1];
            }
        }

        if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            throw new InvalidArgumentException('The URL does not contain a valid YouTube video ID.');
        }

        return [
            'id' => $id,
            'url' => 'https://www.youtube.com/watch?v=' . $id,
        ];
    }

    /** @return array{id: string, url: string, title: string, duration: float, thumbnail: ?string, channel: ?string} */
    public function metadata(string $inputUrl): array
    {
        $parsed = $this->parseUrl($inputUrl);
        $command = [
            $this->ytDlp,
            '--ignore-config',
            '--no-playlist',
            '--skip-download',
            '--dump-single-json',
            '--no-warnings',
            '--no-cache-dir',
            '--no-cookies',
            '--socket-timeout', '20',
            '--retries', '2',
            '--extractor-retries', '2',
            '--js-runtimes', 'deno:' . $this->deno,
            '--',
            $parsed['url'],
        ];

        [$exitCode, $stdout, $stderr] = $this->capture($command);
        if ($exitCode !== 0) {
            throw new RuntimeException($this->friendlyError($stderr));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('yt-dlp returned invalid video information.');
        }

        $duration = (float) ($data['duration'] ?? 0);
        $liveStatus = (string) ($data['live_status'] ?? 'not_live');
        if (($data['is_live'] ?? false) === true || in_array($liveStatus, ['is_live', 'is_upcoming', 'post_live'], true)) {
            throw new InvalidArgumentException('Live and upcoming streams are not supported.');
        }
        if ($duration <= 0 || !is_finite($duration)) {
            throw new RuntimeException('The video duration could not be determined.');
        }

        return [
            'id' => $parsed['id'],
            'url' => $parsed['url'],
            'title' => trim((string) ($data['title'] ?? 'YouTube video')),
            'duration' => $duration,
            'thumbnail' => filter_var($data['thumbnail'] ?? null, FILTER_VALIDATE_URL) ?: null,
            'channel' => isset($data['channel']) ? trim((string) $data['channel']) : null,
        ];
    }

    /** @return array{downloader: int, encoder: int, downloaderError: string, encoderError: string} */
    public function streamClip(string $inputUrl, ClipOptions $options): array
    {
        $parsed = $this->parseUrl($inputUrl);
        $section = sprintf('*%.3f-%.3f', $options->start, $options->end);
        $formatSelector = sprintf('bv*[height<=%1$d]+ba/b[height<=%1$d]/b', $options->resolution);
        $downloadCommand = [
            $this->ytDlp,
            '--ignore-config',
            '--no-playlist',
            '--quiet',
            '--no-warnings',
            '--no-progress',
            '--no-cache-dir',
            '--no-cookies',
            '--no-part',
            '--no-mtime',
            '--socket-timeout', '25',
            '--retries', '3',
            '--fragment-retries', '3',
            '--concurrent-fragments', '1',
            '--js-runtimes', 'deno:' . $this->deno,
            '--download-sections', $section,
            '--format', $formatSelector,
            '--merge-output-format', 'mkv',
            '--output', '-',
            '--',
            $parsed['url'],
        ];
        $encodeCommand = $this->ffmpeg->streamEncodeCommand($options);

        return $this->pump($downloadCommand, $encodeCommand);
    }

    /** @param list<string> $downloadCommand @param list<string> $encodeCommand @return array{downloader: int, encoder: int, downloaderError: string, encoderError: string} */
    private function pump(array $downloadCommand, array $encodeCommand): array
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $downloader = proc_open($downloadCommand, $spec, $downloadPipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($downloader)) {
            throw new RuntimeException('yt-dlp could not be started.');
        }
        fclose($downloadPipes[0]);

        $encoder = proc_open($encodeCommand, $spec, $encodePipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($encoder)) {
            proc_terminate($downloader);
            proc_close($downloader);
            throw new RuntimeException('FFmpeg could not be started.');
        }

        foreach ([$downloadPipes[1], $downloadPipes[2], $encodePipes[0], $encodePipes[1], $encodePipes[2]] as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $buffer = '';
        $downloadOpen = true;
        $encoderInputOpen = true;
        $downloadError = '';
        $encoderError = '';

        while ($downloadOpen || $encoderInputOpen || !feof($downloadPipes[2]) || !feof($encodePipes[1]) || !feof($encodePipes[2])) {
            if (connection_aborted()) {
                proc_terminate($downloader);
                proc_terminate($encoder);
                break;
            }

            if ($downloadOpen && feof($downloadPipes[1])) {
                fclose($downloadPipes[1]);
                $downloadOpen = false;
            }
            if (!$downloadOpen && $buffer === '' && $encoderInputOpen) {
                fclose($encodePipes[0]);
                $encoderInputOpen = false;
            }

            $read = [];
            if ($downloadOpen && strlen($buffer) < 4_194_304) {
                $read[] = $downloadPipes[1];
            }
            if (!feof($downloadPipes[2])) {
                $read[] = $downloadPipes[2];
            }
            if (!feof($encodePipes[1])) {
                $read[] = $encodePipes[1];
            }
            if (!feof($encodePipes[2])) {
                $read[] = $encodePipes[2];
            }
            $write = $encoderInputOpen && $buffer !== '' ? [$encodePipes[0]] : [];
            $except = null;

            if ($read === [] && $write === []) {
                break;
            }

            $ready = stream_select($read, $write, $except, 1, 0);
            if ($ready === false) {
                proc_terminate($downloader);
                proc_terminate($encoder);
                throw new RuntimeException('The YouTube processing pipeline failed.');
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 262_144);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $downloadPipes[1]) {
                    $buffer .= $chunk;
                } elseif ($stream === $encodePipes[1]) {
                    echo $chunk;
                    flush();
                } elseif ($stream === $downloadPipes[2] && strlen($downloadError) < 1_048_576) {
                    $downloadError .= substr($chunk, 0, 1_048_576 - strlen($downloadError));
                } elseif ($stream === $encodePipes[2] && strlen($encoderError) < 1_048_576) {
                    $encoderError .= substr($chunk, 0, 1_048_576 - strlen($encoderError));
                }
            }

            if ($write !== [] && $buffer !== '') {
                $written = fwrite($encodePipes[0], $buffer);
                if ($written === false) {
                    proc_terminate($downloader);
                    proc_terminate($encoder);
                    throw new RuntimeException('FFmpeg stopped accepting the YouTube stream.');
                }
                $buffer = (string) substr($buffer, $written);
            }
        }

        if ($downloadOpen) {
            fclose($downloadPipes[1]);
        }
        if ($encoderInputOpen) {
            fclose($encodePipes[0]);
        }
        fclose($downloadPipes[2]);
        fclose($encodePipes[1]);
        fclose($encodePipes[2]);

        $downloadExit = proc_close($downloader);
        $encodeExit = proc_close($encoder);
        if ($downloadExit !== 0) {
            error_log('yt-dlp failure: ' . trim($downloadError));
        }
        if ($encodeExit !== 0) {
            error_log('YouTube FFmpeg failure: ' . trim($encoderError));
        }

        return [
            'downloader' => $downloadExit,
            'encoder' => $encodeExit,
            'downloaderError' => $downloadError,
            'encoderError' => $encoderError,
        ];
    }

    /** @param list<string> $command @return array{int, string, string} */
    private function capture(array $command): array
    {
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $spec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('yt-dlp could not be started.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 16_777_216);
        $stderr = stream_get_contents($pipes[2], 2_097_152);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    private function friendlyError(string $stderr): string
    {
        $clean = preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $stderr) ?? $stderr;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $clean) ?: [])));
        $message = $lines !== [] ? end($lines) : 'YouTube could not provide this video.';
        $message = preg_replace('/^ERROR:\s*/i', '', (string) $message) ?? (string) $message;

        return substr($message, 0, 500);
    }
}
