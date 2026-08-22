<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Hash Driver
    |--------------------------------------------------------------------------
    */

    'driver' => 'bcrypt',

    /*
    |--------------------------------------------------------------------------
    | Bcrypt Options
    |--------------------------------------------------------------------------
    |
    | S6-02 — offline auth relies on the local users table, so the cost
    | factor is what actually protects a stolen/lost laptop's password
    | hashes, not a server round-trip. Pinned here rather than left to
    | BcryptHasher's own class default (also 12 as of this Laravel version,
    | but that's an implementation detail liable to change under us —
    | build-plan_1.md §6's "cost >= 12" is a requirement this config
    | should say explicitly, not one that happens to hold today.
    |
    */

    'bcrypt' => [
        'rounds' => env('BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

];
