<?php

namespace App\Console\Commands;

use App\Support\DevkitConfig;
use App\Support\Editor;
use Illuminate\Console\Command;

class ConfigSetCommand extends Command
{
    protected $signature = 'config:set {key : Dot key, e.g. ide} {value : JSON is decoded (true, 8025, {...}); anything else is a string}';

    protected $description = 'Write a key in ~/.devkit/config.json';

    public function handle(DevkitConfig $config): int
    {
        $key = (string) $this->argument('key');
        $raw = (string) $this->argument('value');

        $decoded = json_decode($raw, true);
        $value = json_last_error() === JSON_ERROR_NONE && ! is_string($decoded) ? $decoded : $raw;

        if ($key === 'ide' && (! is_string($value) || ! array_key_exists($value, Editor::APPS))) {
            $this->warn("Unknown ide [".json_encode($value).']; known: '.implode(', ', array_keys(Editor::APPS)).'. Saved anyway; the default `open -t` applies.');
        }
        if ($key === 'db_client' && (! is_string($value) || ! array_key_exists($value, DbCommand::CLIENTS))) {
            $this->warn("Unknown db_client [".json_encode($value).']; known: '.implode(', ', array_keys(DbCommand::CLIENTS)).". Saved anyway; `open -a ".json_encode($value).'` will be tried.');
        }

        $config->set($key, $value);
        $this->line("<fg=gray>{$key} = ".(is_scalar($value) ? (string) $value : json_encode($value)).'</>');

        return self::SUCCESS;
    }
}
