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
        'page'   => 'Página',
    ],

    'blocks' => [
        'static_text'         => 'Texto',
        'static_text_default' => 'Texto',
        'static_text_empty'   => 'Texto (vazio)',
        'data_field'          => 'Campo de dados',
        'image'               => 'Imagem',
        'rule'                => 'Linha / Retângulo',
        'line_table'          => 'Tabela de linhas',
        'code_slot'           => 'Código QR / barras',
        'page_number'         => 'Número de página',
    ],

    'traits' => [
        'content'       => 'Conteúdo',
        'wrap'          => 'Várias linhas',
        'format'        => 'Formato',
        'file'          => 'Ficheiro',
        'choose_image'  => 'Escolher imagem…',
        'remove_image'  => 'Remover imagem',
        'anchor_last_page' => 'Fixa na última página',
        'fit'           => 'Ajuste',
        'thickness'     => 'Espessura',
        'filled'        => 'Preenchido',
        'collection'    => 'Coleção',
        'columns'       => 'Colunas',
        'column_label'  => 'Rótulo',
        'column_width'  => 'Largura',
        'column_remove' => 'Remover coluna',
        'column_align'  => 'Alinhamento',
        'column_reorder' => 'Arrastar para reordenar',
        'column_hide'   => 'Ocultar coluna',
        'column_show'   => 'Mostrar coluna',
        // "Acrescentar coluna" da tabela de linhas (CGE-85): campos próprios, das tabelas ligadas, coluna calculada.
        'add_column'          => '+ Acrescentar coluna',
        'column_search'       => 'Procurar campos…',
        'column_linked_field' => 'Campo da tabela ligada',
        'column_no_match'     => 'Nenhum campo corresponde à pesquisa.',
        'already_in_table'    => 'já está na tabela',
        'calculated_column'   => 'Coluna calculada',
        'node'          => 'Modelo',
        'field_source'  => 'Origem dos dados',
        'code_source'   => 'Origem',
        'code_type'     => 'Tipo',
        'page_format'   => 'Formato',
    ],

    'states' => [
        'draft'     => 'Rascunho',
        'validated' => 'Validado',
        'active'    => 'Ativo',
        'archived'  => 'Arquivado',
    ],

    'editor' => [
        'heading'         => 'Editor de modelo',
        'back'            => 'Voltar aos modelos',
        'save'            => 'Gravar',
        'preview'         => 'Pré-visualizar',
        'activate'        => 'Ativar',
        'activating'      => 'A validar e ativar…',
        'saving'          => 'A gravar…',
        'rendering'       => 'A gerar a pré-visualização…',
        'unsaved'         => 'Alterações não gravadas',
        'unsaved_warning' => 'Tem alterações não gravadas neste modelo. Se sair agora, perde-as.',
        'format_switch_confirm' => 'Trocar de formato substitui o layout atual. Continuar?',
        'version'         => 'Versão :number',
        'document_type'   => 'Tipo de documento',
        'open_full_page'  => 'Abrir em página inteira',
        'read_only'       => 'Esta versão já não pode ser alterada. Crie uma versão nova a partir dela.',
    ],

    /*
     * Casca do editor: barra de ferramentas, painéis e réguas — rótulos nossos, não da
     * biblioteca de canvas.
     */
    'chrome' => [
        'zoom'           => 'Zoom',
        'zoom_out'       => 'Reduzir',
        'zoom_in'        => 'Ampliar',
        'fit'            => 'Ajustar à janela',
        'page_format'    => 'Formato da página',
        'legal_fields'   => 'Campos legais',
        'legal_hint'     => 'Marca os campos obrigatórios por lei enquanto confere o layout.',
        'zones'          => 'Zonas',
        'zones_hint'     => 'Mostra onde acaba o cabeçalho e começa o corpo.',
        'rulers'         => 'Réguas',
        'rulers_hint'    => 'Escala em milímetros, e a extensão do bloco escolhido.',
        'clean_view'     => 'Vista limpa',
        'clean_hint'     => 'A folha exatamente como sai impressa.',
        'blocks'         => 'Blocos',
        'style'          => 'Estilo',
        'field'          => 'Campo',
        'layers'         => 'Camadas',
        'nothing_chosen' => 'Escolhe um bloco na folha.',
        'zone'           => 'Zona',
        'scope_any'      => 'visível em qualquer página',
        'scope_all'      => 'visível em todas as páginas',
        'type'           => 'tipo :type',
        'legal_chip'     => 'legal',
        'count_fields'   => ':count campo|:count campos',
        'count_tables'   => ':count tabela|:count tabelas',
        'count_codes'    => ':count código|:count códigos',
        'count_problems' => ':count problema|:count problemas',
    ],

    'actions' => [
        'restore_legal'      => 'Repor campos legais em falta',
        'restore_legal_none' => 'Não falta nenhum campo legal.',
        'restore_legal_done' => ':count campo(s) legal(is) reposto(s).',
        'snap_to_sheet'      => 'Repor na folha',
        'snap_to_sheet_done' => ':count campo(s) reposto(s) na folha.',
        'snap_to_sheet_none' => 'Nada a repor: não há campos fora da folha.',
    ],

    'formats' => [
        'none'   => 'Sem formato',
        'date'   => 'Data',
        'number' => 'Número',
        'money'  => 'Moeda',
        'upper'  => 'Maiúsculas',
    ],

    'format_args' => [
        'pattern'         => 'Padrão da data',
        'decimals'        => 'Casas decimais',
        'symbol'          => 'Símbolo',
        'decimals_money'  => 'Da moeda',
        'decimals_number' => 'Automático',
    ],

    'geometry' => [
        'page'             => 'Página',
        'margin'           => [
            'top'    => 'Margem de cima',
            'right'  => 'Margem direita',
            'bottom' => 'Margem de baixo',
            'left'   => 'Margem esquerda',
        ],
        'header_height'    => 'Altura do cabeçalho',
        'footer_height'    => 'Altura do rodapé',
        'body_too_small'   => 'Assim o corpo fica com menos de 20 mm. Reduza as margens ou as alturas.',
        'margins_too_wide' => 'As margens esquerda e direita não deixam largura na folha.',
    ],

    'format_symbols' => [
        'before' => 'Símbolo antes (€ 1,00)',
        'after'  => 'Símbolo depois (1,00 €)',
        'code'   => 'Código no fim (1,00 EUR)',
        'none'   => 'Sem símbolo (1,00)',
    ],

    'fits' => [
        'contain' => 'Caber sem cortar',
        'cover'   => 'Preencher e cortar',
        'fill'    => 'Esticar',
    ],

    'rule_widths' => [
        'thin'   => 'Fina',
        'medium' => 'Média',
        'thick'  => 'Grossa',
    ],

    'sectors' => [
        'typography' => 'Tipografia',
        'box'        => 'Caixa',
        'border'     => 'Contorno',
        'position'   => 'Posição',
    ],

    /*
     * Rótulos das propriedades do painel de estilo. A biblioteca de canvas traz os
     * seus ("Font size", "Padding", "Border width") e não os traduz.
     */
    'properties' => [
        'font_size'        => 'Tamanho',
        'font_weight'      => 'Peso',
        'font_style'       => 'Estilo',
        'text_align'       => 'Alinhamento',
        'line_height'      => 'Entrelinha',
        'letter_spacing'   => 'Espaçamento',
        'color'            => 'Cor',
        'width'            => 'Largura',
        'height'           => 'Altura',
        'padding'          => 'Espaço interior',
        'background_color' => 'Cor de fundo',
        'border_width'     => 'Espessura do contorno',
        'border_style'     => 'Tipo de contorno',
        'border_color'     => 'Cor do contorno',
        'top'              => 'Topo',
        'left'             => 'Esquerda',
        'right'            => 'Direita',
        'bottom'           => 'Fundo',
    ],

    /*
     * Opções das propriedades de estilo. A biblioteca de canvas oferece 100 a 900 para
     * o peso e os nomes CSS para o resto; no editor o que o utilizador escolhe é
     * "Negrito".
     */
    'style_options' => [
        'weight_normal' => 'Normal',
        'weight_bold'   => 'Negrito',
        'style_normal'  => 'Normal',
        'style_italic'  => 'Itálico',
        'align_left'    => 'Esquerda',
        'align_center'  => 'Centro',
        'align_right'   => 'Direita',
        'align_justify' => 'Justificado',
        'border_none'   => 'Sem contorno',
        'border_solid'  => 'Contínuo',
        'border_dashed' => 'Tracejado',
        'border_dotted' => 'Pontilhado',
    ],

    'problems' => [
        'heading'   => ':count problema(s) a resolver antes de este modelo poder ser ativado',
        'structure' => 'Estrutura',
        'legal'     => 'Campos legais',
        'render'    => 'Renderização',
    ],

    'checks' => [
        'missing'   => 'O campo obrigatório por lei «:field» não está no modelo.',
        'font_size' => '«:field» está com uma fonte menor do que :minimum pt.',
        'opacity'   => '«:field» está transparente.',
        'hidden'    => '«:field» está escondido.',
        'scope'     => '«:field» tem de aparecer em todas as páginas, logo só pode estar no cabeçalho ou no rodapé.',
        'off_sheet' => 'O campo «:field» está fora da folha.',
        'text_overflow' => 'O texto de «:field» não cabe na caixa e vai ser cortado.',
        'missing_table' => 'Falta a tabela «:field». É obrigatória para ativar.',
        'missing_mandatory' => 'Falta o campo «:field». É obrigatório para ativar, num bloco próprio ou dentro de uma fórmula.',

        'code_incomplete' => 'Este código fixo tem campos obrigatórios em branco. Sem eles, o código é válido mas não aponta para nada.',
    ],

    'page_formats' => [
        'x'      => 'Número da página',
        'x_of_y' => 'Número e total (1 de 3)',
        'x_slash_y' => 'Número e total (1 / 3)',
        'of'     => 'de',
    ],

    'aligns' => [
        'left'   => 'Esquerda',
        'center' => 'Centro',
        'right'  => 'Direita',
    ],

    'notices' => [
        'legal_locked' => '«:field» não pode ser eliminado. É um campo obrigatório por lei neste tipo de documento.',
        'close'        => 'Fechar aviso',
        'confirm_delete_legal' => '«:field» é um campo obrigatório por lei. Eliminar à mesma? Terá de o voltar a colocar antes de o poder pôr em produção.',
        'confirm_occlusion'    => 'Há elementos sobrepostos ou tapados que podem não sair bem impressos. Gravar à mesma?',
    ],

    // Botões dos diálogos de confirmação/aviso do próprio editor (dialog.js), em vez
    // dos diálogos nativos do browser.
    'dialog' => [
        'confirm' => 'Continuar',
        'cancel'  => 'Cancelar',
        'close'   => 'OK',
    ],

    // Camada 1 da validação: estática, sobre `project_data` (Output\Pdf\LayoutValidator).
    'validation' => [
        'unknown_binding'   => 'O campo «:binding» não existe no catálogo deste tipo de documento.',
        'unknown_format'    => 'O formato «:format» não é permitido.',
        'format_mismatch'   => 'O formato impresso do campo não corresponde ao formato escolhido. Volte a escolher o formato e grave.',
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
        'occluded_warning'  => '«:field» está tapado por outro elemento colocado por cima e pode não aparecer impresso.',
        'overlapping'       => '«:field» está sobreposto a «:other» e podem sair ilegíveis.',
        // V-19 (CGE-37): `:formula` é o cabeçalho da coluna, ou o rótulo genérico de fórmula num campo.
        'formula_syntax'            => '«:formula»: a fórmula não está bem escrita. Verifique os operadores, os parênteses, as vírgulas entre argumentos, as aspas e o número de argumentos de cada função.',
        'formula_unknown_function'  => '«:formula»: a fórmula usa uma função que não existe.',
        'formula_unknown_reference' => '«:formula»: a fórmula usa um campo que não existe neste tipo de documento (ou o resultado de outra fórmula, o que não é permitido).',
        'formula_scope'             => '«:formula»: aqui, uma coluna das linhas só pode ser usada dentro de SUM ou COUNT.',
        'formula_duplicate_key'     => '«:formula»: duas fórmulas têm a mesma chave. Apague esta e crie-a de novo.',
        'formula_without_source'    => '«:formula»: a fórmula está vazia.',
    ],

    // Campos e colunas de fórmula (CGE-37).
    'formula' => [
        'label'        => 'Fórmula',
        'toggle'       => 'Calcular com uma fórmula',
        'legal_locked' => 'É o único bloco com este campo legal: não pode passar a fórmula. Junte outra cópia do campo se precisar de uma fórmula aqui.',
        'source'       => 'Fórmula',
        'placeholder'  => 'ex.: totals.total_due * 2',
        'calculating'  => 'A calcular…',
        'result'       => 'Resultado: :value',
        'blank'        => 'Sem valor: imprime vazio.',

        // "Valor: Campo | Fórmula" do campo de dados e a caixa da fórmula (spec §6.1, §6.4).
        // `:name` e `:field` são preenchidos no JS.
        'value'         => 'Valor',
        'value_field'   => 'Campo',
        'value_formula' => 'Fórmula',
        'done'          => 'Concluir',
        'unknown_field' => 'Campo desconhecido: :name',
        'quick' => [
            'heading'     => 'Exemplos rápidos',
            'text'        => 'Texto com campo no meio',
            'text_source' => '"Texto antes " & :field & " texto depois"',
            'upper'       => 'Maiúsculas',
            'total'       => 'Total da tabela',
            // Os da caixa de uma coluna, feitos a partir da própria coluna.
            'left'        => 'Primeiros 40 caracteres',
            'round'       => 'Arredondar',
            'share'       => '% do total',
            'zero_blank'  => 'Vazio quando é zero',
        ],

        // O grupo das funções nas sugestões sob a caixa da fórmula.
        'functions_group'   => 'Funções',

        // Uma por função que o parser aceita (Formulas\Parser::FUNCTIONS); o bundle lê a
        // lista de funções destas chaves, por isso uma função nova lá precisa das duas aqui.
        'signatures' => [
            'IF'     => 'IF(condição, se_verdadeiro, se_falso)',
            'AND'    => 'AND(condição, …)',
            'OR'     => 'OR(condição, …)',
            'NOT'    => 'NOT(condição)',
            'SUM'    => 'SUM(coleção.coluna)',
            'COUNT'  => 'COUNT(coleção.coluna)',
            'ROUND'  => 'ROUND(número[, decimais])',
            'ABS'    => 'ABS(número)',
            'MIN'    => 'MIN(número, …)',
            'MAX'    => 'MAX(número, …)',
            'UPPER'  => 'UPPER(texto)',
            'LOWER'  => 'LOWER(texto)',
            'CONCAT' => 'CONCAT(texto, …)',
            'LEFT'   => 'LEFT(texto[, quantos])',
            'RIGHT'  => 'RIGHT(texto[, quantos])',
            'LEN'    => 'LEN(texto)',
            'TRIM'   => 'TRIM(texto)',
            'YEAR'   => 'YEAR(data)',
            'MONTH'  => 'MONTH(data)',
            'DAY'    => 'DAY(data)',
            'DAYS'   => 'DAYS(fim, início)',
            'TEXT'   => 'TEXT(valor, "date" | "money" | "number"[, opção])',
        ],
        'descriptions' => [
            'IF'     => 'O segundo valor se a condição se verificar, senão o terceiro.',
            'AND'    => 'Verdadeiro se todas as condições se verificarem.',
            'OR'     => 'Verdadeiro se pelo menos uma condição se verificar.',
            'NOT'    => 'O contrário da condição.',
            'SUM'    => 'Soma a coluna em todas as linhas do documento.',
            'COUNT'  => 'Quantas linhas tem o documento.',
            'ROUND'  => 'O número arredondado a essas casas decimais (nenhuma se omitido).',
            'ABS'    => 'O número sem sinal.',
            'MIN'    => 'O menor dos valores.',
            'MAX'    => 'O maior dos valores.',
            'UPPER'  => 'O texto em maiúsculas.',
            'LOWER'  => 'O texto em minúsculas.',
            'CONCAT' => 'Os textos juntos (o mesmo que &).',
            'LEFT'   => 'Os primeiros caracteres do texto (um se a quantidade for omitida).',
            'RIGHT'  => 'Os últimos caracteres do texto (um se a quantidade for omitida).',
            'LEN'    => 'Quantos caracteres tem o texto.',
            'TRIM'   => 'O texto sem espaços no início e no fim.',
            'YEAR'   => 'O ano da data.',
            'MONTH'  => 'O mês da data (1 a 12).',
            'DAY'    => 'O dia do mês da data.',
            'DAYS'   => 'Os dias entre a data de início e a data de fim.',
            'TEXT'   => 'O valor escrito como o documento o imprime: data, montante ou número. O terceiro argumento, opcional, é uma opção desse formato. Numa data é o padrão: "dd/MM/yyyy", "yyyy-MM-dd", "yyyy/MM/dd", "dd-MM-yyyy" ou "dd MMM yyyy".',
        ],

        // A coluna Blocos enquanto se edita uma fórmula: "Campos e funções" (spec §6.3).
        // `:example` é preenchido no JS.
        'library' => [
            'heading'          => 'Campos e funções',
            'fields'           => 'Campos',
            'functions'        => 'Funções',
            'search_fields'    => 'Procurar campo…',
            'search_functions' => 'Procurar função…',
            'empty'            => 'Nada encontrado.',
            'example'          => 'ex.: :example',
            'this_row'         => 'Esta linha',
        ],

        // Os grupos de funções da lista (e "Totais da tabela" também nos campos).
        'groups' => [
            'math'       => 'Contas',
            'totals'     => 'Totais da tabela',
            'conditions' => 'Condições',
            'text'       => 'Texto',
            'dates'      => 'Datas',
            'other'      => 'Outras',
        ],

        // Um exemplo por função, escrito com chaves técnicas do tipo Documento fiscal; o
        // editor mostra-o com os nomes legíveis do tipo aberto (os campos que esse tipo
        // não tem ficam como estão). Texto entre aspas não é traduzido pelo editor. Cada
        // exemplo vale num campo de dados: colunas da tabela só dentro de SUM/COUNT.
        'examples' => [
            'IF'     => 'IF(totals.tax_total > 0, totals.total_due, totals.net_total)',
            'AND'    => 'IF(AND(totals.tax_total > 0, totals.net_total > 0), "Sim", "Não")',
            'OR'     => 'IF(OR(totals.tax_total = 0, totals.net_total = 0), "Isento", "")',
            'NOT'    => 'IF(NOT(totals.tax_total > 0), "Sem IVA", "")',
            'SUM'    => 'SUM(lines.amount)',
            'COUNT'  => 'COUNT(lines.code)',
            'ROUND'  => 'ROUND(totals.net_total * 0.23, 2)',
            'ABS'    => 'ABS(totals.total_due)',
            'MIN'    => 'MIN(totals.total_due, 1000)',
            'MAX'    => 'MAX(totals.total_due, 0)',
            'UPPER'  => 'UPPER(customer.name)',
            'LOWER'  => 'LOWER(customer.name)',
            'CONCAT' => 'CONCAT(document.designation, " ", document.number)',
            'LEFT'   => 'LEFT(customer.name, 20)',
            'RIGHT'  => 'RIGHT(customer.tax_id, 4)',
            'LEN'    => 'LEN(customer.name)',
            'TRIM'   => 'TRIM(customer.address)',
            'YEAR'   => 'YEAR(document.issue_date)',
            'MONTH'  => 'MONTH(document.issue_date)',
            'DAY'    => 'DAY(document.issue_date)',
            'DAYS'   => 'DAYS(document.supply_date, document.issue_date)',
            'TEXT'   => '"Fornecido em " & TEXT(document.supply_date, "date", "yyyy/MM/dd")',
        ],

        // Colunas da tabela de linhas em Fórmula e colunas calculadas: calculadas para cada
        // linha. `:example` é o 1.º exemplo rápido da própria coluna, com nomes legíveis.
        'column_label'       => 'Fórmula',
        'add_column'         => '+ Coluna calculada',
        'remove_column'      => 'Remover coluna calculada',
        'column_calculated'  => 'Uma coluna calculada é sempre uma fórmula; para a tirar, apague-a.',
        'column_no_collection' => 'Uma tabela sem coleção não tem linhas para uma fórmula ler.',
        'column_placeholder' => 'ex.: :example',
        'column_help'        => 'O resultado mostrado é o da primeira linha.',
        'columns_overflow'   => 'As colunas ocupam mais do que a largura da tabela; reduza ou esconda uma coluna.',
    ],

    // Rotas (Http\Controllers\*), mensagens de sucesso.
    'messages' => [
        'created'       => 'Modelo criado.',
        'saved'         => 'Layout gravado.',
        'draft_created' => 'Versão de rascunho criada.',
        'activated'     => 'Versão ativada. A anterior foi arquivada.',
        'duplicated'    => 'Modelo duplicado.',
        'copy_name'          => ':name (cópia)',
        'copy_name_numbered' => ':name (cópia :number)',
    ],

    // Rotas (Http\Controllers\*) e ativação (Versions\VersionActivator).
    'errors' => [
        'version_immutable'  => 'Esta versão é imutável. Crie uma versão nova a partir dela para a alterar.',
        'validation_failed'  => 'O layout tem problemas que impedem a gravação. Corrija-os e grave outra vez.',
        'activation_failed'  => 'A validação por renderização falhou. A versão não foi ativada.',
        'nothing_to_preview' => 'Esta versão ainda não tem layout gravado para pré-visualizar.',
        'unsafe_template'    => 'O layout recebido contém conteúdo que um modelo não pode conter. Não foi gravado nem renderizado.',
        'render_failed'      => 'Não foi possível gerar o PDF. O motor de renderização não respondeu como esperado.',
        'preview_truncated'  => 'O PDF mostra :shown de :total linhas: este impresso tem no máximo :pages páginas. Use filtros na Amostra para escolher as linhas.',
        'type_not_creatable' => 'Este tipo de documento não pode ser criado de zero: obtém-se duplicando um modelo existente.',
        'image_too_large'    => 'A imagem é demasiado grande. Escolha um ficheiro menor.',
        'catalogue_failed'   => 'Não foi possível carregar o catálogo de campos deste tipo de documento. O editor não abre sem ele.',
        'load_failed'        => 'Não foi possível carregar o layout desta versão.',
        'save_failed'        => 'Não foi possível gravar o layout.',
    ],

    // Http\Requests\StoreTemplateRequest, Http\Requests\UpdateVersionRequest.
    'attributes' => [
        'name'         => 'nome',
        'type_code'    => 'tipo de documento',
        'project_data' => 'layout',
        'html'         => 'HTML do layout',
        'css'          => 'CSS do layout',
    ],

    // Camada 2 da validação: sobre o PDF renderizado (Output\Pdf\RenderValidator).
    'render' => [
        'failed'              => 'A renderização falhou: :reason',
        'too_slow'            => 'A renderização levou :seconds s; o limite é :limit s.',
        'too_many_pages'      => 'O documento saiu com :pages páginas; o limite deste tipo de documento é :limit.',
        'value_missing'       => 'O valor de «:field» não aparece no PDF gerado.',
        'missing_on_page'     => 'Página :page: «:field» tem de aparecer em todas as páginas e falta nesta.',
        'outside_printable'   => 'Página :page: «:field» saiu fora da área imprimível.',
        'page_size_mismatch'  => 'O PDF gerado tem :actual mas o modelo pede :expected.',
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

    'code_fields' => [
        'text'     => 'Texto',
        'url'      => 'URL',
        'email'    => 'Email',
        'subject'  => 'Assunto',
        'number'   => 'Número',
        'message'  => 'Mensagem',
        'security' => 'Segurança',
        'network'  => 'Nome da rede',
        'password' => 'Senha',
    ],

    // The sample-data panel: previewing the layout with real records (never saved).
    'preview' => [
        'tab' => 'Amostra',
        'intro' => 'Pré-visualize o layout com registos reais. Só altera o que vê aqui: nunca é guardado.',
        'none' => 'Não há nada para pré-visualizar neste tipo de documento.',
        'unbound' => 'Liga um campo ou uma tabela para pré-visualizar com dados reais',
        'example' => 'Exemplo',
        'add_filter' => 'Adicionar filtro',
        'field_placeholder' => 'Campo',
        'value_placeholder' => 'Valor',
        'op_contains' => 'contém',
        'op_eq' => 'igual a',
        'remove_filter' => 'Remover filtro',
        'loading' => 'A carregar…',
        'show_more' => 'Mostrar mais',
        'use_example' => 'Clicar para voltar ao exemplo',
        'no_results' => 'Nenhum registo corresponde. É mostrado o exemplo.',
        'collection_hint' => 'Escolha Todos os registos, ou adicione um filtro, para pré-visualizar linhas reais.',
        'rows_shown' => 'A mostrar :count de :total',
        'badge_all' => 'Todos (:total)',
        'badge_filtered' => 'Filtrado (:total)',
        'example_mode' => 'Exemplo',
        'all_records' => 'Todos os registos',
        'operator' => 'Operador',
        'showing' => 'A mostrar :shown de :total',
        'error' => 'Não foi possível carregar os registos.',
    ],

];
