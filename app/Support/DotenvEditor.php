<?php

namespace App\Support;

use RuntimeException;

/**
 * Sets keys in a .env in place. Laravel loads .env immutably (first definition wins), so a key
 * that exists must be replaced on its line, not appended; new keys go at the end under a header.
 * Comments, order and everything else are left as they are.
 */
final class DotenvEditor
{
    /** @return array{changed: list<string>, added: list<string>, created: bool} */
    public static function apply(string $file, array $set): array
    {
        $created = false;
        if (! is_file($file)) {
            $example = dirname($file).'/.env.example';
            if (is_file($example)) {
                copy($example, $file);
            } else {
                file_put_contents($file, '');
            }
            $created = true;
        }
        $lines = preg_split('/\R/', (string) file_get_contents($file));
        $changed = [];
        $added = [];

        foreach ($set as $key => $value) {
            $value = self::quote((string) $value);
            $found = false;
            foreach ($lines as $n => $line) {
                if (preg_match('/^\s*(export\s+)?'.preg_quote($key, '/').'\s*=/', $line)) {
                    $new = ($line !== '' && preg_match('/^\s*export\s+/', $line) ? 'export ' : '')."{$key}={$value}";
                    if ($lines[$n] !== $new) {
                        $lines[$n] = $new;
                        $changed[] = $key;
                    }
                    $found = true;
                    break; // first definition is the one Laravel reads
                }
            }
            if (! $found) {
                if ($added === []) {
                    if (end($lines) !== '') {
                        $lines[] = '';
                    }
                    $lines[] = '# devkit';
                }
                $lines[] = "{$key}={$value}";
                $added[] = $key;
            }
        }

        if ($changed !== [] || $added !== [] || $created) {
            $out = implode("\n", $lines);
            if (! str_ends_with($out, "\n")) {
                $out .= "\n";
            }
            if (file_put_contents($file, $out) === false) {
                throw new RuntimeException("Could not write {$file}");
            }
        }

        return ['changed' => $changed, 'added' => $added, 'created' => $created];
    }

    /** Leave `"${VAR}"`-style and already-quoted values alone; quote anything with spaces or #. */
    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^(["\']).*\1$/s', $value)) {
            return $value;
        }
        if (preg_match('/[\s#]/', $value)) {
            return '"'.str_replace('"', '\"', $value).'"';
        }

        return $value;
    }
}
