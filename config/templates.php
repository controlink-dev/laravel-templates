<?php

return [

    'editor' => [
        /*
         * Optional header the embedded editor sends with the current tenant id on every
         * request to the host's own endpoints (the id comes from the host's
         * TenantResolver). null sends no header. The package owns no database and no
         * tenancy mode of its own — the host resolves and scopes the tenant; this is
         * only how the editor tells the host which tenant a request is for.
         */
        'tenant_header' => null,
    ],

    // Used by the default IntlMoneyFormatter only.
    'money' => [
        'currency' => env('TEMPLATES_CURRENCY', 'EUR'),
    ],

    /*
     * The separators every number and amount of a document prints with (CGE-75), the
     * same whatever language the user has. Null: ICU's for the app locale.
     */
    'numbers' => [
        'locale'    => null,
        'thousands' => null,
        'decimal'   => null,
    ],

    /*
     * Fonts every PDF layout may use. The canvas loads them by URL and the composer
     * embeds the same files as data URIs. `path` is relative to the host's public
     * directory, or absolute. The default ships with the package (OFL licence).
     */
    'fonts' => [
        'LiberationSans' => [
            'weights' => [
                400 => ['path' => __DIR__.'/../resources/fonts/LiberationSans-Regular.ttf', 'format' => 'truetype', 'mime' => 'font/ttf'],
                700 => ['path' => __DIR__.'/../resources/fonts/LiberationSans-Bold.ttf',    'format' => 'truetype', 'mime' => 'font/ttf'],
            ],
        ],
    ],

    // Closed list of formats a data field may use; the Twig sandbox allows exactly these.
    'formats' => ['date', 'number', 'money', 'upper'],

    'code_types' => [
        'text'  => ['fields' => ['text'],  'payload' => '{text}', 'required' => ['text']],
        'url'   => ['fields' => ['url'],   'payload' => '{url}',  'required' => ['url']],
        'email' => [
            'fields'   => ['email', 'subject'],
            'payload'  => 'mailto:{email}',
            'query'    => ['subject' => 'subject'],
            'required' => ['email'],
        ],
        'phone' => ['fields' => ['number'],            'payload' => 'tel:{number}',              'required' => ['number']],
        'sms'   => ['fields' => ['number', 'message'], 'payload' => 'smsto:{number}:{message}',  'required' => ['number']],
        'wifi'  => [
            'fields'   => ['security', 'network', 'password'],
            'payload'  => 'WIFI:T:{security};S:{network};P:{password};;',
            'escape'   => ['\\', ';', ':', ','],
            'required' => ['security', 'network'],
        ],
    ],

    'image_max_bytes' => 512 * 1024,

    // Whole CSS pixels (0.264583mm) — the engine snaps fills to them.
    'rule_widths' => ['thin' => 0.26, 'medium' => 0.53, 'thick' => 0.79],

    'geometry_tolerance_mm'     => 0.5,
    'render_time_limit_seconds' => 5,

    'pdf' => [
        'engine'    => \Controlink\Templates\Output\Pdf\GotenbergEngine::class,
        'gotenberg' => [
            'url'     => env('TEMPLATES_GOTENBERG_URL'),
            'timeout' => (int) env('TEMPLATES_GOTENBERG_TIMEOUT', 30),
        ],
    ],

    /*
     * Template types, declared by the host. See README, "Declaring a type", and
     * tests/Fixtures/types/invoice.php for a complete example.
     */
    'types' => [],
];
