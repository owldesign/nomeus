<?php

namespace Zhuk\DevkitClient\Dumps;

use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Cloner\VarCloner;

/**
 * Sends structured events to devkit's dump server using VarDumper's own wire format
 * (base64(serialize([Data, context])) per line), with a `devkit` context naming the kind.
 * One socket per process; failures are silent — recording must never break the app.
 */
final class Sender
{
    /** @var resource|null */
    private $socket = null;

    private bool $failed = false;

    public function __construct(private readonly string $host) {}

    public static function requestId(): ?string
    {
        return $_SERVER['DEVKIT_REQUEST_ID'] ?? null;
    }

    /** The exact line the server receives — separated out so the framing is testable without a socket. */
    public static function frame(string $kind, array $payload, array $context = []): string
    {
        $devkit = ['kind' => $kind, 'request_id' => self::requestId()] + $context;
        if (isset($_SERVER['REQUEST_METHOD'])) {   // a web request (fpm); the CLI has argv instead
            $devkit += ['uri' => $_SERVER['REQUEST_URI'] ?? null, 'method' => $_SERVER['REQUEST_METHOD']];
        } else {
            $devkit += ['command' => implode(' ', $_SERVER['argv'] ?? [])];
        }
        $data = (new VarCloner)->cloneVar($payload);

        return base64_encode(serialize([$data, ['devkit' => $devkit]]))."\n";
    }

    public function send(string $kind, array $payload, array $context = []): void
    {
        if ($this->failed) {
            return;
        }
        $line = self::frame($kind, $payload, $context);
        if ($this->socket === null) {
            $this->socket = @stream_socket_client('tcp://'.$this->host, $errno, $errstr, 0.2);
            if ($this->socket === false) {
                $this->socket = null;
                $this->failed = true;   // nothing listening: stay quiet for the rest of this process

                return;
            }
            stream_set_timeout($this->socket, 1);
        }
        if (@fwrite($this->socket, $line) === false) {
            $this->failed = true;
        }
    }
}
