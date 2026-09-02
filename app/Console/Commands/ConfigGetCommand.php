<?php

namespace App\Console\Commands;

use App\Support\DevkitConfig;
use Illuminate\Console\Command;

class ConfigGetCommand extends Command
{
    protected $signature = 'config:get {key? : Dot key, e.g. ide or mail.smtp_port; omit for everything}';

    protected $description = 'Read ~/.devkit/config.json';

    public function handle(DevkitConfig $config): int
    {
        if (! $config->exists()) {
            $this->error("No config at {$config->path()} — run install/install.sh");

            return self::FAILURE;
        }

        $value = $this->argument('key') ? $config->get($this->argument('key')) : $config->all();
        if ($value === null) {
            $this->error("Key [{$this->argument('key')}] is not set.");

            return self::FAILURE;
        }
        $this->line(is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
