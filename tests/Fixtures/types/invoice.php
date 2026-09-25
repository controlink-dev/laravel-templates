<?php

// Adapted from cgest's config/print_forms.php (document_types.invoice), renamed to the
// package's vocabulary (see the extraction plan's rename table). Node ids and binding
// keys are left as the host declared them: they are the host's own vocabulary, not the
// package's.

return [
    'invoice' => [
        'label' => 'templates::editor.test_types.invoice',
        'output' => 'pdf',
        'seeded' => true,
        'layout_revision' => 1,

        'bindings' => [
            // Issuer
            ['key' => 'emitente.nome', 'label' => 'Nome do emitente', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
            ['key' => 'emitente.nif', 'label' => 'NIF do emitente', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
            ['key' => 'emitente.morada', 'label' => 'Sede do emitente', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],

            // Customer
            ['key' => 'adquirente.nome', 'label' => 'Nome do adquirente', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
            ['key' => 'adquirente.nif', 'label' => 'NIF do adquirente', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],
            ['key' => 'adquirente.morada', 'label' => 'Morada do adquirente', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],

            // Document identity
            ['key' => 'documento.designacao', 'label' => 'Designação do documento', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],
            ['key' => 'documento.numero', 'label' => 'Número do documento', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],
            ['key' => 'documento.atcud', 'label' => 'ATCUD', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],
            ['key' => 'documento.certificado', 'label' => 'Menção de certificação', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'every_page'],

            ['key' => 'documento.data_emissao', 'label' => 'Data de emissão', 'type' => 'date', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page', 'default_format' => 'date'],
            ['key' => 'documento.data_bens', 'label' => 'Data dos bens/serviços', 'type' => 'date', 'mandatory' => true, 'legal' => false, 'default_format' => 'date'],
            ['key' => 'documento.hash_caracteres', 'label' => 'Caracteres do hash', 'type' => 'text', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],

            ['key' => 'documento.qr', 'label' => 'Código QR', 'type' => 'qr', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page'],

            // Totals
            ['key' => 'totais.total_iliquido', 'label' => 'Total ilíquido', 'type' => 'money', 'mandatory' => true, 'legal' => false, 'default_format' => 'money'],
            ['key' => 'totais.total_impostos', 'label' => 'Total de impostos', 'type' => 'money', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page', 'default_format' => 'money'],
            ['key' => 'totais.total_a_pagar', 'label' => 'Total a pagar', 'type' => 'money', 'mandatory' => true, 'legal' => true, 'scope' => 'any_page', 'default_format' => 'money'],
            ['key' => 'totais.motivo_isencao', 'label' => 'Motivo de isenção', 'type' => 'text', 'mandatory' => true, 'legal' => false],

            ['key' => 'linhas', 'label' => 'Linhas do documento', 'type' => 'collection', 'mandatory' => true, 'legal' => false, 'columns' => [
                ['key' => 'codigo', 'label' => 'Código'],
                ['key' => 'descricao', 'label' => 'Descrição'],
                ['key' => 'quantidade', 'label' => 'Quantidade', 'default_format' => 'number'],
                ['key' => 'unidade', 'label' => 'Unidade'],
                ['key' => 'preco_unitario', 'label' => 'Preço unitário', 'default_format' => 'money'],
                ['key' => 'taxa_iva', 'label' => 'Taxa de IVA'],
                ['key' => 'valor', 'label' => 'Valor', 'default_format' => 'money'],
            ]],
        ],

        'nodes' => [
            'documento' => ['model' => null, 'label' => 'templates::editor.test_nodes.documento'],
            'totais' => ['model' => null, 'label' => 'templates::editor.test_nodes.totais'],
            'emitente' => ['model' => null, 'label' => 'templates::editor.test_nodes.emitente'],
            'adquirente' => ['model' => null, 'label' => 'templates::editor.test_nodes.adquirente'],
            'adquirente_morada' => ['model' => null, 'label' => 'templates::editor.test_nodes.adquirente_morada'],
            'linha' => ['model' => null, 'collection' => true, 'label' => 'templates::editor.test_nodes.linha'],
        ],

        'fixture' => [
            'emitente.nome' => 'Sociedade Industrial de Transformação e Comércio de Materiais, S.A.',
            'emitente.nif' => '999999999',
            'emitente.morada' => 'Rua Doutor António Bernardino de Almeida, n.º 1234, 5.º Esquerdo, 4200-072 Porto',
            'adquirente.nome' => 'Cooperativa Agrícola dos Produtores de Frutas e Hortícolas do Ribatejo, CRL',
            'adquirente.nif' => '888888888',
            'adquirente.morada' => 'Avenida Marechal Gomes da Costa, Lote 27, Bloco B, 3.º Direito, 1800-255 Lisboa',
            'documento.designacao' => 'Fatura-recibo',
            'documento.numero' => 'FR 2026/0000000001',
            'documento.atcud' => 'JFT7-0000000001',
            'documento.certificado' => 'Processado por programa certificado n.º 9999/AT',
            'documento.data_emissao' => '2026-12-31',
            'documento.data_bens' => '2026-12-31',
            'documento.hash_caracteres' => 'ABCD',
            'documento.qr' => 'A:999999999*B:888888888*C:PT*D:FR*E:N*F:20261231*G:FR 2026/0000000001*H:JFT7-0000000001',
            'totais.total_iliquido' => 999999.99,
            'totais.total_impostos' => 229999.99,
            'totais.total_a_pagar' => 1229999.98,
            'totais.motivo_isencao' => 'M99 - Não sujeito; não tributado (ou similar), isenção ao abrigo do artigo 9.º do CIVA',

            'linhas' => [
                ['codigo' => 'ART-0000000001', 'descricao' => 'Chapa de aço laminado a frio, acabamento galvanizado, 2000 x 1000 x 1,5 mm', 'quantidade' => 1234.5678, 'unidade' => 'UN', 'preco_unitario' => 1234.56, 'taxa_iva' => '23%', 'valor' => 999999.99],
                ['codigo' => 'SRV-0000000002', 'descricao' => 'Serviço de montagem e assistência técnica no local, deslocação incluída', 'quantidade' => 8.0, 'unidade' => 'H', 'preco_unitario' => 45.00, 'taxa_iva' => '23%', 'valor' => 360.00],
                ['codigo' => 'ART-0000000003', 'descricao' => 'Parafuso sextavado inox A2, DIN 933, M8 x 40 mm (embalagem de 100 unidades)', 'quantidade' => 25.0, 'unidade' => 'CX', 'preco_unitario' => 12.3456, 'taxa_iva' => '6%', 'valor' => 308.64],
            ],
        ],

        'fixture_repeats' => 60,

        'initial_layout' => [
            'html' => implode('', [
                '<div class="page">',

                '<div class="zone zone--header" data-tpl-zone="header">',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.designacao" data-tpl-format="" data-tpl-legal="1" id="tpl-designacao">{{ documento.designacao }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.numero" data-tpl-format="" data-tpl-legal="1" id="tpl-numero">{{ documento.numero }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.atcud" data-tpl-format="" data-tpl-legal="1" id="tpl-atcud">{{ documento.atcud }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.data_emissao" data-tpl-format="date" data-tpl-legal="1" id="tpl-data-emissao">{{ documento.data_emissao|date }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.data_bens" data-tpl-format="date" data-tpl-legal="0" id="tpl-data-bens">{{ documento.data_bens|date }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="emitente.nome" data-tpl-format="" data-tpl-legal="1" id="tpl-emitente-nome">{{ emitente.nome }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="emitente.nif" data-tpl-format="" data-tpl-legal="1" id="tpl-emitente-nif">{{ emitente.nif }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="emitente.morada" data-tpl-format="" data-tpl-legal="1" id="tpl-emitente-morada">{{ emitente.morada }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="adquirente.nome" data-tpl-format="" data-tpl-legal="1" id="tpl-adquirente-nome">{{ adquirente.nome }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="adquirente.nif" data-tpl-format="" data-tpl-legal="1" id="tpl-adquirente-nif">{{ adquirente.nif }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="adquirente.morada" data-tpl-format="" data-tpl-legal="1" id="tpl-adquirente-morada">{{ adquirente.morada }}</div>',
                '</div>',

                '<div class="zone zone--body" data-tpl-zone="body">',

                '<div class="block block--lines" data-tpl-type="line-table" data-tpl-collection="linhas"'
                .' data-tpl-columns="[{&quot;key&quot;:&quot;codigo&quot;,&quot;label&quot;:&quot;Código&quot;,&quot;width&quot;:&quot;24mm&quot;,&quot;align&quot;:&quot;left&quot;,&quot;format&quot;:&quot;&quot;},'
                .'{&quot;key&quot;:&quot;descricao&quot;,&quot;label&quot;:&quot;Descrição&quot;,&quot;width&quot;:&quot;62mm&quot;,&quot;align&quot;:&quot;left&quot;,&quot;format&quot;:&quot;&quot;},'
                .'{&quot;key&quot;:&quot;quantidade&quot;,&quot;label&quot;:&quot;Quantidade&quot;,&quot;width&quot;:&quot;20mm&quot;,&quot;align&quot;:&quot;right&quot;,&quot;format&quot;:&quot;number&quot;},'
                .'{&quot;key&quot;:&quot;unidade&quot;,&quot;label&quot;:&quot;Unidade&quot;,&quot;width&quot;:&quot;12mm&quot;,&quot;align&quot;:&quot;left&quot;,&quot;format&quot;:&quot;&quot;},'
                .'{&quot;key&quot;:&quot;preco_unitario&quot;,&quot;label&quot;:&quot;Preço unitário&quot;,&quot;width&quot;:&quot;22mm&quot;,&quot;align&quot;:&quot;right&quot;,&quot;format&quot;:&quot;money&quot;},'
                .'{&quot;key&quot;:&quot;taxa_iva&quot;,&quot;label&quot;:&quot;Taxa de IVA&quot;,&quot;width&quot;:&quot;14mm&quot;,&quot;align&quot;:&quot;right&quot;,&quot;format&quot;:&quot;&quot;},'
                .'{&quot;key&quot;:&quot;valor&quot;,&quot;label&quot;:&quot;Valor&quot;,&quot;width&quot;:&quot;26mm&quot;,&quot;align&quot;:&quot;right&quot;,&quot;format&quot;:&quot;money&quot;}]"'
                .' id="tpl-linhas" style="top:0mm">'
                .'<table class="lines"><thead><tr>'
                .'<th style="text-align:left" width="24mm">Código</th>'
                .'<th style="text-align:left" width="62mm">Descrição</th>'
                .'<th style="text-align:right" width="20mm">Quantidade</th>'
                .'<th style="text-align:left" width="12mm">Unidade</th>'
                .'<th style="text-align:right" width="22mm">Preço unitário</th>'
                .'<th style="text-align:right" width="14mm">Taxa de IVA</th>'
                .'<th style="text-align:right" width="26mm">Valor</th>'
                .'</tr></thead><tbody>'
                .'{% for linha in linhas %}<tr>'
                .'<td style="text-align:left">{{ linha.codigo }}</td>'
                .'<td style="text-align:left">{{ linha.descricao }}</td>'
                .'<td style="text-align:right">{{ linha.quantidade|number }}</td>'
                .'<td style="text-align:left">{{ linha.unidade }}</td>'
                .'<td style="text-align:right">{{ linha.preco_unitario|money }}</td>'
                .'<td style="text-align:right">{{ linha.taxa_iva }}</td>'
                .'<td style="text-align:right">{{ linha.valor|money }}</td>'
                .'</tr>{% endfor %}'
                .'</tbody></table></div>',

                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="totais.motivo_isencao" data-tpl-format="" data-tpl-legal="0" id="tpl-motivo-isencao">{{ totais.motivo_isencao }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="totais.total_iliquido" data-tpl-format="money" data-tpl-legal="0" id="tpl-total-iliquido">{{ totais.total_iliquido|money }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="totais.total_impostos" data-tpl-format="money" data-tpl-legal="1" id="tpl-total-impostos">{{ totais.total_impostos|money }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="totais.total_a_pagar" data-tpl-format="money" data-tpl-legal="1" id="tpl-total-a-pagar">{{ totais.total_a_pagar|money }}</div>',
                '</div>',

                '<div class="zone zone--footer" data-tpl-zone="footer">',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.certificado" data-tpl-format="" data-tpl-legal="1" id="tpl-certificado">{{ documento.certificado }}</div>',
                '<div class="block block--field" data-tpl-type="data-field" data-tpl-binding="documento.hash_caracteres" data-tpl-format="" data-tpl-legal="1" id="tpl-hash">{{ documento.hash_caracteres }}</div>',

                '<img class="block block--code" data-tpl-type="code-slot" data-tpl-binding="documento.qr" data-tpl-legal="1" id="tpl-qr" style="width:30mm;height:30mm" alt="">',
                '</div>',

                '</div>',
            ]),

            'css' => implode('', [
                '#tpl-designacao{position:absolute;left:0;top:0;font-size:16pt;font-weight:700;color:#000}',
                '#tpl-numero{position:absolute;left:0;top:8mm;font-size:10pt;color:#000}',
                '#tpl-atcud{position:absolute;left:0;top:13mm;font-size:8pt;color:#000}',

                '#tpl-data-emissao{position:absolute;left:120mm;top:0;width:60mm;text-align:right;font-size:9pt;color:#000}',
                '#tpl-data-bens{position:absolute;left:120mm;top:5mm;width:60mm;text-align:right;font-size:8pt;color:#000}',

                '#tpl-emitente-nome{position:absolute;left:0;top:20mm;width:180mm;font-size:9pt;font-weight:700;color:#000}',
                '#tpl-emitente-nif{position:absolute;left:0;top:24.5mm;font-size:8pt;color:#000}',
                '#tpl-emitente-morada{position:absolute;left:0;top:28.5mm;width:180mm;font-size:8pt;color:#000}',
                '#tpl-adquirente-nome{position:absolute;left:0;top:37mm;width:180mm;font-size:9pt;font-weight:700;color:#000}',
                '#tpl-adquirente-nif{position:absolute;left:0;top:41.5mm;font-size:8pt;color:#000}',
                '#tpl-adquirente-morada{position:absolute;left:0;top:45.5mm;width:180mm;font-size:8pt;color:#000}',

                '#tpl-linhas{position:absolute;left:0;width:180mm;font-size:7pt;color:#000}',

                '#tpl-motivo-isencao{position:absolute;left:0;bottom:6mm;width:110mm;font-size:7pt;color:#000}',
                '#tpl-total-iliquido{position:absolute;right:0;bottom:22mm;font-size:9pt;color:#000}',
                '#tpl-total-impostos{position:absolute;right:0;bottom:14mm;font-size:9pt;color:#000}',
                '#tpl-total-a-pagar{position:absolute;right:0;bottom:6mm;font-size:11pt;font-weight:700;color:#000}',

                '#tpl-certificado{position:absolute;left:0;bottom:0;font-size:7pt;color:#000}',
                '#tpl-hash{position:absolute;left:0;bottom:5mm;font-size:7pt;color:#000}',
                '#tpl-qr{position:absolute;right:0;top:2mm}',
            ]),

            // Zone bands, in millimetres.
            'zones' => [
                'header_height' => 55.0,
                'footer_height' => 35.0,
            ],
        ],

        'pdf' => [
            'paper_format' => 'A4',

            'margins' => [
                'top' => 15.0,
                'right' => 15.0,
                'bottom' => 15.0,
                'left' => 15.0,
            ],

            'visibility_limits' => [
                'font_size_min_pt' => 7,
                'contrast_min' => 4.5,
                'opacity_min' => 1.0,
                'occlusion' => 0,
                'truncation' => 0,
                'qr_size_min_mm' => 30,
            ],

            'line_row_height_mm' => 4.5,
            'body_reserve_mm' => 32.0,
            'max_pages' => 10,
        ],
    ],
];
