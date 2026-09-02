<?php

namespace App\Services\Services;

use RuntimeException;

final class DriverRegistry
{
    /** @var array<string, Driver> */
    private array $drivers = [];

    public function __construct()
    {
        foreach ([new PostgresDriver, new MySqlDriver, new MariaDbDriver, new RedisDriver] as $driver) {
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

    /** The driver whose formula list names this formula (short or tap-qualified), or null. */
    public function driverForFormula(string $formula): ?Driver
    {
        $short = basename($formula);
        foreach ($this->drivers as $driver) {
            foreach ($driver->formulae() as $f) {
                if ($f === $formula || basename($f) === $short) {
                    return $driver;
                }
            }
        }

        return null;
    }
}
