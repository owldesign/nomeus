<?php

namespace App\Console\Commands;

use App\Services\BrewBridge;
use App\Services\Init\InitRunner;
use App\Services\New\ManifestBuilder;
use App\Services\New\SiteScaffolder;
use App\Services\Services\DriverRegistry;
use App\Services\ValetBridge;
use App\Support\Editor;
use App\Support\Manifest;
use App\Support\Shell;
use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/** The site wizard: a directory, a nomeus.yml from your answers, then `init`. Nothing it does is new; it only asks. */
class NewCommand extends Command
{
    protected $signature = 'new
        {name? : site name → <name>.test}
        {--dir= : where to create it (default: your parked directory + name)}
        {--laravel : composer create-project laravel/laravel}
        {--from= : composer create-project <package[:constraint]> instead (e.g. laravel/laravel:^12, statamic/statamic)}
        {--empty : use an empty/existing directory, no scaffold}
        {--php= : isolate to this version (default: linked)}
        {--node= : pin a Node version (.nvmrc; fnm installs it during init), e.g. 22 or lts}
        {--db= : postgresql | mysql | mariadb | none}
        {--redis}
        {--service=* : extra types: meilisearch, typesense, seaweedfs}
        {--mail : mailpit + client package}
        {--secure : https}
        {--no-scripts : write nomeus.yml but skip post-init (migrate)}
        {--open : open the site in the browser when done}
        {--edit : open the directory in the IDE when done}
        {--dry-run : print the nomeus.yml and the init plan, create nothing}
        {--yes : never prompt; take flags and defaults}';

    protected $description = 'Create a site: scaffold, nomeus.yml, init — interactive when run without flags';

    public function handle(ValetBridge $valet, BrewBridge $brew, DriverRegistry $drivers, ManifestBuilder $builder, SiteScaffolder $scaffolder, InitRunner $init, Editor $editor, Shell $shell): int
    {
        $ask = ! $this->option('yes') && $this->input->isInteractive();
        if (! $valet->isInstalled()) {
            $this->error('valet is not installed.');

            return self::FAILURE;
        }
        $parked = $valet->paths();

        // ── name, directory ──────────────────────────────────────────────────
        $name = (string) ($this->argument('name') ?: ($ask ? text('Site name', placeholder: 'shop', hint: 'becomes <name>.'.$valet->tld()) : ''));
        if (! preg_match('/^[a-z0-9][a-z0-9.-]*$/', $name)) {
            $this->error("Site name must be lowercase letters, digits, dots and dashes, got [{$name}].");

            return self::FAILURE;
        }
        if ($valet->find($name) !== null && ! $this->option('empty')) {
            $this->error("{$name}.{$valet->tld()} already exists — use --empty to init it in place.");

            return self::FAILURE;
        }
        $dir = $this->option('dir') ? \App\Support\NomeusConfig::expand((string) $this->option('dir')) : (($parked[0] ?? null) ? "{$parked[0]}/{$name}" : null);
        if ($dir === null) {
            $this->error('No parked directory — pass --dir=<path> or `valet park` your sites folder first.');

            return self::FAILURE;
        }

        // ── starter ──────────────────────────────────────────────────────────
        $from = $this->option('from') ?: ($this->option('laravel') ? SiteScaffolder::DEFAULT : null);
        if ($from === null && ! $this->option('empty')) {
            $from = $ask
                ? match (select('Start from', ['laravel' => 'Laravel (composer create-project laravel/laravel)', 'custom' => 'Another package', 'empty' => 'Empty directory / already there'], 'laravel')) {
                    'laravel' => SiteScaffolder::DEFAULT,
                    'custom' => text('Composer package', placeholder: 'laravel/laravel:^12'),
                    default => null,
                }
                : SiteScaffolder::DEFAULT;
        }

        // ── php, services, mail, tls ─────────────────────────────────────────
        $installed = $brew->installedPhp();
        $php = $this->option('php') ?: ($ask && count($installed) > 1 ? select('PHP', array_combine($installed, $installed), $brew->linkedPhp() ?? $installed[0]) : null);
        if ($php !== null && ! in_array($php, $installed, true)) {
            $this->error("php {$php} is not installed (".implode(', ', $installed).').');

            return self::FAILURE;
        }
        $db = $this->option('db');
        if ($db === null && $ask) {
            $db = select('Database', ['postgresql' => 'PostgreSQL', 'mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'none' => 'none'], 'postgresql');
        }
        if ($db !== null && $db !== 'none' && ! in_array($db, ManifestBuilder::DATABASES, true)) {
            $this->error('--db must be postgresql, mysql, mariadb or none.');

            return self::FAILURE;
        }
        $extras = (array) $this->option('service');
        $redis = (bool) $this->option('redis');
        if ($ask && ! $this->option('redis') && $extras === []) {
            $picked = multiselect('Also', ['redis' => 'Redis', 'meilisearch' => 'Meilisearch', 'typesense' => 'Typesense', 'seaweedfs' => 'SeaweedFS (S3)'], ['redis']);
            $redis = in_array('redis', $picked, true);
            $extras = array_values(array_diff($picked, ['redis']));
        }
        foreach ($extras as $t) {
            if (! $drivers->has($t)) {
                $this->error("Unknown service type [{$t}].");

                return self::FAILURE;
            }
        }
        $mail = $this->option('mail') || ($ask && confirm('Mail (mailpit inbox for this app)?', true));
        $secure = $this->option('secure') || ($ask && confirm('https?', true));

        $services = array_values(array_filter(array_merge($db && $db !== 'none' ? [$db] : [], $redis ? ['redis'] : [], $extras)));
        $answers = [
            'name' => $name, 'domain' => $name, 'php' => $php, 'node' => $this->option('node') ?: null, 'secure' => $secure, 'services' => $services, 'mail' => $mail,
            'post_init' => $from !== null ? ['php artisan migrate --force'] : [],   // recorded even with --no-scripts: the next `init` runs it
        ];
        if ($redis) {
            $answers['env'] = ['CACHE_STORE' => 'redis', 'QUEUE_CONNECTION' => 'redis', 'SESSION_DRIVER' => 'redis'];
        }
        $yaml = $builder->yaml($answers);

        // ── dry run ──────────────────────────────────────────────────────────
        $this->line("<fg=gray>{$dir}/nomeus.yml</>");
        $this->line($yaml);
        if ($this->option('dry-run')) {
            $this->line('<fg=gray>'.($from ? "would run: composer create-project {$from}" : 'would use the directory as is').", then init:</>");
            $this->table(['step', 'action', ''], array_map(fn ($s) => [$s->id, $s->label, $s->skip ? "skip {$s->skip}" : 'run '.($s->detail ?? '')], $init->plan(Manifest::fromArray($builder->build($answers), $dir))));

            return self::SUCCESS;
        }
        if ($ask && ! confirm("Create {$name}.{$valet->tld()} in {$dir}?", true)) {
            return self::SUCCESS;
        }

        // ── do it ────────────────────────────────────────────────────────────
        $log = fn (string $l) => $this->line("<fg=gray>  {$l}</>");
        try {
            $this->line('<fg=yellow>▶ scaffold</>');
            $scaffolder->scaffold($dir, $from, $log);
            file_put_contents("{$dir}/nomeus.yml", $yaml);
            $log('nomeus.yml written');

            $this->line('<fg=yellow>▶ init</>');
            $result = $init->run(Manifest::load($dir), fn (string $id, string $line) => $this->line(
                str_starts_with($line, '▶') ? "<fg=yellow>{$line}</>" : "<fg=gray>{$line}</>"
            ), skipScripts: (bool) $this->option('no-scripts'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $url = ($secure ? 'https' : 'http')."://{$name}.{$valet->tld()}";
        $this->info("{$url} — ".count($result['ran']).' step(s) run, '.count($result['skipped']).' already in place');
        if ($this->option('open')) {
            $shell->run(['open', $url], timeout: 10);
        }
        if ($this->option('edit')) {
            $editor->openDir($dir);
        }

        return self::SUCCESS;
    }
}
