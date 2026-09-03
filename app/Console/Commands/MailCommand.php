<?php

namespace App\Console\Commands;

use App\Services\MailpitClient;
use App\Services\ServiceManager;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

class MailCommand extends Command
{
    protected $signature = 'mail {--create : create the mailpit instance if there is none}';

    protected $description = 'Open the Mailpit inbox (starts the mailpit instance if it is stopped)';

    public function handle(MailpitClient $mail, ServiceManager $services, Shell $shell): int
    {
        $i = $mail->instance();
        if ($i === null) {
            if (! $this->option('create')) {
                $this->error('No mailpit instance. Create one: nomeus services:create mailpit   (or: nomeus mail --create)');

                return self::FAILURE;
            }
            try {
                $i = $services->create('mailpit', log: fn (string $l) => $this->line("<fg=gray>{$l}</>"));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        } elseif (! $mail->available()) {
            $this->line("<fg=gray>starting {$i->name}</>");
            try {
                $services->start($i);
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $shell->open($mail->baseUrl());
        $this->info("{$mail->baseUrl()}   ·   SMTP 127.0.0.1:{$mail->smtpPort()}   ·   .env: nomeus services:env {$i->name}");

        return self::SUCCESS;
    }
}
