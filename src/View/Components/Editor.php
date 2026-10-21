<?php

namespace Controlink\Templates\View\Components;

use Closure;
use Controlink\Templates\Types\TemplateType;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use RuntimeException;

/**
 * The template editor, as an embeddable component.
 *
 * ```blade
 * <x-templates::editor
 *     :template="$template"
 *     :version="$version"
 *     height="calc(100vh - 8rem)"
 * />
 * ```
 *
 * It mounts its own bundle inside its own container and takes no control of the page
 * around it: no global variables, no assumptions about the layout, and its stylesheet
 * and script are their own asset entry point, published under `public/vendor/templates`
 * — a host page loads none of the editor's CSS or JavaScript unless it renders this
 * component.
 *
 * Every setting reaches the browser through `data-*` attributes on the container,
 * including the interface strings: the bundle deliberately loads none of the host's
 * JavaScript, so it has no access to the host's translation layer.
 *
 * Phase 1 supports only `mode="print"` — a PDF layout edited on an A4/A5 canvas. Any
 * other mode throws, rather than silently rendering something that was never built.
 */
class Editor extends Component
{
    /**
     * The token a host leaves in `urls['format_switch']` for the chosen format id. The
     * editor bundle substitutes it on an in-place format switch (`editor.js`), so the
     * host builds the URL once and never has to know the format ahead of time.
     */
    public const FORMAT_PLACEHOLDER = '__FORMAT__';

    /**
     * @param  string  $height  any CSS length; the component fills its container's width
     * @param  bool  $actions  render the save/preview/activate bar, or leave it to the host page
     * @param  string  $mode  phase 1 supports only `print`
     * @param  array<string, string>  $urls  the host's own endpoints (the package registers no routes): load, save, preview, activate, catalogue, stylesheet, font (with {family}/{weight}), format_switch (with the literal {@see self::FORMAT_PLACEHOLDER})
     * @param  bool  $formatSelector  render the in-toolbar page-format `<select>`. A host that drives format switching from its own page chrome (format tabs that hit `urls['format_switch']`) turns this off so the two controls do not both show.
     * @param  bool  $hostNotices  hand outcome messages (saved, refused, render failed) to the host instead of the editor's own status line. The editor still announces each as a bubbling `templates:message` DOM event ({@see resources/js/editor/editor.js}); turn this on only where the host page actually listens for it, or the message is lost.
     */
    public function __construct(
        public Model $template,
        public Model $version,
        public string $height = '100%',
        public bool $actions = true,
        public string $mode = 'print',
        public array $urls = [],
        public bool $formatSelector = true,
        public bool $hostNotices = false,
    ) {
        if ($this->mode !== 'print') {
            throw new \InvalidArgumentException("Unsupported editor mode [{$this->mode}]; phase 1 supports only 'print'.");
        }

        if ($this->version->template_id !== $this->template->getKey()) {
            // A version from another template would load someone else's layout into
            // this template's editor, and the first save would write it there.
            throw new RuntimeException('The version does not belong to the template.');
        }
    }

    /** The document type definition, from `config('templates.types')`. */
    public function type(): TemplateType
    {
        return $this->template->type();
    }

    /**
     * The typeface, as one entry per weight.
     *
     * The canvas loads each face by URL and the compositor embeds the same files as
     * data URIs; the same bytes on both sides is what makes a measured text width mean
     * anything. An absolute path (the package's own default font, which lives inside
     * `vendor/`, outside the host's public directory) is served by the host at `$urls['font']`;
     * a relative one is served straight from the host's public directory, the way the host's own assets are.
     *
     * @return array{family: string, faces: array<int, array{weight: int, url: string, format: string}>}
     */
    public function font(): array
    {
        $fonts  = (array) config('templates.fonts');
        $family = (string) array_key_first($fonts);

        if ($family === '') {
            throw new RuntimeException('No template font is configured.');
        }

        $faces = [];

        foreach ((array) ($fonts[$family]['weights'] ?? []) as $weight => $face) {
            $faces[] = [
                'weight' => (int) $weight,
                // Cache-busted the same way `assetUrl()` busts the bundle's own
                // files: by the font file's own content hash, so a face the host
                // swapped is a new URL rather than a stale one served under the same
                // name — the canvas has to measure text against the exact bytes
                // `DocumentComposer::fontFaces()` embeds, or the width it shows is a
                // promise the PDF does not keep.
                'url' => self::isAbsolutePath((string) $face['path'])
                    ? str_replace(['{family}', '{weight}'], [$family, (string) $weight], $this->urls['font'] ?? '').'?v='.self::fontVersion((string) $face['path'])
                    : asset($face['path']).'?v='.self::fontVersion((string) $face['path']),
                'format' => (string) $face['format'],
            ];
        }

        if ($faces === []) {
            throw new RuntimeException("The template font {$family} declares no weights.");
        }

        return ['family' => $family, 'faces' => $faces];
    }

    /** A Windows drive letter or a leading slash: the font lives outside the host's public directory. */
    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:/', $path) === 1;
    }

    /**
     * The font file's own content hash, 12 hex characters — the same convention
     * `assetUrl()` uses — or `missing` when the file cannot be read.
     */
    private static function fontVersion(string $path): string
    {
        $resolved = self::isAbsolutePath($path) ? $path : public_path($path);

        return is_file($resolved) ? substr(hash_file('sha256', $resolved), 0, 12) : 'missing';
    }

    /**
     * Every string the editor bundle shows, already translated.
     *
     * The bundle deliberately loads none of the host's JavaScript, so it has no access
     * to a translation layer. The map lives on the component rather than in a
     * controller because the component is what renders them — a second host page
     * should not have to know this list exists.
     *
     * @return array<string, string>
     */
    public function strings(): array
    {
        $strings = [
            'zone.header' => __('templates::editor.zones.header'),
            'zone.body'   => __('templates::editor.zones.body'),
            'zone.footer' => __('templates::editor.zones.footer'),
            'zone.page'   => __('templates::editor.zones.page'),

            'block.static_text'         => __('templates::editor.blocks.static_text'),
            'block.static_text_default' => __('templates::editor.blocks.static_text_default'),
            'block.data_field'          => __('templates::editor.blocks.data_field'),
            'block.image'               => __('templates::editor.blocks.image'),
            'block.rule'                => __('templates::editor.blocks.rule'),
            'block.line_table'          => __('templates::editor.blocks.line_table'),
            'block.code_slot'           => __('templates::editor.blocks.code_slot'),
            'block.page_number'         => __('templates::editor.blocks.page_number'),

            'trait.collection'    => __('templates::editor.traits.collection'),
            'trait.columns'       => __('templates::editor.traits.columns'),
            'trait.column_label'  => __('templates::editor.traits.column_label'),
            'trait.column_width'  => __('templates::editor.traits.column_width'),
            'trait.column_remove' => __('templates::editor.traits.column_remove'),
            'trait.column_align'  => __('templates::editor.traits.column_align'),
            'trait.column_reorder' => __('templates::editor.traits.column_reorder'),
            'trait.column_hide'   => __('templates::editor.traits.column_hide'),
            'trait.column_show'   => __('templates::editor.traits.column_show'),
            'trait.node'          => __('templates::editor.traits.node'),
            'trait.field_source'  => __('templates::editor.traits.field_source'),
            'trait.code_source'   => __('templates::editor.traits.code_source'),
            'trait.code_type'     => __('templates::editor.traits.code_type'),
            'trait.page_format'   => __('templates::editor.traits.page_format'),
            'trait.content'       => __('templates::editor.traits.content'),
            'trait.format'        => __('templates::editor.traits.format'),
            'trait.file'          => __('templates::editor.traits.file'),
            'trait.fit'           => __('templates::editor.traits.fit'),
            'trait.thickness'     => __('templates::editor.traits.thickness'),
            'trait.filled'        => __('templates::editor.traits.filled'),

            'page_format.x'      => __('templates::editor.page_formats.x'),
            'page_format.x_of_y' => __('templates::editor.page_formats.x_of_y'),
            'page_format.of'     => __('templates::editor.page_formats.of'),

            'format.none' => __('templates::editor.formats.none'),

            'fit.contain' => __('templates::editor.fits.contain'),
            'fit.cover'   => __('templates::editor.fits.cover'),
            'fit.fill'    => __('templates::editor.fits.fill'),

            /*
             * One label per property the style manager offers. The style manager
             * labels them in English and has no translation layer of its own, so a
             * Portuguese panel would otherwise read "Tipografia / Font size".
             */
            'prop.font_size'        => __('templates::editor.properties.font_size'),
            'prop.font_weight'      => __('templates::editor.properties.font_weight'),
            'prop.font_style'       => __('templates::editor.properties.font_style'),
            'prop.text_align'       => __('templates::editor.properties.text_align'),
            'prop.line_height'      => __('templates::editor.properties.line_height'),
            'prop.letter_spacing'   => __('templates::editor.properties.letter_spacing'),
            'prop.color'            => __('templates::editor.properties.color'),
            'prop.width'            => __('templates::editor.properties.width'),
            'prop.height'           => __('templates::editor.properties.height'),
            'prop.padding'          => __('templates::editor.properties.padding'),
            'prop.background_color' => __('templates::editor.properties.background_color'),
            'prop.border_width'     => __('templates::editor.properties.border_width'),
            'prop.border_style'     => __('templates::editor.properties.border_style'),
            'prop.border_color'     => __('templates::editor.properties.border_color'),
            'prop.top'              => __('templates::editor.properties.top'),
            'prop.left'             => __('templates::editor.properties.left'),

            'weight.normal' => __('templates::editor.style_options.weight_normal'),
            'weight.bold'   => __('templates::editor.style_options.weight_bold'),
            'style.normal'  => __('templates::editor.style_options.style_normal'),
            'style.italic'  => __('templates::editor.style_options.style_italic'),
            'align.left'    => __('templates::editor.style_options.align_left'),
            'align.center'  => __('templates::editor.style_options.align_center'),
            'align.right'   => __('templates::editor.style_options.align_right'),
            'align.justify' => __('templates::editor.style_options.align_justify'),
            'border.none'   => __('templates::editor.style_options.border_none'),
            'border.solid'  => __('templates::editor.style_options.border_solid'),
            'border.dashed' => __('templates::editor.style_options.border_dashed'),
            'border.dotted' => __('templates::editor.style_options.border_dotted'),

            'sector.typography' => __('templates::editor.sectors.typography'),
            'sector.box'        => __('templates::editor.sectors.box'),
            'sector.border'     => __('templates::editor.sectors.border'),
            'sector.position'   => __('templates::editor.sectors.position'),

            'action.restore_legal'      => __('templates::editor.actions.restore_legal'),
            'action.restore_legal_none' => __('templates::editor.actions.restore_legal_none'),
            'action.restore_legal_done' => __('templates::editor.actions.restore_legal_done'),
            'action.snap_to_sheet'      => __('templates::editor.actions.snap_to_sheet'),
            'action.snap_to_sheet_done' => __('templates::editor.actions.snap_to_sheet_done'),
            'action.snap_to_sheet_none' => __('templates::editor.actions.snap_to_sheet_none'),

            /*
             * Chrome strings the JavaScript builds at runtime: the counts in the
             * toolbar and the header of the properties panel. Everything the chrome
             * renders once comes straight from Blade instead.
             */
            'chrome.count_fields'   => __('templates::editor.chrome.count_fields', ['count' => ':count']),
            'chrome.count_tables'   => __('templates::editor.chrome.count_tables', ['count' => ':count']),
            'chrome.count_codes'    => __('templates::editor.chrome.count_codes', ['count' => ':count']),
            'chrome.count_problems' => __('templates::editor.chrome.count_problems', ['count' => ':count']),
            'chrome.legal_chip'     => __('templates::editor.chrome.legal_chip'),
            'chrome.scope_any'      => __('templates::editor.chrome.scope_any'),
            'chrome.scope_all'      => __('templates::editor.chrome.scope_all'),
            'chrome.type'           => __('templates::editor.chrome.type'),
            'chrome.nothing_chosen' => __('templates::editor.chrome.nothing_chosen'),

            'problems.heading'   => __('templates::editor.problems.heading'),
            'problems.structure' => __('templates::editor.problems.structure'),
            'problems.legal'     => __('templates::editor.problems.legal'),
            'problems.render'    => __('templates::editor.problems.render'),
            'activating'         => __('templates::editor.editor.activating'),
            'activated'          => __('templates::editor.messages.activated'),
            'activateFailed'     => __('templates::editor.errors.activation_failed'),

            'check.missing'         => __('templates::editor.checks.missing'),
            'check.font_size'       => __('templates::editor.checks.font_size'),
            'check.opacity'         => __('templates::editor.checks.opacity'),
            'check.hidden'          => __('templates::editor.checks.hidden'),
            'check.scope'           => __('templates::editor.checks.scope'),
            'check.off_sheet'       => __('templates::editor.checks.off_sheet'),
            'check.code_incomplete' => __('templates::editor.checks.code_incomplete'),

            'error.image_too_large'  => __('templates::editor.errors.image_too_large'),
            'error.catalogue_failed' => __('templates::editor.errors.catalogue_failed'),

            'saving'         => __('templates::editor.editor.saving'),
            'saved'          => __('templates::editor.messages.saved'),
            'rendering'      => __('templates::editor.editor.rendering'),
            'unsaved'        => __('templates::editor.editor.unsaved'),
            'unsavedWarning' => __('templates::editor.editor.unsaved_warning'),
            'formatSwitchConfirm' => __('templates::editor.editor.format_switch_confirm'),
            'loadFailed'     => __('templates::editor.errors.load_failed'),
            'saveFailed'     => __('templates::editor.errors.save_failed'),
            'previewFailed'  => __('templates::editor.errors.render_failed'),

            'notice.legal_locked' => __('templates::editor.notices.legal_locked'),
            'notice.close'        => __('templates::editor.notices.close'),

            'confirm.delete_legal' => __('templates::editor.notices.confirm_delete_legal'),
            'confirm.occlusion'    => __('templates::editor.notices.confirm_occlusion'),

            // Buttons of the editor's own confirm/alert dialogs (dialog.js).
            'dialog.confirm' => __('templates::editor.dialog.confirm'),
            'dialog.cancel'  => __('templates::editor.dialog.cancel'),
            'dialog.close'   => __('templates::editor.dialog.close'),

            // The sample-data panel (real records, never saved).
            'preview.tab' => __('templates::editor.preview.tab'),
            'preview.intro' => __('templates::editor.preview.intro'),
            'preview.none' => __('templates::editor.preview.none'),
            'preview.unbound' => __('templates::editor.preview.unbound'),
            'preview.example' => __('templates::editor.preview.example'),
            'preview.add_filter' => __('templates::editor.preview.add_filter'),
            'preview.field_placeholder' => __('templates::editor.preview.field_placeholder'),
            'preview.value_placeholder' => __('templates::editor.preview.value_placeholder'),
            'preview.op_contains' => __('templates::editor.preview.op_contains'),
            'preview.op_eq' => __('templates::editor.preview.op_eq'),
            'preview.remove_filter' => __('templates::editor.preview.remove_filter'),
            'preview.loading' => __('templates::editor.preview.loading'),
            'preview.show_more' => __('templates::editor.preview.show_more'),
            'preview.use_example' => __('templates::editor.preview.use_example'),
            'preview.no_results' => __('templates::editor.preview.no_results'),
            'preview.collection_hint' => __('templates::editor.preview.collection_hint'),
            'preview.rows_shown' => __('templates::editor.preview.rows_shown'),
            'preview.lines_badge' => __('templates::editor.preview.lines_badge'),
            'preview.operator' => __('templates::editor.preview.operator'),
            'preview.showing' => __('templates::editor.preview.showing'),
            'preview.error' => __('templates::editor.preview.error'),
        ];

        // The format and rule width labels follow the config, so adding one there does
        // not need a second edit here.
        foreach ((array) config('templates.formats') as $format) {
            $strings['format.'.$format] = __('templates::editor.formats.'.$format);
        }

        foreach (array_keys((array) config('templates.rule_widths')) as $width) {
            $strings['rule_width.'.$width] = __('templates::editor.rule_widths.'.$width);
        }

        // The code source, type and per-type field labels follow the config too, so a
        // new content type needs no second edit here.
        foreach (array_keys((array) __('templates::editor.code_sources')) as $source) {
            $strings['code_source.'.$source] = __('templates::editor.code_sources.'.$source);
        }

        foreach (array_keys((array) config('templates.code_types')) as $type) {
            $strings['code_type.'.$type] = __('templates::editor.code_types.'.$type);
        }

        foreach (array_keys((array) __('templates::editor.code_fields')) as $field) {
            $strings['code_field.'.$field] = __('templates::editor.code_fields.'.$field);
        }

        return $strings;
    }

    /** Header the editor sends with the tenant id, or null when the host declares none. */
    public function tenantHeader(): ?string
    {
        return config('templates.editor.tenant_header');
    }

    public function tenantId(): ?string
    {
        return app(\Controlink\Templates\Contracts\TenantResolver::class)->currentId();
    }

    /**
     * A published asset's URL, cache-busted by its own content hash.
     *
     * `?v=` is the file's own hash rather than a build timestamp or a package version,
     * so an unpublished (or stale) asset is `missing` rather than an old file silently
     * served under a URL that looks current.
     */
    public static function assetUrl(string $file): string
    {
        $path = public_path('vendor/templates/'.$file);
        $hash = is_file($path) ? substr(hash_file('sha256', $path), 0, 12) : 'missing';

        return asset('vendor/templates/'.$file).'?v='.$hash;
    }

    /**
     * The print stylesheet, served raw rather than as a bundled asset: the canvas and
     * the compositor have to read exactly the same bytes.
     */
    public function stylesheetUrl(): string
    {
        return ($this->urls['stylesheet'] ?? '').'?v='.app(\Controlink\Templates\Output\Pdf\DocumentComposer::class)->printStylesheetVersion();
    }

    /**
     * The inline SVG icons the view draws instead of an icon font (no Font Awesome, no
     * host dependency — the same reasoning as the bundle's own `icons.js`).
     *
     * @return array<string, string>
     */
    public function icons(): array
    {
        return [
            'file-pdf' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M3 1.5h6.4L13 5v9.5H3v-13Zm7 .9v2.6h2.6L10 2.4Z"/>'
                .'<rect x="5" y="9" width="6" height="1.2" rx="0.3"/>'
                .'<rect x="5" y="11.3" width="4.5" height="1.2" rx="0.3"/>'
                .'</svg>',

            'floppy-disk' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M2 2h9.2L14 4.8V14H2V2Zm1.5 1.5v3h6v-3h-6ZM4 9.5v3h8v-3H4Z"/>'
                .'<rect x="9.5" y="3.5" width="1.5" height="2.5"/>'
                .'</svg>',

            'circle-check' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13Zm0 1.6a4.9 4.9 0 1 1 0 9.8 4.9 4.9 0 0 1 0-9.8Z"/>'
                .'<path d="M5 8.2 7 10.2 11.2 5.8 12.3 6.9 7 12.4 3.9 9.3Z"/>'
                .'</svg>',

            'lock' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M4.5 6.5V5a3.5 3.5 0 0 1 7 0v1.5H13V15H3V6.5h1.5Zm1.6 0h3.8V5a1.9 1.9 0 0 0-3.8 0v1.5Z"/>'
                .'</svg>',

            'minus' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<rect x="2.5" y="7.2" width="11" height="1.6"/>'
                .'</svg>',

            'plus' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<rect x="2.5" y="7.2" width="11" height="1.6"/>'
                .'<rect x="7.2" y="2.5" width="1.6" height="11"/>'
                .'</svg>',

            'arrows-to-dot' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<circle cx="8" cy="8" r="1.4"/>'
                .'<path d="M1 1h4v1.5H2.5V6H1V1Z"/>'
                .'<path d="M15 1v5h-1.5V2.5H10V1h5Z"/>'
                .'<path d="M1 15v-5h1.5v3.5H6V15H1Z"/>'
                .'<path d="M15 15h-5v-1.5h3.5V10H15v5Z"/>'
                .'</svg>',

            'shield-check' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M8 1 13.5 3v4.2c0 4-2.3 6.6-5.5 7.8C4.8 13.8 2.5 11.2 2.5 7.2V3L8 1Zm0 1.7L4 4.1v3.1c0 3.1 1.6 5.1 4 6.1 2.4-1 4-3 4-6.1V4.1L8 2.7Z"/>'
                .'<path d="M4.9 8.6 6 7.5 6.7 8.2 9.9 5 11 6.1 6.7 10.4Z"/>'
                .'</svg>',

            'table-rows' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M1.5 2.5h13v11h-13v-11Zm1.5 1.5v2.3h10V4H3Zm0 3.8v2.3h10V7.8H3Zm0 3.8v1.9h10v-1.9H3Z"/>'
                .'</svg>',

            'ruler-combined' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M1.5 9.5 9.5 1.5 14.5 6.5 6.5 14.5 1.5 9.5Zm2.3.1 1.1 1.1 1-1-1.1-1.1-1 1Zm2.3-2.3 1.1 1.1 1-1-1.1-1.1-1 1Zm2.3-2.3 1.1 1.1 1-1L9.3 3.9l-1 1Z"/>'
                .'</svg>',

            'eye' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M8 3C4.5 3 1.7 5.2.5 8c1.2 2.8 4 5 7.5 5s6.3-2.2 7.5-5C14.3 5.2 11.5 3 8 3Zm0 8.2A3.2 3.2 0 1 1 8 4.8a3.2 3.2 0 0 1 0 6.4Z"/>'
                .'<circle cx="8" cy="8" r="1.6"/>'
                .'</svg>',

            'shield-exclamation' => '<svg viewBox="0 0 16 16" width="1em" height="1em" fill="currentColor" aria-hidden="true">'
                .'<path fill-rule="evenodd" clip-rule="evenodd" d="M8 1 13.5 3v4.2c0 4-2.3 6.6-5.5 7.8C4.8 13.8 2.5 11.2 2.5 7.2V3L8 1Zm0 1.7L4 4.1v3.1c0 3.1 1.6 5.1 4 6.1 2.4-1 4-3 4-6.1V4.1L8 2.7Z"/>'
                .'<rect x="7.3" y="4.6" width="1.4" height="3.4" rx="0.4"/>'
                .'<rect x="7.3" y="8.8" width="1.4" height="1.4" rx="0.3"/>'
                .'</svg>',
        ];
    }

    /**
     * The page formats the editor offers, from `config('templates.page_formats')`.
     * Falls back to a single A4 sheet so the editor works without any configuration.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pageFormats(): array
    {
        $formats = array_values((array) config('templates.page_formats', []));

        // A type offers only the formats it allows (per-type allow-list); a type that
        // declares none falls back to the whole registry.
        $allowed = config('templates.types.'.$this->template->type_code.'.formats');

        if (is_array($allowed) && $allowed !== []) {
            $allowed  = array_map('strval', $allowed);
            $formats  = array_values(array_filter(
                $formats,
                static fn (array $format): bool => in_array((string) $format['id'], $allowed, true),
            ));
        }

        return $formats !== []
            ? $formats
            : [['id' => 'A4', 'label' => 'A4', 'medium' => 'sheet', 'paper_format' => 'A4']];
    }

    /** The current version's geometry, or null when it has none. */
    public function geometry(): ?array
    {
        $geometry = $this->version->geometry ?? null;

        return is_array($geometry) && $geometry !== [] ? $geometry : null;
    }

    /** Whether the version can no longer be edited. */
    public function locked(): bool
    {
        return ! $this->version->isEditable();
    }

    public function render(): View|Closure|string
    {
        return view('templates::components.editor');
    }
}
