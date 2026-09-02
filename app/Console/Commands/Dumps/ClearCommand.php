<?php

namespace App\Console\Commands\Dumps;

use App\Services\Dumps\DumpStore;
use Illuminate\Console\Command;

class ClearCommand extends Command
{
    protected $signature = 'dumps:clear';

    protected $description = 'Delete every stored dump and event';

    public function handle(DumpStore $store): int
    {
        $this->info($store->clear().' entries cleared');

        return self::SUCCESS;
    }
}
