<?php

namespace App\Services;

use RuntimeException;

/**
 * Turns a Laravel site's .env into something a GUI client can open, applying the same defaults
 * as config/database.php: sqlite at database/database.sqlite, 127.0.0.1, 3306/5432, user root.
 *
 * @return array{kind:'url'|'file', target:string, display:string, driver:string}
 */
final class DatabaseUrl
{
    public static function fromEnv(array $env, string $siteDir, string $label): array
    {
        $url = $env['DB_URL'] ?? $env['DATABASE_URL'] ?? '';
        if ($url !== '') {
            return ['kind' => 'url', 'target' => $url, 'display' => self::mask($url), 'driver' => (string) parse_url($url, PHP_URL_SCHEME)];
        }

        $driver = strtolower((string) ($env['DB_CONNECTION'] ?? 'sqlite'));

        if ($driver === 'sqlite') {
            $db = (string) ($env['DB_DATABASE'] ?? '');
            $path = match (true) {
                $db === '' => rtrim($siteDir, '/').'/database/database.sqlite',
                str_starts_with($db, '/') => $db,
                default => rtrim($siteDir, '/').'/'.$db,
            };

            return ['kind' => 'file', 'target' => $path, 'display' => $path, 'driver' => 'sqlite'];
        }

        $scheme = match ($driver) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'postgresql',
            default => throw new RuntimeException("No GUI URL for DB_CONNECTION={$driver}."),
        };
        $host = (string) ($env['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($env['DB_PORT'] ?? ($scheme === 'mysql' ? '3306' : '5432'));
        $user = (string) ($env['DB_USERNAME'] ?? 'root');
        $pass = (string) ($env['DB_PASSWORD'] ?? '');
        $name = (string) ($env['DB_DATABASE'] ?? '');

        $auth = rawurlencode($user).($pass !== '' ? ':'.rawurlencode($pass) : '');
        $query = '?name='.rawurlencode($label).'&statusColor=ffc83d';
        $target = "{$scheme}://{$auth}@{$host}:{$port}/".rawurlencode($name).$query;
        $display = "{$scheme}://".rawurlencode($user).($pass !== '' ? ':•••••' : '')."@{$host}:{$port}/".rawurlencode($name);

        return ['kind' => 'url', 'target' => $target, 'display' => $display, 'driver' => $driver];
    }

    /** Hide the password in a user-supplied DB_URL for display. */
    public static function mask(string $url): string
    {
        return preg_replace('~^(\w+://[^:/@]+):[^@]*@~', '$1:•••••@', $url) ?? $url;
    }
}
