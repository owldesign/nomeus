<?php

namespace App\Services\Doctor;

use App\Services\MailpitClient;
use App\Services\ServiceManager;

final class MailDoctor implements Section
{
    public function __construct(private readonly MailpitClient $mail, private readonly ServiceManager $services) {}

    public function name(): string
    {
        return 'mail';
    }

    public function checks(): array
    {
        $r = new Rows;
        $i = $this->mail->instance();
        if ($i === null) {
            return $r->warn('mailpit', 'no mailpit instance — devkit mail --create')->all();
        }
        $st = $this->services->status($i);
        $r->expect($st['running'], 'mailpit', "{$i->name}: smtp {$this->mail->smtpPort()} · ui {$this->mail->baseUrl()}", "{$i->name} stopped — devkit services:start {$i->name}", 'warn');

        return $r->all();
    }
}
