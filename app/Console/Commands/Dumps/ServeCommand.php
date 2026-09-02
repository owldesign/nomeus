<?php

namespace App\Console\Commands\Dumps;

use App\Services\Dumps\DumpIngest;
use App\Services\Dumps\DumpStore;
use Illuminate\Console\Command;
use Symfony\Component\VarDumper\Cloner\Data;
use Symfony\Component\VarDumper\Server\DumpServer;

/** The dump server. Runs under launchd as the `dumps` service instance; blocks forever. */
class ServeCommand extends Command
{
    protected $signature = 'dumps:serve {--port=9912} {--host=127.0.0.1}';

    protected $description = 'Receive dumps and recorded events (VarDumper server protocol) and store them for the Debug page';

    public function handle(DumpStore $store, DumpIngest $ingest): int
    {
        $addr = "tcp://{$this->option('host')}:{$this->option('port')}";
        $server = new DumpServer($addr);
        $server->start();
        $this->line("listening on {$addr} · store {$store->path()}");

        $n = 0;
        $server->listen(function (Data $data, array $context, int $clientId) use ($store, $ingest, &$n) {
            $row = $ingest->toRow($data, $context);
            $id = $store->insert($row);
            $this->line(sprintf('#%d %-7s %s', $id, $row['kind'], mb_strimwidth(str_replace("\n", ' ', $row['text']), 0, 120, '…')));
            if (++$n % 100 === 0) {
                $store->prune();
            }
        });

        return self::SUCCESS;
    }
}
