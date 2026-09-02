<?php

namespace App\Services\Init;

use Closure;

/** One thing init does — or, when already satisfied, explains why it won't. */
final class Step
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $skip,          // reason it's already done; null = will run
        private readonly ?Closure $action,      // fn(callable $log): void
        public readonly ?string $detail = null, // what it will do, for --dry-run
    ) {}

    public static function skip(string $id, string $label, string $why): self
    {
        return new self($id, $label, $why, null);
    }

    public static function run(string $id, string $label, Closure $action, ?string $detail = null): self
    {
        return new self($id, $label, null, $action, $detail);
    }

    public function execute(callable $log): void
    {
        if ($this->action !== null) {
            ($this->action)($log);
        }
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'skip' => $this->skip, 'detail' => $this->detail];
    }
}
