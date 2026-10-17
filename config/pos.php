<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Business timezone
    |--------------------------------------------------------------------------
    |
    | The business day (daily order number, "Name (2)" suffix, "sales today")
    | resets at local midnight of this timezone. config/app.php stays on UTC
    | because every datetime column is stored and read as UTC; only the day
    | boundary moves. An unknown identifier falls back to Asia/Jakarta.
    |
    */
    'business_timezone' => env('POS_BUSINESS_TIMEZONE', 'Asia/Jakarta'),

    // An open bill is marked "from yesterday" only if business_date < today AND it is older than this.
    'stale_after_hours' => 6,

    // The "not sent to the kitchen" chip turns red after this many minutes.
    'unsent_alert_minutes' => 3,

    // The creator of a bill may cancel an already sent item until this many minutes after the last send.
    'cancel_sent_grace_minutes' => 10,

    // How long the active bill is restored after a page reload.
    'active_context_ttl_minutes' => 30,
];
