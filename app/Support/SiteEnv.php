<?php

namespace App\Support;

use Dotenv\Dotenv;

/** A site's .env as an array, parsed with the same library Laravel uses. */
final class SiteEnv
{
    /** @return array<string, string>|null null when the site has no .env */
    public static function read(string $siteDir): ?array
    {
        $file = rtrim($siteDir, '/').'/.env';
        if (! is_file($file)) {
            return null;
        }

        return Dotenv::parse((string) file_get_contents($file));
    }
}
