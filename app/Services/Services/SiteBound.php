<?php

namespace App\Services\Services;

/**
 * A driver whose runtime is a site's own PHP and vendor dir rather than a brew formula.
 * create() requires --site, records the site path and php bin dir in options, and skips brew.
 */
interface SiteBound
{
    /** Path inside the site that must exist, e.g. vendor/laravel/reverb. */
    public function siteRequirement(): string;

    /** Composer package whose version is recorded as the instance version. */
    public function sitePackage(): string;
}
