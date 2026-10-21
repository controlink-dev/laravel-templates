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
        'data_field'          => 'Campo de dados',
        'image'               => 'Imagem',
        'rule'                => 'Linha / Retângulo',
        'line_table'          => 'Tabela de linhas',
        'code_slot'           => 'Código QR / barras',
        'page_number'         => 'Número de página',
    ],

    'traits' => [
        'content'       => 'Conteúdo',
        'format'        => 'Formato',
        'file'          => 'Ficheiro',
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

        'code_incomplete' => 'Este código fixo tem campos obrigatórios em branco. Sem eles, o código é válido mas não aponta para nada.',
    ],

    'page_formats' => [
        'x'      => 'Número da página',
        'x_of_y' => 'Número e total (1 de 3)',
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
        'confirm_occlusion'    => 'Alguns campos podem não aparecer impressos porque têm outro elemento por cima. Gravar à mesma?',
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
        'collection_hint' => 'Adicione um filtro para pré-visualizar linhas reais.',
        'rows_shown' => 'A mostrar :count de :total',
        'lines_badge' => ':count linhas',
        'operator' => 'Operador',
        'showing' => 'A mostrar :shown de :total',
        'error' => 'Não foi possível carregar os registos.',
    ],

];
