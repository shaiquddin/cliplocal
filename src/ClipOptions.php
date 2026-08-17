<?php

declare(strict_types=1);

final class ClipOptions
{
    /** @var array<string, array{label: string, extension: string, mime: string}> */
    public const FORMATS = [
        'mp4' => ['label' => 'MP4', 'extension' => 'mp4', 'mime' => 'video/mp4'],
        'webm' => ['label' => 'WebM', 'extension' => 'webm', 'mime' => 'video/webm'],
        'wmv' => ['label' => 'WMV', 'extension' => 'wmv', 'mime' => 'video/x-ms-wmv'],
        'mkv' => ['label' => 'Matroska', 'extension' => 'mkv', 'mime' => 'video/x-matroska'],
    ];

    /** @var list<int> */
    public const RESOLUTIONS = [480, 720, 1080];

    private function __construct(
        public readonly string $media,
        public readonly float $start,
        public readonly float $end,
        public readonly int $resolution,
        public readonly string $format,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input, float $sourceDuration): self
    {
        $media = trim((string) ($input['media'] ?? ''));
        $start = filter_var($input['start'] ?? null, FILTER_VALIDATE_FLOAT);
        $end = filter_var($input['end'] ?? null, FILTER_VALIDATE_FLOAT);
        $resolution = filter_var($input['resolution'] ?? null, FILTER_VALIDATE_INT);
        $format = strtolower(trim((string) ($input['format'] ?? '')));

        if ($media === '') {
            throw new InvalidArgumentException('Select a source video.');
        }
        if ($start === false || $end === false || !is_finite($start) || !is_finite($end)) {
            throw new InvalidArgumentException('The start and end timestamps are invalid.');
        }
        if ($start < 0 || $end <= $start) {
            throw new InvalidArgumentException('The end timestamp must be after the start timestamp.');
        }
        if ($end > $sourceDuration + 0.25) {
            throw new InvalidArgumentException('The selected end timestamp exceeds the source duration.');
        }
        if (($end - $start) > 7_200) {
            throw new InvalidArgumentException('A single clip cannot exceed two hours.');
        }
        if ($resolution === false || !in_array($resolution, self::RESOLUTIONS, true)) {
            throw new InvalidArgumentException('Select a supported output resolution.');
        }
        if (!isset(self::FORMATS[$format])) {
            throw new InvalidArgumentException('Select a supported output format.');
        }

        return new self($media, round($start, 3), round($end, 3), $resolution, $format);
    }

    public function duration(): float
    {
        return $this->end - $this->start;
    }

    /** @return array{label: string, extension: string, mime: string} */
    public function formatDefinition(): array
    {
        return self::FORMATS[$this->format];
    }
}
