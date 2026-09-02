<?php

namespace App\Exceptions;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

class ValetCommandFailed extends RuntimeException
{
    public static function from(array $args, ProcessResult $result): self
    {
        $detail = trim($result->errorOutput()) ?: trim($result->output()) ?: 'no output';
        $detail = preg_replace('/\s+/', ' ', $detail);

        return new self(sprintf('valet %s failed (exit %d): %s', implode(' ', $args), $result->exitCode(), $detail));
    }
}
