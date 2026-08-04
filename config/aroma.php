<?php

return [

    /*
     * The panel is a fixed-date demo: the header clock, the "N minutes ago" labels
     * and the seeded timestamps are all anchored to this moment.
     */
    'now' => '2026-07-28 14:12:00',

    /*
     * Revision of the catalogue currently published to the devices.
     */
    'catalog_version' => 4193,

    /*
     * Rows per page in the product table.
     */
    'per_page' => 15,

    /*
     * The service account for the console at /su. Nothing reads these at runtime —
     * the account only appears when `php artisan aroma:superadmin` is run.
     */
    'superadmin' => [
        'login' => env('SUPERADMIN_LOGIN', 'superadmin'),
        'password' => env('SUPERADMIN_PASSWORD', 'secret'),
        'name' => env('SUPERADMIN_NAME', 'Суперадмин'),
    ],

    /*
     * The first administrator of a fresh install. Only `php artisan db:seed` reads
     * these — nothing looks them up at runtime.
     */
    'admin' => [
        'login' => env('ADMIN_LOGIN', 'admin'),
        'password' => env('ADMIN_PASSWORD', 'admin12345'),
        'name' => env('ADMIN_NAME', 'Администратор'),
    ],

];
