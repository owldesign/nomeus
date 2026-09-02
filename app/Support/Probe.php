<?php

namespace App\Support;

/**
 * Liveness by answering, not by process name. Root-owned daemons that rewrite
 * their argv (nginx, php-fpm) are unreliable under pgrep; a socket that accepts
 * a connection is unambiguous. Bound in the container so tests can swap it.
 */
class Probe
{
    public function tcp(string $host, int $port, float $timeout = 0.25): bool
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }

    public function unix(string $path, float $timeout = 0.25): bool
    {
        if (! file_exists($path)) {
            return false;
        }
        $socket = @fsockopen('unix://'.$path, -1, $errno, $errstr, $timeout);
        if ($socket === false) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
