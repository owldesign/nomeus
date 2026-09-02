<?php

namespace Zhuk\DevkitClient;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Str;

/** Adds "X-Tags: <app>" to every outgoing message; Mailpit files it under that tag. */
final class TagOutgoingMail
{
    public function handle(MessageSending $event): void
    {
        $headers = $event->message->getHeaders();
        if ($headers->has('X-Tags')) {
            return;
        }
        $tag = config('devkit-client.mail_tag') ?: Str::slug((string) config('app.name', 'app'));
        if ($tag !== '') {
            $headers->addTextHeader('X-Tags', $tag);
        }
    }
}
