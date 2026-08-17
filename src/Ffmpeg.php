<?php

declare(strict_types=1);

final class Ffmpeg
{
    private readonly string $ffmpeg;
    private readonly string $ffprobe;

    public function __construct()
    {
        $this->ffmpeg = getenv('FFMPEG_BIN') ?: 'ffmpeg';
        $this->ffprobe = getenv('FFPROBE_BIN') ?: 'ffprobe';
    }

    /** @return array{duration: float, width: int, height: int, videoCodec: string, audioCodec: ?string} */
    public function probe(string $path): array
    {
        $command = [
            $this->ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration:stream=codec_type,codec_name,width,height',
            '-of', 'json',
            $path,
        ];

        [$exitCode, $stdout, $stderr] = $this->capture($command);
        if ($exitCode !== 0) {
            throw new RuntimeException('FFprobe could not read this video. ' . trim($stderr));
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('FFprobe returned invalid media information.');
        }

        $duration = (float) ($data['format']['duration'] ?? 0);
        $video = null;
        $audio = null;
        foreach (($data['streams'] ?? []) as $stream) {
            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }
            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        if ($duration <= 0 || !is_finite($duration) || !is_array($video)) {
            throw new RuntimeException('The selected file does not contain a readable video stream.');
        }

        return [
            'duration' => $duration,
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'videoCodec' => (string) ($video['codec_name'] ?? 'unknown'),
            'audioCodec' => is_array($audio) ? (string) ($audio['codec_name'] ?? 'unknown') : null,
        ];
    }

    public function streamClip(string $path, ClipOptions $options): int
    {
        $command = $this->clipCommand($path, $options);
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('FFmpeg could not be started.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stderr = '';

        while (!feof($pipes[1]) || !feof($pipes[2])) {
            if (connection_aborted()) {
                proc_terminate($process);
                break;
            }

            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }
            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            $ready = stream_select($read, $write, $except, 1, 0);
            if ($ready === false) {
                proc_terminate($process);
                throw new RuntimeException('The FFmpeg stream could not be read.');
            }
            if ($ready === 0) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 1_048_576);
                if ($chunk === false || $chunk === '') {
                    continue;
                }

                if ($stream === $pipes[1]) {
                    echo $chunk;
                    flush();
                } elseif (strlen($stderr) < 1_048_576) {
                    $stderr .= substr($chunk, 0, 1_048_576 - strlen($stderr));
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            error_log('FFmpeg clip failure: ' . trim($stderr));
        }

        return $exitCode;
    }

    /** @return list<string> */
    public function streamEncodeCommand(ClipOptions $options): array
    {
        $command = [
            $this->ffmpeg,
            '-hide_banner',
            '-loglevel', 'error',
            '-nostats',
            '-nostdin',
            '-i', 'pipe:0',
            '-t', number_format($options->duration(), 3, '.', ''),
            ...$this->mappingAndScaleArguments($options),
        ];

        return [...$command, ...$this->formatArguments($options), 'pipe:1'];
    }

    /** @return list<string> */
    private function clipCommand(string $path, ClipOptions $options): array
    {
        $command = [
            $this->ffmpeg,
            '-hide_banner',
            '-loglevel', 'error',
            '-nostats',
            '-nostdin',
            '-ss', number_format($options->start, 3, '.', ''),
            '-i', $path,
            '-t', number_format($options->duration(), 3, '.', ''),
            ...$this->mappingAndScaleArguments($options),
        ];

        return [...$command, ...$this->formatArguments($options), 'pipe:1'];
    }

    /** @return list<string> */
    private function mappingAndScaleArguments(ClipOptions $options): array
    {
        $scale = sprintf('scale=-2:min(ih\\,%d):flags=lanczos,format=yuv420p', $options->resolution);

        return [
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-sn',
            '-dn',
            '-vf', $scale,
        ];
    }

    /** @return list<string> */
    private function formatArguments(ClipOptions $options): array
    {
        return match ($options->format) {
            'mp4' => [
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22',
                '-c:a', 'aac', '-b:a', '160k',
                '-movflags', '+frag_keyframe+empty_moov+default_base_moof',
                '-f', 'mp4',
            ],
            'webm' => [
                '-c:v', 'libvpx-vp9', '-deadline', 'realtime', '-cpu-used', '6',
                '-crf', '32', '-b:v', '0', '-row-mt', '1',
                '-c:a', 'libopus', '-b:a', '128k',
                '-f', 'webm',
            ],
            'wmv' => [
                '-c:v', 'wmv2', '-b:v', $this->wmvBitrate($options->resolution),
                '-c:a', 'wmav2', '-b:a', '192k',
                '-f', 'asf',
            ],
            'mkv' => [
                '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '22',
                '-c:a', 'aac', '-b:a', '160k',
                '-f', 'matroska',
            ],
        };
    }

    private function wmvBitrate(int $resolution): string
    {
        return match ($resolution) {
            480 => '1500k',
            720 => '3000k',
            1080 => '6000k',
        };
    }

    /** @param list<string> $command @return array{int, string, string} */
    private function capture(array $command): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException('The media utility could not be started.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 2_097_152);
        $stderr = stream_get_contents($pipes[2], 1_048_576);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }
}
