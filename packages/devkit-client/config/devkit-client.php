<?php

return [
    // Mailpit tag for this app's mail. Defaults to a slug of APP_NAME.
    'mail_tag' => env('DEVKIT_MAIL_TAG'),

    // Record queries, jobs, views, outgoing requests and logs for the Debug page when capture is on.
    'dumps' => env('DEVKIT_DUMPS', true),
];
