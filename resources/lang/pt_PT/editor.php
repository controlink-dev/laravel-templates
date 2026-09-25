<?php

return [

    // Used only by the test fixture (tests/Fixtures/types/invoice.php); the testbench
    // has no lang namespace of its own to load these from.
    'test_types' => [
        'invoice' => 'Fatura (teste)',
    ],

    'test_nodes' => [
        'documento' => 'Documento',
        'totais' => 'Totais',
        'emitente' => 'Emitente',
        'adquirente' => 'Adquirente',
        'adquirente_morada' => 'Morada do adquirente',
        'linha' => 'Linha',
    ],

    // Used by Data\FixtureValue to build the sample dataset for a calculated field.
    'fixtures' => [
        'text_sample' => 'Exemplo de texto para medir a largura desta coluna',
        'yes' => 'Sim',
    ],

];
