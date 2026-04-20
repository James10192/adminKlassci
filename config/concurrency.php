<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Concurrency Driver
    |--------------------------------------------------------------------------
    |
    | Laravel provides three concurrency drivers out of the box: `process`,
    | `fork`, and `sync`. The `fork` driver is Unix-only (uses pcntl_fork) and
    | faster but unavailable on Windows. `process` spawns PHP subprocesses
    | (works everywhere, ~50-100ms overhead per task). `sync` runs tasks
    | sequentially (useful for dev/debug).
    |
    */

    'default' => env('CONCURRENCY_DRIVER', 'process'),

];
