<?php

namespace App\Support;

final readonly class Task
{
    public function __construct(
        public string $id,
        public string $label,
        public array $argv,
        public ?string $cwd,
        public string $status,        // queued | running | done | failed
        public ?int $exitCode,
        public string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public int $timeout,
    ) {}

    public static function fromArray(array $a): self
    {
        return new self(
            id: (string) $a['id'],
            label: (string) ($a['label'] ?? ''),
            argv: array_values((array) ($a['argv'] ?? [])),
            cwd: $a['cwd'] ?? null,
            status: (string) ($a['status'] ?? 'queued'),
            exitCode: isset($a['exit_code']) ? (int) $a['exit_code'] : null,
            createdAt: (string) ($a['created_at'] ?? ''),
            startedAt: $a['started_at'] ?? null,
            finishedAt: $a['finished_at'] ?? null,
            timeout: (int) ($a['timeout'] ?? 900),
        );
    }

    public function finished(): bool
    {
        return in_array($this->status, ['done', 'failed'], true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'argv' => $this->argv,
            'cwd' => $this->cwd,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'timeout' => $this->timeout,
        ];
    }
}
