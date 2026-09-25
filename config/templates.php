<?php

return [

    /*
     * How the package finds the tenant's database. `none`: models use the default
     * connection. `column`: shared database, a `tenant_id` column filled from the
     * TenantResolver. `connection`: models use the named connection the host points at
     * the tenant before the package runs. See README, "Tenancy".
     */
    'tenancy' => [
        'mode'       => env('TEMPLATES_TENANCY_MODE', 'none'),
        'connection' => env('TEMPLATES_TENANCY_CONNECTION'),
    ],

    /*
     * The models the package instantiates. Extend them in the host to add auditing or
     * relations, and point these at the subclasses. Never referenced by class name
     * inside the package: always through this config.
     */
    'models' => [
        'template' => \Controlink\Templates\Models\Template::class,
        'version'  => \Controlink\Templates\Models\TemplateVersion::class,
    ],

    'http' => [
        // `false` registers no routes; the host registers its own.
        'routes'     => true,
        'prefix'     => 'templates',
        'middleware' => ['web', 'auth'],
        // Header the editor sends with the tenant id, or null for none.
        'tenant_header' => null,
    ],

    // Used by the default IntlMoneyFormatter only.
    'money' => [
        'currency' => env('TEMPLATES_CURRENCY', 'EUR'),
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

    /*
     * `Data\DocumentGraph`'s introspection of a node's model. `excluded_columns` is an
     * extra host-declared rule, on top of the package's own plumbing/blind-index/hidden
     * exclusions — a column a host never wants offered as a document field without
     * hiding it from the model everywhere else.
     */
    'graph' => [
        'excluded_columns' => [],
    ],
];
