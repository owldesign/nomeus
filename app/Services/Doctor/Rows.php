<?php

namespace App\Services\Doctor;

/** Tiny collector so every section builds rows the same way. */
final class Rows
{
    /** @var list<array{level:string, check:string, detail:string}> */
    private array $rows = [];

    public function ok(string $check, string $detail): self
    {
        return $this->add('ok', $check, $detail);
    }

    public function warn(string $check, string $detail): self
    {
        return $this->add('warn', $check, $detail);
    }

    public function fail(string $check, string $detail): self
    {
        return $this->add('fail', $check, $detail);
    }

    /** ok when $good, else the given level with the fix in $bad. */
    public function expect(bool $good, string $check, string $okDetail, string $bad, string $level = 'fail'): self
    {
        return $good ? $this->ok($check, $okDetail) : $this->add($level, $check, $bad);
    }

    private function add(string $level, string $check, string $detail): self
    {
        $this->rows[] = ['level' => $level, 'check' => $check, 'detail' => $detail];

        return $this;
    }

    public function all(): array
    {
        return $this->rows;
    }
}
