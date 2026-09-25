<?php

return [

    // Used only by the test fixture (tests/Fixtures/types/invoice.php); the testbench
    // has no lang namespace of its own to load these from.
    'test_types' => [
        'invoice' => 'Invoice (test)',
    ],

    'test_nodes' => [
        'documento' => 'Document',
        'totais' => 'Totals',
        'emitente' => 'Issuer',
        'adquirente' => 'Customer',
        'adquirente_morada' => 'Customer address',
        'linha' => 'Line',
    ],

    // Used by Data\FixtureValue to build the sample dataset for a calculated field.
    'fixtures' => [
        'text_sample' => 'Sample text used to measure the width of this column',
        'yes' => 'Yes',
    ],

];
