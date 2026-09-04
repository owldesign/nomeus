<?php

namespace App\Services;

use App\Support\Editor;
use App\Support\LogParser;
use RuntimeException;

/** Incremental reads of one log file: last N KB first, then everything appended since an offset. */
final class LogTailer
{
    public const INITIAL_BYTES = 65536;

    public const MAX_BYTES = 1048576;

    public function __construct(private readonly Editor $editor) {}

    /**
     * @return array{offset:int, size:int, truncated:bool, reset:bool, entries:list<array>}
     */
    public function read(string $path, ?int $offset = null, int $initialBytes = self::INITIAL_BYTES): array
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            throw new RuntimeException("No such log: {$path}");
        }
        $size = (int) filesize($path);
        $reset = false;

        if ($offset !== null && $offset > $size) {
            $offset = null;   // truncated or rotated under us: start over
            $reset = true;
        }
        $truncated = false;
        if ($offset === null) {
            $offset = max(0, $size - $initialBytes);
            $truncated = $offset > 0;
        }
        if ($offset >= $size) {
            return ['offset' => $size, 'size' => $size, 'truncated' => false, 'reset' => $reset, 'entries' => []];
        }

        $fh = fopen($path, 'rb');
        fseek($fh, $offset);
        $chunk = (string) fread($fh, min($size - $offset, self::MAX_BYTES));
        fclose($fh);

        if ($truncated) {
            // Seeked into the middle of an entry: skip to the first line that starts one.
            $skip = LogParser::firstEntryOffset($chunk);
            if ($skip !== null && $skip > 0) {
                $offset += $skip;
                $chunk = substr($chunk, $skip);
            }
        }

        $parsed = LogParser::parse($chunk, complete: false);
        $entries = array_map(fn ($e) => $this->link($e), $parsed['entries']);

        return [
            'offset' => $offset + $parsed['consumed'],
            'size' => $size,
            'truncated' => $truncated,
            'reset' => $reset,
            'entries' => $entries,
        ];
    }

    public function truncate(string $path): void
    {
        if (! is_file($path)) {
            throw new RuntimeException("No such log: {$path}");
        }
        $fh = fopen($path, 'r+');
        if ($fh === false || ! ftruncate($fh, 0)) {
            throw new RuntimeException("Could not truncate {$path}");
        }
        fclose($fh);
    }

    private function link(array $e): array
    {
        $e['refs'] = array_map(fn ($r) => $r + ['url' => $this->editor->fileUrl($r['file'], $r['line'] ?: null)], $e['refs']);

        return $e;
    }
}
