<?php

namespace App\Services\Services;

/**
 * A driver that runs one of devkit's own artisan commands as a service (the dump server).
 * Like SiteBound, but the "site" is devkit itself: its php, its directory, no --site needed.
 */
interface DevkitBound {}
