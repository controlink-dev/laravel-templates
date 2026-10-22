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
        'page'   => 'Page',
    ],

    'blocks' => [
        'static_text'         => 'Text',
        'static_text_default' => 'Text',
        'static_text_empty'   => 'Text (empty)',
        'data_field'          => 'Data field',
        'image'               => 'Image',
        'rule'                => 'Line / Rectangle',
        'line_table'          => 'Line table',
        'code_slot'           => 'QR / barcode',
        'page_number'         => 'Page number',
    ],

    'traits' => [
        'content'       => 'Content',
        'wrap'          => 'Multiple lines',
        'format'        => 'Format',
        'file'          => 'File',
        'fit'           => 'Fit',
        'thickness'     => 'Thickness',
        'filled'        => 'Filled',
        'collection'    => 'Collection',
        'columns'       => 'Columns',
        'column_label'  => 'Label',
        'column_width'  => 'Width',
        'column_remove' => 'Remove column',
        'column_align'  => 'Alignment',
        'column_reorder' => 'Drag to reorder',
        'column_hide'   => 'Hide column',
        'column_show'   => 'Show column',
        'node'          => 'Model',
        'field_source'  => 'Data source',
        'code_source'   => 'Source',
        'code_type'     => 'Type',
        'page_format'   => 'Format',
    ],

    'states' => [
        'draft'     => 'Draft',
        'validated' => 'Validated',
        'active'    => 'Active',
        'archived'  => 'Archived',
    ],

    'editor' => [
        'heading'         => 'Template editor',
        'back'            => 'Back to templates',
        'save'            => 'Save',
        'preview'         => 'Preview',
        'activate'        => 'Activate',
        'activating'      => 'Validating and activating…',
        'saving'          => 'Saving…',
        'rendering'       => 'Generating the preview…',
        'unsaved'         => 'Unsaved changes',
        'unsaved_warning' => 'You have unsaved changes in this template. Leaving now discards them.',
        'format_switch_confirm' => 'Switching format replaces the current layout. Continue?',
        'version'         => 'Version :number',
        'document_type'   => 'Document type',
        'open_full_page'  => 'Open in a full page',
        'read_only'       => 'This version can no longer be changed. Create a new version from it.',
    ],

    /* Editor chrome: toolbar, panels and rulers — our own labels, not the canvas library's. */
    'chrome' => [
        'zoom'           => 'Zoom',
        'zoom_out'       => 'Zoom out',
        'zoom_in'        => 'Zoom in',
        'fit'            => 'Fit to window',
        'page_format'    => 'Page format',
        'legal_fields'   => 'Legal fields',
        'legal_hint'     => 'Marks the fields required by law while you check the layout.',
        'zones'          => 'Zones',
        'zones_hint'     => 'Shows where the header ends and the body begins.',
        'rulers'         => 'Rulers',
        'rulers_hint'    => 'Scale in millimetres, and the extent of the chosen block.',
        'clean_view'     => 'Clean view',
        'clean_hint'     => 'The sheet exactly as it prints.',
        'blocks'         => 'Blocks',
        'style'          => 'Style',
        'field'          => 'Field',
        'layers'         => 'Layers',
        'nothing_chosen' => 'Choose a block on the sheet.',
        'zone'           => 'Zone',
        'scope_any'      => 'visible on any page',
        'scope_all'      => 'visible on every page',
        'type'           => ':type',
        'legal_chip'     => 'legal',
        'count_fields'   => ':count field|:count fields',
        'count_tables'   => ':count table|:count tables',
        'count_codes'    => ':count code|:count codes',
        'count_problems' => ':count problem|:count problems',
    ],

    'actions' => [
        'restore_legal'      => 'Restore missing legal fields',
        'restore_legal_none' => 'No legal field is missing.',
        'restore_legal_done' => ':count legal field(s) restored.',
        'snap_to_sheet'      => 'Bring onto the sheet',
        'snap_to_sheet_done' => ':count field(s) brought back onto the sheet.',
        'snap_to_sheet_none' => 'Nothing to bring back: no field is off the sheet.',
    ],

    'formats' => [
        'none'   => 'No format',
        'date'   => 'Date',
        'number' => 'Number',
        'money'  => 'Currency',
        'upper'  => 'Uppercase',
    ],

    'format_args' => [
        'pattern'         => 'Date pattern',
        'decimals'        => 'Decimal places',
        'symbol'          => 'Symbol',
        'decimals_money'  => 'From the currency',
        'decimals_number' => 'Automatic',
    ],

    'geometry' => [
        'page'             => 'Page',
        'margin'           => [
            'top'    => 'Top margin',
            'right'  => 'Right margin',
            'bottom' => 'Bottom margin',
            'left'   => 'Left margin',
        ],
        'header_height'    => 'Header height',
        'footer_height'    => 'Footer height',
        'body_too_small'   => 'That leaves the body under 20 mm. Reduce the margins or the heights.',
        'margins_too_wide' => 'The left and right margins leave no width on the sheet.',
    ],

    'format_symbols' => [
        'before' => 'Symbol before (€ 1.00)',
        'after'  => 'Symbol after (1.00 €)',
        'code'   => 'Code at the end (1.00 EUR)',
        'none'   => 'No symbol (1.00)',
    ],

    'fits' => [
        'contain' => 'Fit without cropping',
        'cover'   => 'Fill and crop',
        'fill'    => 'Stretch',
    ],

    'rule_widths' => [
        'thin'   => 'Thin',
        'medium' => 'Medium',
        'thick'  => 'Thick',
    ],

    'sectors' => [
        'typography' => 'Typography',
        'box'        => 'Box',
        'border'     => 'Border',
        'position'   => 'Position',
    ],

    /* Style panel property labels; the canvas library ships its own and never translates them. */
    'properties' => [
        'font_size'        => 'Size',
        'font_weight'      => 'Weight',
        'font_style'       => 'Style',
        'text_align'       => 'Alignment',
        'line_height'      => 'Line height',
        'letter_spacing'   => 'Letter spacing',
        'color'            => 'Colour',
        'width'            => 'Width',
        'height'           => 'Height',
        'padding'          => 'Inner spacing',
        'background_color' => 'Background colour',
        'border_width'     => 'Border width',
        'border_style'     => 'Border style',
        'border_color'     => 'Border colour',
        'top'              => 'Top',
        'left'             => 'Left',
        'right'            => 'Right',
        'bottom'           => 'Bottom',
    ],

    /* Style property options; the canvas library offers 100-900 and raw CSS keywords. */
    'style_options' => [
        'weight_normal' => 'Normal',
        'weight_bold'   => 'Bold',
        'style_normal'  => 'Normal',
        'style_italic'  => 'Italic',
        'align_left'    => 'Left',
        'align_center'  => 'Centre',
        'align_right'   => 'Right',
        'align_justify' => 'Justified',
        'border_none'   => 'None',
        'border_solid'  => 'Solid',
        'border_dashed' => 'Dashed',
        'border_dotted' => 'Dotted',
    ],

    'problems' => [
        'heading'   => ':count problem(s) to resolve before this template can be activated',
        'structure' => 'Structure',
        'legal'     => 'Legal fields',
        'render'    => 'Rendering',
    ],

    'checks' => [
        'missing'   => 'The legally required field ":field" is not in the template.',
        'font_size' => '":field" uses a font smaller than :minimum pt.',
        'opacity'   => '":field" is transparent.',
        'hidden'    => '":field" is hidden.',
        'scope'     => '":field" has to appear on every page, so it can only sit in the header or the footer.',
        'off_sheet' => 'The field ":field" is off the sheet.',
        'text_overflow' => 'The text of ":field" does not fit its box and will be cut.',
        'missing_table' => 'The ":field" table is missing. It is required to activate.',

        'code_incomplete' => 'This fixed code has required fields left blank. Without them the code is valid but points at nothing.',
    ],

    'page_formats' => [
        'x'      => 'Page number',
        'x_of_y' => 'Number and total (1 of 3)',
        'of'     => 'of',
    ],

    'aligns' => [
        'left'   => 'Left',
        'center' => 'Center',
        'right'  => 'Right',
    ],

    'notices' => [
        'legal_locked' => '":field" cannot be deleted. It is a field required by law on this document type.',
        'close'        => 'Close notice',
        'confirm_delete_legal' => '":field" is a field required by law. Delete it anyway? You will need to place it again before you can put the document into production.',
        'confirm_occlusion'    => 'Some fields may not appear when printed because something is placed on top of them. Save anyway?',
    ],

    // Buttons of the editor's own confirm/alert dialogs (dialog.js), in place of the
    // browser's native dialogs.
    'dialog' => [
        'confirm' => 'Continue',
        'cancel'  => 'Cancel',
        'close'   => 'OK',
    ],

    // Layer 1 of the validation: static, over `project_data` (Output\Pdf\LayoutValidator).
    'validation' => [
        'unknown_binding'   => 'The field ":binding" does not exist in this document type catalogue.',
        'unknown_format'    => 'The format ":format" is not allowed.',
        'format_mismatch'   => 'The printed format of the field does not match the format chosen for it. Choose the format again and save.',
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
        'occluded_warning'  => '":field" is covered by another element placed on top of it and may not appear when printed.',
        // V-19 (CGE-37): `:formula` is the column header, or the generic formula label on a field.
        'formula_syntax'            => '":formula": the formula is not written correctly. Check the operators, brackets, commas between arguments, quotes and the number of arguments of each function.',
        'formula_unknown_function'  => '":formula": the formula uses a function that does not exist.',
        'formula_unknown_reference' => '":formula": the formula uses a field that does not exist in this document type (or the result of another formula, which is not allowed).',
        'formula_scope'             => '":formula": a column of the lines can only be used inside SUM or COUNT here.',
        'formula_duplicate_key'     => '":formula": two formulas share the same key. Delete this one and create it again.',
        'formula_without_source'    => '":formula": the formula is empty.',
    ],

    // Formula fields and columns (CGE-37).
    'formula' => [
        'label'        => 'Formula',
        'toggle'       => 'Calculate with a formula',
        'legal_locked' => 'A legal field cannot become a formula: the document would lose the field the law requires.',
        'mandatory_locked' => 'A mandatory field cannot become a formula: the document would lose a field this document type requires.',
        'source'       => 'Formula',
        'placeholder'  => 'e.g. totals.total_due * 2',
        'calculating'  => 'Calculating…',
        'result'       => 'Result: :value',
        'blank'        => 'No value: prints empty.',

        // The data field's "Value: Field | Formula" and its formula box (spec §6.1, §6.4).
        // `:name` and `:field` are filled in JS.
        'value'         => 'Value',
        'value_field'   => 'Field',
        'value_formula' => 'Formula',
        'done'          => 'Done',
        'unknown_field' => 'Unknown field: :name',
        'quick' => [
            'heading'     => 'Quick examples',
            'text'        => 'Text with a field in the middle',
            'text_source' => '"Text before " & :field & " text after"',
            'upper'       => 'Capitals',
            'total'       => 'Table total',
            // The column box's, made from the column itself.
            'left'        => 'First 40 characters',
            'round'       => 'Round',
            'share'       => '% of the total',
            'zero_blank'  => 'Blank when zero',
        ],

        // The functions' group in the suggestions under the formula box.
        'functions_group'   => 'Functions',

        // One per function the parser accepts (Formulas\Parser::FUNCTIONS); the bundle reads
        // the function list from these keys, so a function added there needs both here.
        'signatures' => [
            'IF'     => 'IF(condition, if_true, if_false)',
            'AND'    => 'AND(condition, …)',
            'OR'     => 'OR(condition, …)',
            'NOT'    => 'NOT(condition)',
            'SUM'    => 'SUM(collection.column)',
            'COUNT'  => 'COUNT(collection.column)',
            'ROUND'  => 'ROUND(number[, decimals])',
            'ABS'    => 'ABS(number)',
            'MIN'    => 'MIN(number, …)',
            'MAX'    => 'MAX(number, …)',
            'UPPER'  => 'UPPER(text)',
            'LOWER'  => 'LOWER(text)',
            'CONCAT' => 'CONCAT(text, …)',
            'LEFT'   => 'LEFT(text[, count])',
            'RIGHT'  => 'RIGHT(text[, count])',
            'LEN'    => 'LEN(text)',
            'TRIM'   => 'TRIM(text)',
            'YEAR'   => 'YEAR(date)',
            'MONTH'  => 'MONTH(date)',
            'DAY'    => 'DAY(date)',
            'DAYS'   => 'DAYS(end, start)',
            'TEXT'   => 'TEXT(value, "date" | "money" | "number"[, option])',
        ],
        'descriptions' => [
            'IF'     => 'The second value when the condition holds, the third otherwise.',
            'AND'    => 'True when every condition holds.',
            'OR'     => 'True when at least one condition holds.',
            'NOT'    => 'The opposite of the condition.',
            'SUM'    => 'Adds up the column over every line of the document.',
            'COUNT'  => 'How many lines the document has.',
            'ROUND'  => 'The number rounded to that many decimals (none when left out).',
            'ABS'    => 'The number without its sign.',
            'MIN'    => 'The smallest of the values.',
            'MAX'    => 'The largest of the values.',
            'UPPER'  => 'The text in capitals.',
            'LOWER'  => 'The text in lower case.',
            'CONCAT' => 'The texts joined together (the same as &).',
            'LEFT'   => 'The first characters of the text (one when the count is left out).',
            'RIGHT'  => 'The last characters of the text (one when the count is left out).',
            'LEN'    => 'How many characters the text has.',
            'TRIM'   => 'The text without spaces at the start and the end.',
            'YEAR'   => 'The year of the date.',
            'MONTH'  => 'The month of the date (1 to 12).',
            'DAY'    => 'The day of the month of the date.',
            'DAYS'   => 'The days from the start date to the end date.',
            'TEXT'   => 'The value written as the document prints it: a date, an amount or a number. The optional third argument is an option of that format (for a date, its pattern).',
        ],

        // The Blocks rail while a formula is edited: "Fields and functions" (spec §6.3).
        // `:example` is filled in JS.
        'library' => [
            'heading'          => 'Fields and functions',
            'fields'           => 'Fields',
            'functions'        => 'Functions',
            'search_fields'    => 'Search fields…',
            'search_functions' => 'Search functions…',
            'empty'            => 'Nothing found.',
            'example'          => 'e.g. :example',
            'this_row'         => 'This line',
        ],

        // The function groups of the list ("Table totals" also among the fields).
        'groups' => [
            'math'       => 'Arithmetic',
            'totals'     => 'Table totals',
            'conditions' => 'Conditions',
            'text'       => 'Text',
            'dates'      => 'Dates',
            'other'      => 'Other',
        ],

        // One example per function, written with technical keys of the fiscal document
        // type; the editor shows it with the readable names of the open type (fields that
        // type lacks stay as they are). Quoted text is never translated by the editor.
        'examples' => [
            'IF'     => 'IF(totals.tax_total > 0, totals.total_due, totals.net_total)',
            'AND'    => 'IF(AND(totals.tax_total > 0, totals.net_total > 0), "Yes", "No")',
            'OR'     => 'IF(OR(totals.tax_total = 0, totals.net_total = 0), "Exempt", "")',
            'NOT'    => 'IF(NOT(totals.tax_total > 0), "No VAT", "")',
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
            'TEXT'   => '"Total: " & TEXT(totals.total_due, "money")',
        ],

        // Line-table columns in Formula and calculated columns: computed for each line. The
        // placeholder's `:example` is the column's own first quick example, by name.
        'column_label'       => 'Formula',
        'add_column'         => '+ Calculated column',
        'remove_column'      => 'Remove calculated column',
        'column_calculated'  => 'A calculated column is always a formula; to drop it, delete it.',
        'column_no_collection' => 'A table with no collection has no lines for a formula to read.',
        'column_placeholder' => 'e.g. :example',
        'column_help'        => 'The result shown is for the first line.',
        'columns_overflow'   => 'The columns take more than the width of the table; make one narrower or hide one.',
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
        'image_too_large'    => 'The image is too large. Choose a smaller file.',
        'catalogue_failed'   => 'The field catalogue for this document type could not be loaded. The editor does not open without it.',
        'load_failed'        => 'The layout of this version could not be loaded.',
        'save_failed'        => 'The layout could not be saved.',
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
        'page_size_mismatch'  => 'The generated PDF is :actual but the template asks for :expected.',
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

    'code_fields' => [
        'text'     => 'Text',
        'url'      => 'URL',
        'email'    => 'Email',
        'subject'  => 'Subject',
        'number'   => 'Number',
        'message'  => 'Message',
        'security' => 'Security',
        'network'  => 'Network name',
        'password' => 'Password',
    ],

    // The sample-data panel: previewing the layout with real records (never saved).
    'preview' => [
        'tab' => 'Sample',
        'intro' => 'Preview the layout with real records. This only changes what you see here: it is never saved.',
        'none' => 'There is nothing to preview for this document type.',
        'unbound' => 'Bind a field or a table to preview it with real data',
        'example' => 'Example',
        'add_filter' => 'Add filter',
        'field_placeholder' => 'Field',
        'value_placeholder' => 'Value',
        'op_contains' => 'contains',
        'op_eq' => 'equals',
        'remove_filter' => 'Remove filter',
        'loading' => 'Loading…',
        'show_more' => 'Show more',
        'use_example' => 'Click to go back to the example',
        'no_results' => 'No records match. The example is shown.',
        'collection_hint' => 'Add a filter to preview real lines.',
        'rows_shown' => 'Showing :count of :total',
        'lines_badge' => ':count lines',
        'operator' => 'Operator',
        'showing' => 'Showing :shown of :total',
        'error' => 'The records could not be loaded.',
    ],

];
