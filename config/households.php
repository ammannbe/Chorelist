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
    | the AccountManager (not implemented yet); managing them here is read
    | only, sharing lists with them stays possible.
    |
    */

    'driver' => env('HOUSEHOLDS_DRIVER', 'local'),

];
