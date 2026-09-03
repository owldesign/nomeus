<?php

namespace App\Services;

use App\Support\Shell;
use App\Support\Site;
use Illuminate\Support\Facades\Cache;

/** `php artisan about --json` for a Laravel site, cached briefly. null for non-Laravel sites or failures. */
final class SiteInformation
{
    public function __construct(private readonly Shell $shell) {}

    public function about(Site $site): ?array
    {
        if (! $site->isLaravel()) {
            return null;
        }

        return Cache::remember("nomeus.about.{$site->name}", 30, function () use ($site): ?array {
            $result = $this->shell->run(['php', 'artisan', 'about', '--json'], cwd: $site->path, timeout: 60);
            if (! $result->successful()) {
                return null;
            }
            $decoded = json_decode(trim($result->output()), true);

            return is_array($decoded) ? $decoded : null;
        });
    }
}
