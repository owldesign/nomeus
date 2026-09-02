<?php

namespace App\Services\Services;

use App\Support\ServiceInstance;

/** Mailpit: SMTP catcher on the main port, web UI + REST API on the http aux port. Per-app inboxes come from the X-Tags header. */
final class MailpitDriver extends AbstractDriver
{
    public function type(): string { return 'mailpit'; }

    public function label(): string { return 'Mailpit'; }

    public function formulae(): array { return ['mailpit']; }

    public function defaultPort(): int { return 1025; }

    public function binary(): string { return 'mailpit'; }

    public function versionArgs(): array { return ['version']; }

    public function auxPorts(): array
    {
        return ['http' => 8025];
    }

    public function initialize(ServiceInstance $i, string $binDir): array { return []; }

    public function programArguments(ServiceInstance $i, string $binDir): array
    {
        return [
            "{$binDir}/mailpit",
            '--smtp', "127.0.0.1:{$i->port}",
            '--listen', "127.0.0.1:{$i->options['http_port']}",
            '--database', $i->dataDir().'/mailpit.db',
        ];
    }

    public function staleFiles(ServiceInstance $i): array { return []; }

    /** MAIL_SCHEME for Laravel 12+, MAIL_ENCRYPTION for 11 — both null, both harmless. */
    public function env(ServiceInstance $i): array
    {
        return [
            'MAIL_MAILER' => 'smtp',
            'MAIL_SCHEME' => 'null',
            'MAIL_HOST' => '127.0.0.1',
            'MAIL_PORT' => (string) $i->port,
            'MAIL_USERNAME' => 'null',
            'MAIL_PASSWORD' => 'null',
            'MAIL_ENCRYPTION' => 'null',
        ];
    }
}
