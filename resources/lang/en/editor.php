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

    'zones' => [
        'header' => 'Header',
        'body'   => 'Body',
        'footer' => 'Footer',
    ],

    'blocks' => [
        'line_table'  => 'Line table',
        'page_number' => 'Page number',
    ],

    // Layer 1 of the validation: static, over `project_data` (Output\Pdf\LayoutValidator).
    'validation' => [
        'unknown_binding'   => 'The field ":binding" does not exist in this document type catalogue.',
        'unknown_format'    => 'The format ":format" is not allowed.',
        'missing_mandatory' => 'The mandatory field ":field" is not in the document.',
        'missing_legal'     => 'The legally required field ":field" is not in the document.',
        'image_source'      => 'The image has no file.',
        'image_too_large'   => 'The image exceeds the maximum allowed size.',
        'outside_zone'      => 'The block runs outside the :zone zone.',
        'unsafe_markup'     => 'The layout received carries content a document must not carry.',
        'duplicate_table'   => 'More than one table is bound to ":field". The lines would print twice.',
        'wrong_zone'        => 'The ":block" block can only sit in: :zones.',
        'code_size_unknown' => 'The ":field" code has no stated size. The legal minimum is about the printed size, so the layout has to say it.',
        'code_too_small'    => 'The ":field" code is smaller than :minimum mm on a side.',
        'font_size'         => '":field" uses a font smaller than :minimum pt.',
        'display_none'      => '":field" is hidden (or sits inside a hidden block).',
        'visibility_hidden' => '":field" is invisible (or sits inside an invisible block).',
        'opacity'           => '":field" is transparent.',
        'contrast'          => '":field" has a contrast of :contrast against its background; the minimum is :minimum.',
        'scope'             => '":field" has to appear on every page, so it can only sit in the header or the footer.',
        'outside_printable' => '":field" is outside the printable area.',
        'occluded'          => '":field" is covered by an opaque element placed on top of it.',
    ],

    // Routes (Http\Controllers\*), success messages.
    'messages' => [
        'created'       => 'Template created.',
        'saved'         => 'Layout saved.',
        'draft_created' => 'Draft version created.',
        'activated'     => 'Version activated. The previous one was archived.',
        'duplicated'    => 'Template duplicated.',
        'copy_name'          => ':name (copy)',
        'copy_name_numbered' => ':name (copy :number)',
    ],

    // Routes (Http\Controllers\*) and activation (Versions\VersionActivator).
    'errors' => [
        'version_immutable'  => 'This version is immutable. Create a new version from it to make changes.',
        'validation_failed'  => 'The layout has problems that prevent it from being saved. Fix them and save again.',
        'activation_failed'  => 'The render validation failed. The version was not activated.',
        'nothing_to_preview' => 'This version has no saved layout to preview yet.',
        'unsafe_template'    => 'The layout received carries content a template must not carry. It was neither saved nor rendered.',
        'render_failed'      => 'The PDF could not be generated. The rendering engine did not respond as expected.',
        'type_not_creatable' => 'This document type cannot be created from scratch: it is obtained by duplicating an existing template.',
    ],

    // Http\Requests\StoreTemplateRequest, Http\Requests\UpdateVersionRequest.
    'attributes' => [
        'name'         => 'name',
        'type_code'    => 'document type',
        'project_data' => 'layout',
        'html'         => 'layout HTML',
        'css'          => 'layout CSS',
    ],

    // Layer 2 of the validation: over the rendered PDF (Output\Pdf\RenderValidator).
    'render' => [
        'failed'              => 'Rendering failed: :reason',
        'too_slow'            => 'Rendering took :seconds s; the limit is :limit s.',
        'too_many_pages'      => 'The document came out with :pages pages; the limit for this document type is :limit.',
        'value_missing'       => 'The value of ":field" does not appear in the generated PDF.',
        'missing_on_page'     => 'Page :page: ":field" has to appear on every page and is missing from this one.',
        'outside_printable'   => 'Page :page: ":field" fell outside the printable area.',
        'code_unreadable'     => 'The ":field" code could not be read from the generated PDF.',
        'code_mismatch'       => 'Page :page: the ":field" code reads different content from the expected one.',
        'code_reader_missing' => 'The ":field" code cannot be checked: the QR reading library is missing from this installation.',
    ],

    // The content types a fixed-text code slot may carry (Codes\CodePayload).
    'code_types' => [
        'text'  => 'Text',
        'url'   => 'Web address',
        'email' => 'Email',
        'phone' => 'Phone',
        'sms'   => 'SMS',
        'wifi'  => 'Wi-Fi',
    ],

    'code_sources' => [
        'dynamic' => 'Dynamic',
        'fixed'   => 'Fixed text',
    ],

];
