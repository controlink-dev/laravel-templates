@php
    $type = $type();
    $typeface = $font();
    $readOnly = ! $version->isEditable();
    $icons = $icons();
@endphp

{{--
    The widget's root. Everything the JavaScript looks up is looked up inside it, never
    in the document: embedded, the actions sit in a host header, two editors could share
    a screen, and a workspace pane keeps the previous screen's markup around until it is
    replaced — a document-wide query would find the wrong button in all three cases.
--}}
<div class="tpl-editor-root" data-tpl-root>

    {{--
        One notice, above the bar: why this version cannot be edited, and whatever the
        host has to add (a fiscal warning, say) in the `notice` slot. A host used to draw
        its own banner above ours; two stacked warnings read as two different problems.
    --}}
    @if($readOnly || isset($notice))
        <div class="tpl-editor-notice" role="status">
            <span class="tpl-editor-notice__icon">{!! $icons['lock'] !!}</span>
            <div class="tpl-editor-notice__text">
                @if($readOnly)
                    <strong class="tpl-editor-notice__title">{{ __('templates::editor.editor.read_only') }}</strong>
                @endif
                @isset($notice)
                    <div>{{ $notice }}</div>
                @endisset
            </div>
        </div>
    @endif

    @php
        $bar = $actions || isset($heading) || isset($center);
        // The package's own format selector goes in the bar's centre unless the host put
        // its own controls there; without a bar it stays in the toolbar.
        $selectorInBar = $formatSelector && $bar && ! isset($center);
    @endphp

    @if($bar)
        {{--
            One row, three zones: who (the `heading` slot), which sheet (the `center`
            slot, or our format selector) and what to do with it (status and actions).
            The outer columns share the rest equally so the centre stays centred whatever
            either side holds.
        --}}
        <div class="tpl-editor-actions">
            <div class="tpl-editor-zone tpl-editor-zone--start">
                @isset($heading)
                    <div class="tpl-editor-heading">{{ $heading }}</div>
                @endisset
            </div>

            <div class="tpl-editor-zone tpl-editor-zone--center">
                @isset($center)
                    {{ $center }}
                @elseif($selectorInBar)
                    <div class="tpl-tools">
                        <select class="tpl-tool tpl-tool--format" data-tpl-format
                                aria-label="{{ __('templates::editor.chrome.page_format') }}"></select>
                    </div>
                @endisset
            </div>

            <div class="tpl-editor-zone tpl-editor-zone--end">
                <span data-tpl-status class="tpl-editor-status"></span>

                @if($readOnly)
                    <button type="button" class="tpl-btn tpl-btn--outline-info" data-tpl-preview="1">
                        {!! $icons['eye'] !!} {{ __('templates::editor.editor.preview') }}
                    </button>
                @else
                    <button type="button" class="tpl-btn tpl-btn--outline-primary" data-tpl-save="1">
                        {!! $icons['floppy-disk'] !!} {{ __('templates::editor.editor.save') }}
                    </button>

                    {{-- Wired by `menu.js`, delegated from the root: the hooks inside work
                         exactly as they did as plain buttons. --}}
                    <div class="tpl-menu" data-tpl-menu>
                        <button type="button" class="tpl-menu__toggle" data-tpl-menu-toggle
                                aria-haspopup="menu" aria-expanded="false"
                                aria-label="{{ __('templates::editor.editor.more_actions') }}">
                            {!! $icons['ellipsis'] !!}
                        </button>

                        <div class="tpl-menu__list" role="menu" hidden>
                            <button type="button" role="menuitem" tabindex="-1"
                                    class="tpl-menu__item tpl-menu__item--info" data-tpl-preview="1">
                                <span class="tpl-menu__icon">{!! $icons['eye'] !!}</span>
                                <span class="tpl-menu__text">
                                    <span class="tpl-menu__name">{{ __('templates::editor.editor.preview') }}</span>
                                    <span class="tpl-menu__hint">{{ __('templates::editor.editor.preview_hint') }}</span>
                                </span>
                            </button>

                            {{-- Activation is a permission of its own: drawing a layout and
                                 binding the tenant to it are different decisions. --}}
                            @can('activate', $template)
                                <div class="tpl-menu__separator" role="separator"></div>

                                <button type="button" role="menuitem" tabindex="-1"
                                        class="tpl-menu__item tpl-menu__item--primary" data-tpl-activate="1">
                                    <span class="tpl-menu__icon">{!! $icons['circle-check'] !!}</span>
                                    <span class="tpl-menu__text">
                                        <span class="tpl-menu__name">{{ __('templates::editor.editor.activate_version') }}</span>
                                        <span class="tpl-menu__hint">{{ __('templates::editor.editor.activate_hint') }}</span>
                                    </span>
                                </button>
                            @endcan
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{--
        The editor chrome, and why it is ours rather than the canvas library's.

        The canvas library renders its own shell: a dark panel strip, icon buttons and a
        Desktop/Tablet/Mobile device selector. On a sheet of A4 the device selector means
        nothing — the paper is 210mm wide whatever the screen — and the dark strip
        belongs to no part of a host application. So the shell is switched off
        (`panels: { defaults: [] }`) and built here instead, with plain markup, while the
        canvas library renders its managers into the containers below through `appendTo`.
    --}}
    <div class="tpl-toolbar">
        <div class="tpl-tools">
            <button type="button" class="tpl-tool" data-tpl-command="zoom-out"
                    title="{{ __('templates::editor.chrome.zoom_out') }}">
                {!! $icons['minus'] !!}
            </button>
            {{-- Typed as well as stepped: the number is right there, and two clicks of
                 25% to get from 52% to 100% is two clicks too many. --}}
            <input
                type="text"
                class="tpl-tool tpl-tool--zoom"
                data-tpl-zoom
                inputmode="numeric"
                aria-label="{{ __('templates::editor.chrome.zoom') }}"
                value="&mdash;"
            >
            <button type="button" class="tpl-tool" data-tpl-command="zoom-in"
                    title="{{ __('templates::editor.chrome.zoom_in') }}">
                {!! $icons['plus'] !!}
            </button>
            <button type="button" class="tpl-tool" data-tpl-command="zoom-fit"
                    title="{{ __('templates::editor.chrome.fit') }}">
                {!! $icons['arrows-to-dot'] !!}
            </button>
        </div>

        {{-- The page format, filled by the editor script from `data-formats` and
             `data-geometry`. A host that switches format from its own page chrome (format
             tabs hitting `urls['format_switch']`) passes `:format-selector="false"` so the
             two controls never both show. --}}
        @if($formatSelector && ! $selectorInBar)
            <div class="tpl-tools">
                <select class="tpl-tool tpl-tool--format" data-tpl-format
                        aria-label="{{ __('templates::editor.chrome.page_format') }}"></select>
            </div>
        @endif

        {{-- Three ways of looking at the same sheet. Each is a class on the root, so
             none of it can reach the PDF. --}}
        <div class="tpl-tools">
            {{-- Only where the type has legal fields to show (CGE-87); the same for "Restore" below. --}}
            @if($type->hasLegalFields())
                <button type="button" class="tpl-tool" data-tpl-toggle="legal"
                        title="{{ __('templates::editor.chrome.legal_hint') }}">
                    {!! $icons['shield-check'] !!} {{ __('templates::editor.chrome.legal_fields') }}
                </button>
            @endif
            <button type="button" class="tpl-tool is-on" data-tpl-toggle="zones"
                    title="{{ __('templates::editor.chrome.zones_hint') }}">
                {!! $icons['table-rows'] !!} {{ __('templates::editor.chrome.zones') }}
            </button>
            <button type="button" class="tpl-tool is-on" data-tpl-toggle="rulers"
                    title="{{ __('templates::editor.chrome.rulers_hint') }}">
                {!! $icons['ruler-combined'] !!} {{ __('templates::editor.chrome.rulers') }}
            </button>
        </div>

        <div class="tpl-tools">
            <button type="button" class="tpl-tool" data-tpl-toggle="clean"
                    title="{{ __('templates::editor.chrome.clean_hint') }}">
                {!! $icons['eye'] !!} {{ __('templates::editor.chrome.clean_view') }}
            </button>
        </div>

        @unless($readOnly)
            <div class="tpl-tools">
                @if($type->hasLegalFields())
                    <button type="button" class="tpl-tool" data-tpl-command="restore-legal">
                        {!! $icons['shield-exclamation'] !!} {{ __('templates::editor.actions.restore_legal') }}
                    </button>
                @endif
                <button type="button" class="tpl-tool" data-tpl-command="snap-to-sheet"
                        title="{{ __('templates::editor.actions.snap_to_sheet') }}">
                    {!! $icons['arrows-to-dot'] !!} {{ __('templates::editor.actions.snap_to_sheet') }}
                </button>
            </div>
        @endunless

        <span class="tpl-toolbar-gap"></span>

        <span class="tpl-counts" data-tpl-counts></span>
    </div>

    <div class="tpl-body">
        {{--
            The stage: the canvas plus the notice that floats over it.

            The notice cannot live inside `.tpl-canvas` itself — the canvas is the
            scroll container (a sheet bigger than the box is reached by scrolling, not
            clipped), and an absolutely-positioned descendant of a scrolling positioned
            ancestor scrolls away with its content instead of staying put. It also
            cannot be centred against `.tpl-body` as a whole: that row also holds the
            blocks rail and the properties panel, two fixed-width columns, so centring
            against the row put the notice visibly off-centre from the sheet and, on a
            narrow pane, on top of the rail. The stage is exactly the canvas's own box
            and nothing else, so centring against it is centring over the drawing area.
        --}}
        <div class="tpl-stage">
            {{-- Drawn here rather than a browser `alert()` — a native dialog blocks
                 everything, including the editor underneath — and it cannot be a host's
                 own alert component either, since this bundle loads none of a host's
                 JavaScript. --}}
            <div class="tpl-notice" data-tpl-notice hidden role="status" aria-live="polite"></div>

            {{--
                The canvas, with a ruler along the top and down the left side.

                Everything in a template is positioned in millimetres — margins at 15,
                the header band at 55, a QR code with a 30mm side — and without a visible
                scale the user drags blind and then reads the number off the panel. The
                rulers also project the chosen block's extent, which is the question
                that actually gets asked: does this fit?
            --}}
            <div class="tpl-canvas">
                <div class="tpl-paper">
                    <div class="tpl-ruler-corner"></div>
                    <div class="tpl-ruler tpl-ruler--top" data-tpl-ruler="top"></div>
                    <div class="tpl-ruler tpl-ruler--left" data-tpl-ruler="left"></div>

                    {{-- The editor mounts here and nowhere else. Every setting arrives
                         through `data-*`, never a global. --}}
                    <div
                        data-tpl-editor
                        style="height: {{ $height }}"
        data-load-url="{{ $urls['load'] ?? '' }}"
        data-save-url="{{ $urls['save'] ?? '' }}"
        data-preview-url="{{ $urls['preview'] ?? '' }}"
        data-activate-url="{{ $urls['activate'] ?? '' }}"
        data-catalogue-url="{{ $urls['catalogue'] ?? '' }}"
        {{-- In-place format switch: the editor replaces `__FORMAT__` with the chosen
             format id, fetches that format's own version and rewires itself, never
             navigating. Empty unless the host drives format switching from its own
             chrome; the editor leaves the feature off when it is. --}}
        data-format-switch-url="{{ $urls['format_switch'] ?? '' }}"

        {{-- Labels (CGE-98): the context node the Amostra picks several records for, one
             label each, and the endpoint that downloads them as ZPL. Both empty on every
             other type; the editor then keeps one record per node and no ZPL button. --}}
        data-batch-node="{{ $batchNode ?? '' }}"
        data-zpl-url="{{ $urls['zpl'] ?? '' }}"
        {{-- The thermal receipt (CGE-98), downloaded as a .bin. The editor shows its
             button only while the format on screen is a roll. --}}
        data-escpos-url="{{ $urls['escpos'] ?? '' }}"

        {{-- Served raw, not through the bundle, so the canvas and the PDF engine read
             the same bytes of the same stylesheet. --}}
        data-print-stylesheet="{{ $stylesheetUrl() }}"

        data-font-family="{{ $typeface['family'] }}"
        {{-- One entry per weight: a weight the canvas synthesises is a weight the PDF
             renders differently. --}}
        data-font-faces="{{ json_encode($typeface['faces']) }}"
        data-paper-format="{{ $type->pdf['paper_format'] ?? 'A4' }}"
        data-formats="{{ json_encode($pageFormats()) }}"
        data-geometry="{{ $geometry() ? json_encode($geometry()) : '' }}"
        data-locked="{{ $locked() ? '1' : '' }}"
        data-margins="{{ json_encode($type->pdf['margins'] ?? []) }}"
        data-editable="{{ $readOnly ? '0' : '1' }}"
        data-initial-html="{{ $version->html ?? '<div class=&quot;page&quot;></div>' }}"
        data-initial-css="{{ $version->css ?? '' }}"
        data-strings="{{ json_encode($strings()) }}"
        data-tenant-header="{{ $tenantHeader() }}"
        data-tenant="{{ $tenantId() }}"

        {{-- When the host page listens for the editor's `templates:message` events and
             shows the outcome in its own style, it passes `:host-notices="true"` and the
             editor stops writing those messages into its own status line. Off unless the
             host opts in, so an embedder with no listener keeps seeing them here. --}}
        data-host-notices="{{ $hostNotices ? '1' : '' }}"

        {{-- Read straight off the container rather than a document-wide
             `meta[name="csrf-token"]` lookup: the bundle loads none of the host's
             JavaScript or layout, so it cannot depend on the host's own page
             emitting that meta tag — every setting it needs, this one included,
             has to travel as data on the container. The lookup stays as a
             fallback for a host that already has one. --}}
        data-csrf-token="{{ csrf_token() }}"
                    ></div>
                </div>
            </div>
        </div>

        {{-- Blocks next to the canvas, not across the panel from it: a block is dragged
             onto the sheet, and that drag should be short. --}}
        <aside class="tpl-rail">
            <div class="tpl-rail-head">{{ __('templates::editor.chrome.blocks') }}</div>
            <div class="tpl-rail-body" data-tpl-blocks></div>
        </aside>

        <aside class="tpl-panel">
            <div class="navigation-tabs">
                <div class="navigation-tabs-list" role="tablist">
                    <button type="button" class="navigation-tabs-tab is-active" data-tpl-tab="style">
                        {{ __('templates::editor.chrome.style') }}
                    </button>
                    <button type="button" class="navigation-tabs-tab" data-tpl-tab="field">
                        {{ __('templates::editor.chrome.field') }}
                    </button>
                    <button type="button" class="navigation-tabs-tab" data-tpl-tab="layers">
                        {{ __('templates::editor.chrome.layers') }}
                    </button>
                </div>
            </div>

            {{-- Which block is being edited, and under which rules: the field's name and
                 whether the law is holding it. --}}
            <div class="tpl-selected" data-tpl-selected>
                <p class="tpl-selected-empty">{{ __('templates::editor.chrome.nothing_chosen') }}</p>
            </div>

            <div class="tpl-panel-scroll" data-tpl-panel="style" data-tpl-styles></div>
            <div class="tpl-panel-scroll" data-tpl-panel="field" data-tpl-traits hidden></div>
            <div class="tpl-panel-scroll" data-tpl-panel="layers" data-tpl-layers hidden></div>
        </aside>
    </div>

    {{-- Validation panel. Hidden while there is nothing to say: an always-visible empty
         panel trains people to stop looking at it. --}}
    <aside class="tpl-problems" data-tpl-problems aria-live="polite" hidden></aside>

    {{--
        Unsaved work, announced the way a host announces it.

        A host reads this marker for its own tab-close prompt and for the browser's own
        reload warning. The editor is not a form, so a host's own dirty-form marker would
        never match it — and `beforeunload` alone would cover only a standalone page,
        because closing a pane tab is not a navigation.
    --}}
    <span class="tpl-editor-dirty" data-templates-dirty hidden>
        {{ __('templates::editor.editor.unsaved') }}
    </span>
</div>

@once
    <link rel="stylesheet" href="{{ \Controlink\Templates\View\Components\Editor::assetUrl('editor.css') }}">
    <script type="module" src="{{ \Controlink\Templates\View\Components\Editor::assetUrl('editor.js') }}"></script>
@endonce
