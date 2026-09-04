<?php

namespace App\Support;

/**
 * Turns log text into entries. Laravel entries span lines ("[ts] env.LEVEL: message" then a
 * context JSON and a stack trace); nginx and php-fpm lines are one entry each. The parser
 * reports how many bytes it consumed so a tail can stop before an entry that is still being
 * written and pick it up on the next read.
 */
final class LogParser
{
    private const LARAVEL = '/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] ([\w-]+)\.(\w+): (.*)$/';

    private const NGINX = '#^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(\w+)\] (.*)$#';

    private const FPM = '/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2}(?:\.\d+)?)\] (\w+): (.*)$/';

    private const FILE_REF = '#(/[^\s:()"\'\[\]]+\.(?:php|blade\.php|js|ts|tsx|vue))(?:\((\d+)\)|:(\d+))#';

    /**
     * @return array{entries: list<array>, consumed: int}
     */
    public static function parse(string $text, bool $complete = true): array
    {
        if ($text === '') {
            return ['entries' => [], 'consumed' => 0];
        }
        $lines = explode("\n", $text);
        $endsWithNewline = str_ends_with($text, "\n");
        if ($endsWithNewline) {
            array_pop($lines); // trailing empty element
        }

        $entries = [];
        $current = null;
        $starts = [];      // byte offset where each entry begins
        $pos = 0;

        foreach ($lines as $line) {
            $len = strlen($line) + 1;
            if (preg_match(self::LARAVEL, $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                [$message, $context] = self::splitContext($m[4]);
                $current = ['ts' => str_replace('T', ' ', $m[1]), 'env' => $m[2], 'level' => strtolower($m[3]), 'message' => $message, 'context' => $context, 'trace' => [], 'multiline' => true];
                $starts[] = $pos;
            } elseif (preg_match(self::NGINX, $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = ['ts' => str_replace('/', '-', $m[1]), 'env' => 'nginx', 'level' => strtolower($m[2]), 'message' => $m[3], 'context' => null, 'trace' => [], 'multiline' => false];
                $starts[] = $pos;
            } elseif (preg_match(self::FPM, $line, $m)) {
                if ($current) {
                    $entries[] = $current;
                }
                $current = ['ts' => $m[1], 'env' => 'php-fpm', 'level' => strtolower($m[2]), 'message' => $m[3], 'context' => null, 'trace' => [], 'multiline' => false];
                $starts[] = $pos;
            } elseif ($current !== null && $current['multiline']) {
                $current['trace'][] = $line;        // Laravel entries continue over lines (context, stack trace)
            } elseif (trim($line) !== '') {
                // Plain text: before the first recognisable entry, or after a one-line-format entry — its own entry.
                if ($current) {
                    $entries[] = $current;
                }
                $current = ['ts' => null, 'env' => null, 'level' => 'info', 'message' => $line, 'context' => null, 'trace' => [], 'multiline' => false];
                $starts[] = $pos;
            }
            $pos += $len;
        }
        if ($current) {
            $entries[] = $current;
        }

        // An unterminated last line may still be mid-write: hand that entry back next time.
        $consumed = strlen($text);
        if (! $endsWithNewline && ! $complete && $entries !== []) {
            array_pop($entries);
            $consumed = end($starts) ?: 0;
        }

        return ['entries' => array_map([self::class, 'finish'], $entries), 'consumed' => $consumed];
    }

    /** Byte offset of the first line that begins an entry, or null if none does — for aligning a seek. */
    public static function firstEntryOffset(string $text): ?int
    {
        $pos = 0;
        foreach (explode("\n", $text) as $line) {
            if (preg_match(self::LARAVEL, $line) || preg_match(self::NGINX, $line) || preg_match(self::FPM, $line)) {
                return $pos;
            }
            $pos += strlen($line) + 1;
        }

        return null;
    }

    /** "message {"exception":"…"}" → [message, context-json-or-null] */
    private static function splitContext(string $rest): array
    {
        $at = strpos($rest, ' {"');
        if ($at === false) {
            $at = strpos($rest, ' [{');
        }
        if ($at === false) {
            return [rtrim($rest), null];
        }

        return [rtrim(substr($rest, 0, $at)), trim(substr($rest, $at))];
    }

    private static function finish(array $e): array
    {
        unset($e['multiline']);
        // Drop the trailing "} " / '"}' of a context that carried on across trace lines
        while ($e['trace'] !== [] && in_array(trim(end($e['trace'])), ['', '"}', '}'], true)) {
            array_pop($e['trace']);
        }
        $e['trace'] = implode("\n", $e['trace']);
        $e['refs'] = self::refs($e['message'].' '.($e['context'] ?? '').' '.$e['trace']);
        $e['severity'] = match ($e['level']) {
            'emergency', 'alert', 'critical', 'error', 'crit', 'emerg' => 'error',
            'warning', 'warn' => 'warning',
            'notice', 'info' => 'info',
            default => 'debug',
        };

        return $e;
    }

    /** @return list<array{text:string, file:string, line:int}> unique file:line references */
    public static function refs(string $text): array
    {
        $out = [];
        if (preg_match_all(self::FILE_REF, $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $line = (int) (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? 0));
                $out[$m[0]] = ['text' => $m[0], 'file' => $m[1], 'line' => $line];
            }
        }

        return array_values($out);
    }
}
