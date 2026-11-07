<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Households
    |--------------------------------------------------------------------------
    |
    | Lists can be shared with a household: all its members get access, also
    | the ones joining later. "local": households are managed in this app
    | (create, invite by email, roles). "konto": households are mirrored from
    | the AccountManager (webhooks plus a daily sync, matched by oidc_sub);
    | managing them here is read only, sharing lists with them stays possible.
    |
    */

    'driver' => env('HOUSEHOLDS_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | AccountManager ("Konto")
    |--------------------------------------------------------------------------
    |
    | Contract: AccountManager docs/HOUSEHOLDS-API.md. The secret of the
    | service signs the webhooks (POST /api/konto/webhook) and authenticates
    | the API calls (HTTP Basic with the service key). report_shares: tell the
    | AccountManager which lists are shared with a household (overview only).
    |
    */

    'konto' => [
        'url' => env('KONTO_URL'),
        'key' => env('KONTO_SERVICE_KEY'),
        'secret' => env('KONTO_SECRET'),
        'report_shares' => (bool) env('KONTO_REPORT_SHARES', true),
    ],

];
