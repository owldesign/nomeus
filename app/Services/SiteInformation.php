<?php

namespace App\Services;

use App\Support\Shell;
use App\Support\Site;
use Illuminate\Support\Facades\Cache;

/** `php artisan about --json` for a Laravel site, cached briefly. null for non-Laravel sites or failures. */
final class SiteInformation
{
    public function __construct(private readonly Shell $shell) {}

    /** Why the last about() returned null, if it did (not cached — describes this process's attempt). */
    public ?string $lastError = null;

    public function about(Site $site): ?array
    {
        if (! $site->isLaravel()) {
            $this->lastError = 'not a Laravel app (no artisan)';

            return null;
        }
        $this->lastError = null;
        $cached = Cache::get("nomeus.about.{$site->name}");
        if (is_array($cached)) {
            return $cached;
        }

        // the site's own php when isolated, so `about` reports what the site actually runs on
        $php = 'php';
        if ($site->php !== null && is_executable($bin = $this->shell->brewPrefix()."/opt/php@{$site->php}/bin/php")) {
            $php = $bin;
        }
        $result = $this->shell->run([$php, 'artisan', 'about', '--json'], cwd: $site->path, timeout: 60);
        if (! $result->successful()) {
            $this->lastError = trim($result->errorOutput() ?: $result->output()) ?: "exit {$result->exitCode()}";

            return null;
        }
        $decoded = json_decode(trim($result->output()), true);
        if (! is_array($decoded)) {
            $this->lastError = 'artisan about did not return JSON: '.substr(trim($result->output()), 0, 200);

            return null;
        }
        Cache::put("nomeus.about.{$site->name}", $decoded, 30);

        return $decoded;
    }
}
