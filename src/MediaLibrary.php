<?php

declare(strict_types=1);

final class MediaLibrary
{
    /** @var array<string, string> */
    private const MIME_TYPES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'wmv' => 'video/x-ms-wmv',
        'mpg' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'ts' => 'video/mp2t',
    ];

    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array{name: string, path: string, size: int}> */
    public function all(): array
    {
        $items = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (!isset(self::MIME_TYPES[$extension])) {
                continue;
            }

            $absolute = $file->getRealPath();
            if ($absolute === false || !$this->isInsideRoot($absolute)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($absolute, strlen($this->root) + 1));
            $items[] = [
                'name' => $file->getFilename(),
                'path' => $relative,
                'size' => $file->getSize(),
            ];

            if (count($items) >= 2_000) {
                break;
            }
        }

        usort($items, static fn (array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));

        return $items;
    }

    public function resolve(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new InvalidArgumentException('Select a valid media file.');
        }

        $candidate = $this->root . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $absolute = realpath($candidate);

        if ($absolute === false || !is_file($absolute) || !is_readable($absolute) || !$this->isInsideRoot($absolute)) {
            throw new InvalidArgumentException('The selected media file is unavailable.');
        }

        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new InvalidArgumentException('That file type is not supported.');
        }

        return $absolute;
    }

    public function mimeType(string $absolute): string
    {
        $extension = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));

        return self::MIME_TYPES[$extension] ?? 'application/octet-stream';
    }

    private function isInsideRoot(string $absolute): bool
    {
        if ($absolute === $this->root) {
            return false;
        }

        return str_starts_with($absolute, $this->root . DIRECTORY_SEPARATOR);
    }
}
