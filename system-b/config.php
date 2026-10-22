<?php
declare(strict_types=1);

return [
    'log_file' => __DIR__ . '/var/calls.log',

    // System B's own database. Skasuj ten plik, żeby wrócić do stanu początkowego.
    'db_file' => __DIR__ . '/var/ats-db.json',

    // System B refuses every n-th call with 429.
    'rate_limit_every' => 4,

    // Seconds the API asks us to wait. Set to null to simulate a 429 without Retry-After.
    'retry_after' => 1,

    // The server SIGKILLs the process on this call, mid-run, without warning. null disables it.
    'die_after_calls' => null,

    // Where in the call the shot lands:
    //   'before' - the request never reaches B, its database is untouched, as if the connection
    //              died in flight
    //   'after'  - B stored the change and flushed it to disk, but your process dies before it
    //              finds out that the call succeeded
    'die_at' => 'before',
];
