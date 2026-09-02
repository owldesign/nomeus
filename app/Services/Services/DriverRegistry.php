<?php

namespace App\Services\Services;

use RuntimeException;

final class DriverRegistry
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    public function __construct()
    {
        foreach ([new PostgresDriver, new MySqlDriver, new RedisDriver] as $driver) {
            $this->drivers[$driver->type()] = $driver;
        }
    }

    /** @return array<string, Driver> keyed by type */
    public function all(): array
    {
        return $this->drivers;
    }

    public function get(string $type): Driver
    {
        return $this->drivers[$type] ?? throw new RuntimeException("Unknown service type [{$type}]. Known: ".implode(', ', array_keys($this->drivers)));
    }

    public function has(string $type): bool
    {
        return isset($this->drivers[$type]);
    }
}
