<?php

/*
 * nomeus is same-origin only. No path is exposed to cross-origin requests, so a page on
 * any other origin cannot read the API, and any request carrying a custom header is
 * preflighted and refused. Mutation endpoints (1b+) additionally require that header.
 */
return [
    'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => [],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
