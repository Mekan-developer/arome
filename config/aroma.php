<?php

return [

    /*
     * The panel is a fixed-date demo: the header clock, the "N minutes ago" labels
     * and the seeded timestamps are all anchored to this moment.
     */
    'now' => '2026-07-28 14:12:00',

    /*
     * Rows per page in the audit journal.
     */
    'per_page' => 15,

    /*
     * Сколько строк каталог показывает, пока администратор не выбрал другое число в
     * подвале. Больше, чем в журнале: прайс листают пачками, а не читают подряд.
     */
    'catalog_per_page' => 50,

    /*
     * Размеры страницы, которые администратор может выбрать в подвале каталога.
     * Значение из `?per_page=` принимается, только если оно есть в этом списке —
     * иначе запрос на «все строки разом» становится способом положить панель.
     */
    'per_page_options' => [30, 50, 100],

    /*
     * История последних просканированных товаров в приложении продавца: сколько
     * строк уходит по умолчанию и потолок для параметра `?limit=`.
     */
    'scan_history' => [
        'limit' => 20,
        'max' => 50,
    ],

    /*
     * Учётка консоли /su. Читается только `php artisan aroma:superadmin`.
     * Пароль без дефолта: пустой .env не должен поднимать слабый вход.
     */
    'superadmin' => [
        'login' => env('SUPERADMIN_LOGIN'),
        'password' => env('SUPERADMIN_PASSWORD'),
        'name' => env('SUPERADMIN_NAME', 'Суперадмин'),
    ],

    /*
     * Первый администратор панели. Читается только `php artisan db:seed`.
     * Пароль без дефолта — иначе пустой .env создал бы admin/admin12345.
     */
    'admin' => [
        'login' => env('ADMIN_LOGIN'),
        'password' => env('ADMIN_PASSWORD'),
        'name' => env('ADMIN_NAME', 'Администратор'),
    ],

];
