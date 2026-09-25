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

    'zones' => [
        'header' => 'Cabeçalho',
        'body'   => 'Corpo',
        'footer' => 'Rodapé',
    ],

    'blocks' => [
        'line_table'  => 'Tabela de linhas',
        'page_number' => 'Número de página',
    ],

    // Camada 1 da validação: estática, sobre `project_data` (Output\Pdf\LayoutValidator).
    'validation' => [
        'unknown_binding'   => 'O campo «:binding» não existe no catálogo deste tipo de documento.',
        'unknown_format'    => 'O formato «:format» não é permitido.',
        'missing_mandatory' => 'O campo obrigatório «:field» não está no documento.',
        'missing_legal'     => 'O campo obrigatório por lei «:field» não está no documento.',
        'image_source'      => 'A imagem não tem ficheiro.',
        'image_too_large'   => 'A imagem excede o tamanho máximo permitido.',
        'outside_zone'      => 'O bloco sai fora da zona :zone.',
        'unsafe_markup'     => 'O layout recebido contém conteúdo que um documento não pode conter.',
        'duplicate_table'   => 'Existe mais do que uma tabela ligada a «:field». As linhas sairiam repetidas.',
        'wrong_zone'        => 'O bloco «:block» só pode estar em: :zones.',
        'code_size_unknown' => 'O código «:field» não tem tamanho definido. O mínimo legal é sobre o tamanho impresso, logo o layout tem de o indicar.',
        'code_too_small'    => 'O código «:field» tem menos de :minimum mm de lado.',
        'font_size'         => '«:field» está com uma fonte menor do que :minimum pt.',
        'display_none'      => '«:field» está oculto (ou está dentro de um bloco oculto).',
        'visibility_hidden' => '«:field» está invisível (ou está dentro de um bloco invisível).',
        'opacity'           => '«:field» está transparente.',
        'contrast'          => '«:field» tem um contraste de :contrast contra o fundo; o mínimo é :minimum.',
        'scope'             => '«:field» tem de aparecer em todas as páginas, logo só pode estar no cabeçalho ou no rodapé.',
        'outside_printable' => '«:field» está fora da área imprimível.',
        'occluded'          => '«:field» está coberto por um elemento opaco colocado por cima.',
    ],

    // Ativação (Versions\VersionActivator).
    'errors' => [
        'version_immutable' => 'Esta versão é imutável. Crie uma versão nova a partir dela para a alterar.',
    ],

    // Camada 2 da validação: sobre o PDF renderizado (Output\Pdf\RenderValidator).
    'render' => [
        'failed'              => 'A renderização falhou: :reason',
        'too_slow'            => 'A renderização levou :seconds s; o limite é :limit s.',
        'too_many_pages'      => 'O documento saiu com :pages páginas; o limite deste tipo de documento é :limit.',
        'value_missing'       => 'O valor de «:field» não aparece no PDF gerado.',
        'missing_on_page'     => 'Página :page: «:field» tem de aparecer em todas as páginas e falta nesta.',
        'outside_printable'   => 'Página :page: «:field» saiu fora da área imprimível.',
        'code_unreadable'     => 'O código «:field» não foi possível ler no PDF gerado.',
        'code_mismatch'       => 'Página :page: o código «:field» lê um conteúdo diferente do esperado.',
        'code_reader_missing' => 'Não é possível verificar o código «:field»: falta a biblioteca de leitura de QR nesta instalação.',
    ],

    // Os tipos de conteúdo que um slot de código de texto fixo pode carregar (Codes\CodePayload).
    'code_types' => [
        'text'  => 'Texto',
        'url'   => 'Endereço web',
        'email' => 'Email',
        'phone' => 'Telefone',
        'sms'   => 'SMS',
        'wifi'  => 'Wi-Fi',
    ],

    'code_sources' => [
        'dynamic' => 'Dinâmica',
        'fixed'   => 'Texto fixo',
    ],

];
