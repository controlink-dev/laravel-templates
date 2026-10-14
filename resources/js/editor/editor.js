import '../../css/editor.css';

/* ============================================================================
 * Template editor.
 *
 * Self contained on purpose: this page does not load jQuery, Bootstrap or any host
 * global, so nothing here may depend on them (RF-E03). Every piece of configuration
 * arrives through `data-*` attributes on the container, never through a global
 * (RF-E02), and the interface strings arrive already translated from Blade, because
 * this bundle has no access to the translation layer.
 * ========================================================================= */

import grapesjs from 'grapesjs';
import grapesjsMjml from 'grapesjs-mjml';
import { registerComponents } from './components.js';
import { registerTableAndSlot } from './table.js';
import { icons } from './icons.js';
import {
    checkLegalFieldsLocally,
    restoreMissingLegalFields,
    styleManagerSectors,
} from './panels.js';
import { mergeProblems } from './problems.js';
import { confirmDialog } from './dialog.js';

/*
 * Mounting, and the four things that made a workspace pane hostile.
 *
 * A pane injects its content as innerHTML and re-executes every `<script>` on each
 * load, and switching tabs replaces that content without telling anyone. So:
 *
 * - this module can run more than once, hence the mount flag on the container;
 * - the container can be torn out from under the editor, hence the observer that
 *   destroys the instance when it goes — without it GrapesJS leaks its canvas iframe
 *   and every listener it registered;
 * - `beforeunload` never fires, because closing a tab is not a navigation, hence the
 *   `[data-templates-dirty]` marker that hooks into the workspace's own prompt;
 * - and the editor's stylesheet is a Vite entry of its own, so nothing of it reaches
 *   the rest of the application (RF-E03).
 *
 * Being a module also means the browser will not re-evaluate this file on a second
 * pane load — the same trap `families.js` documents — so mounting is driven by an
 * observer rather than by top-level code alone.
 */
function mountAll() {
    document.querySelectorAll('[data-tpl-editor]').forEach((element) => {
        if (element.dataset.tplMounted === '1') {
            return;
        }

        element.dataset.tplMounted = '1';

        mountEditor(element).catch((error) => {
            renderStatus(error.message, element);
        });
    });
}

mountAll();

// A pane loaded after this module was first evaluated, or a `templates:refresh`.
document.addEventListener('templates:refresh', mountAll);

new MutationObserver(mountAll).observe(document.body, { childList: true, subtree: true });

async function mountEditor(el) {
    /*
     * Everything is looked up inside the widget's own root, never in the document.
     *
     * On a page of its own that made no difference; embedded it does. The actions live
     * in the host page's header, two editors could share a screen, and a pane keeps the
     * previous screen's markup around until it is replaced — a `document.querySelector`
     * would find the wrong button in all three cases.
     */
    const root = el.closest('[data-tpl-root]') ?? el.parentElement ?? el;
    const config = readConfig(el);

    config.root = root;

    // The catalogue has to be in hand before the editor mounts: the data field's
    // binding select is built from it, and a component type cannot be redefined once
    // registered.
    Object.assign(config, await loadCatalogue(config));

    /*
     * The canvas is handed two stylesheets: the very print stylesheet the PDF engine
     * is given (RF-C02), and a generated one carrying the @font-face.
     *
     * The font face is kept out of the project stylesheet on purpose. If it lived in
     * the project it would be exported into `project_data`, and the layout would then
     * carry a URL the engine cannot fetch — the engine has no network. Instead each
     * side declares the same font its own way: a URL here, a data URI in the
     * compositor.
     */
    // Every container the chrome offers, resolved once and inside the widget's root.
    const chrome = {
        // The mount itself: `setZoom` gives it an explicit height so the container
        // around it can scroll a sheet bigger than the box.
        mount:    el,
        toolbar:  root.querySelector('.tpl-toolbar'),
        canvas:   root.querySelector('.tpl-canvas'),
        blocks:   root.querySelector('[data-tpl-blocks]'),
        styles:   root.querySelector('[data-tpl-styles]'),
        traits:   root.querySelector('[data-tpl-traits]'),
        layers:   root.querySelector('[data-tpl-layers]'),
        selected: root.querySelector('[data-tpl-selected]'),
        counts:   root.querySelector('[data-tpl-counts]'),
        zoom:     root.querySelector('[data-tpl-zoom]'),
        rulers:   root.querySelectorAll('[data-tpl-ruler]'),
    };

    // Email is a third medium: no page, no geometry — designed in MJML, output is HTML.
    // It takes its own init path and shares none of the page editor's setup.
    if (config.geometry?.medium === 'email') {
        mountEmailEditor(el, config, chrome);

        return;
    }

    // The sheet's own size, which the canvas document and the chrome both need.
    // A mutable object on purpose: a format change rewrites it in place, and the
    // rulers, the zoom-fit and the canvas all read it.
    const paper = { ...resolvePaper(config.geometry, config.paperFormat) };

    config.paper = paper;
    applyPaperVars(root, paper);

    /*
     * The tenant colour has to be handed to the canvas explicitly: the iframe is a
     * document of its own and inherits no custom property from the page around it.
     */
    const styles = getComputedStyle(document.documentElement);
    const accent = styles.getPropertyValue('--tpl-primary').trim() || '#6d28d9';

    // The surface the sheet sits on. Without it the area beside the paper is the
    // iframe's own white and the edge of the page disappears.
    const desk = styles.getPropertyValue('--tpl-surface-sunken').trim() || '#edf1f6';

    /*
     * `print.css` is a fixed, host-agnostic asset and ships with a placeholder family
     * (spec: `templates.fonts`). This rule is injected after it in the `canvas.styles`
     * array below, so the later declaration wins and the canvas shows the same family
     * the compositor embeds in the PDF (`DocumentComposer::pageVariables()`), not the
     * placeholder.
     */
    const fontFace = config.fontFaces
        .map((face) => `@font-face{font-family:"${config.fontFamily}";font-style:normal;`
            + `font-weight:${face.weight};src:url(${face.url}) format("${face.format}")}`)
        .join('') + `.page{font-family:"${config.fontFamily}",sans-serif}`;

    // A roll (talão) is a flowing receipt, not a fixed sheet: blocks stack and the text
    // wraps, so it is edited in GrapesJS's native flow mode (the sorter reorders blocks in
    // the tree) rather than the absolute drag the A4 sheet uses.
    const rollMode = config.geometry?.medium === 'roll';

    const editor = grapesjs.init({
        container: el,
        height: '100%',
        fromElement: false,
        // The template never touches browser storage (spec 3.2).
        storageManager: false,
        // A4/A5 place blocks by coordinate (absolute); a roll flows (empty dragMode = the
        // native sorter, which reorders instead of free-positioning).
        dragMode: rollMode ? '' : 'absolute',
        canvas: {
            styles: [
                config.printStylesheet,
                'data:text/css;charset=utf-8,' + encodeURIComponent(fontFace),
            ],
        },

        /*
         * GrapesJS's own shell is off, and the managers render into the chrome the
         * Blade component built.
         *
         * `panels: { defaults: [] }` is what does it. What it removes: a dark strip
         * that matches nothing in this application, eight buttons labelled with Font
         * Awesome 4 class names, and a Desktop/Tablet/Mobile device selector that on a
         * sheet of A4 means nothing at all — the paper is 210mm wide whatever the
         * screen. What stays is every manager, each one drawn inside our own container.
         */
        panels: { defaults: [] },

        /*
         * One device, and it carries no width.
         *
         * A device with a width is a breakpoint to GrapesJS: every style edited while it
         * is active is written inside `@media (max-width: …)`. Sizing the frame that way
         * looked right and quietly broke editing — measured, changing a field's size
         * wrote `@media (max-width: 210mm){#tpl-emitente-nome{font-size:20pt}}`, which
         * the layout's own unmediated rule then beat, so nothing appeared to happen. A
         * template has one page size and must carry no media queries at all.
         *
         * The frame is sized by the stylesheet instead, from `--tpl-paper-*`.
         */
        deviceManager: {
            default: 'tpl-paper',
            devices: [{ id: 'tpl-paper', name: config.paperFormat ?? 'A4', width: '' }],
        },
        // The editing decorations, delivered to the canvas document itself. See
        // `canvasDecorations()` for why they cannot live in `editor.css`.
        canvasCss: `:root{--tpl-accent:${accent};--tpl-desk:${desk}}` + canvasDecorations(paper, rollMode),
        blockManager: { appendTo: chrome.blocks },
        // Only the reduced set (spec 6.6): no flex, no grid, no transitions.
        styleManager: { appendTo: chrome.styles, sectors: styleManagerSectors(config.strings) },
        traitManager: { appendTo: chrome.traits },
        // No wrapper row: "Body" is not a layer of a printed document.
        layerManager: { appendTo: chrome.layers, showWrapper: false },
        // No font family selector: the catalogue holds exactly one family, and a
        // family the server has no file for would break every truncation measurement.
        selectorManager: { componentFirst: true },
    });

    /*
     * Types first, content second, and the order is not cosmetic. GrapesJS resolves a
     * component's type while parsing, so markup parsed before `addType` runs becomes a
     * generic component: the data fields would carry the right attributes and none of
     * the behaviour — no preview text, no legal locking, and `toHTML()` never emitting
     * the template expression. Passing `components` to `init()` parses too early.
     */
    registerComponents(editor, config);
    registerTableAndSlot(editor, config);

    // Declared before the chrome is wired and filled in below: the format selector lives in
    // `wireChrome`, a different scope from the load/dirty tracking.
    const layoutState = { pristine: null, refresh: () => {} };

    const chromeApi = wireChrome(editor, config, chrome, paper, layoutState);

    /*
     * With the panels off, GrapesJS's "view code" button is gone with them — which
     * CA-01 wanted anyway: it showed the exported HTML and CSS, template expressions
     * included, and the interface must never put template syntax in front of the user.
     */

    // Page geometry first, then the document type's own default positions, so the
    // latter can override the former rather than be wiped by it.
    editor.Css.addRules(pageVariables(config));

    if (config.initialCss) {
        editor.Css.addRules(config.initialCss);
    }

    editor.setComponents(config.initialHtml);

    // Now that the document is in place, and again for every field added afterwards.
    applyZoneIdentity(editor, config);
    lockLegalFields(editor);
    // Remove as well as add: deleting a redundant duplicate of a legal field must
    // re-lock the one that stays, or the last copy would be left deletable too.
    editor.on('component:add component:remove', () => lockLegalFields(editor));

    if (!config.editable) {
        // A convenience, not a guarantee: the server refuses to write an immutable
        // version whatever the browser does (spec 3.2, last bullet).
        editor.getModel().set('dmode', 'off');
    }

    /*
     * RF-E06, and the only shape that works in both containers.
     *
     * On a page of its own, `beforeunload` was enough. In a workspace pane it never
     * fires — closing a tab is not a navigation — so the editor instead announces its
     * state through the `[data-templates-dirty]` marker, which a host can read for its
     * own tab-close prompt AND for the browser's own reload warning, and through the
     * `templates:dirty` event, for a host that prefers to listen rather than poll a
     * marker. One mechanism, both containers, and no second copy of the confirmation
     * dialog.
     */
    const marker = root.querySelector('[data-templates-dirty]');

    const announce = (isDirty) => {
        if (marker) {
            marker.hidden = !isDirty;
        }

        root.dispatchEvent(new CustomEvent('templates:dirty', { detail: { dirty: isDirty }, bubbles: true }));
    };

    announce(false);

    let savedSnapshot = null;
    let dirty = false;

    // The canvas as the editor itself last laid it out (the type's default, or a format's
    // starting layout). While the canvas still equals it there is no work to lose; null
    // means a saved version was loaded, which always counts as content.
    // Shared with `wireChrome`, whose format selector reads and resets it.
    layoutState.pristine = null;

    /*
     * Coalesced, not merely guarded.
     *
     * Two things forced this shape. Rendering the panel marks the offending blocks,
     * and marking a block is a component change — which is what triggers the check, so
     * a bare recursive call froze the renderer. But simply dropping re-entrant calls
     * was worse than it looked: moving a block between zones is a remove followed by
     * an add, so the check ran while the block was detached, reported it as missing,
     * and the call that would have corrected that was the one being dropped. The panel
     * then sat there accusing the user of deleting a field they had just moved.
     *
     * Coalescing solves both: every change schedules one trailing pass, and because
     * the panel only writes markers that actually differ, that pass finds nothing to
     * change and the editor settles.
     */
    let checkHandle = null;

    /*
     * Problems the server refused the last save with (spec 8.1).
     *
     * They are kept beside the local ones rather than instead of them: the server sees
     * rules the browser cannot decide — contrast against an effective background,
     * occlusion, structure — and dropping its verdict on the next keystroke would hide
     * the only reason the layout will not save.
     *
     * But only for codes the browser does NOT decide itself: `mergeProblems` drops server
     * copies of the locally-decidable codes (V-10/11/13/16), because the live check is
     * authoritative for those. Keeping them listed the same problem twice while the field
     * was bad, and left a phantom entry after the user fixed it.
     */
    let serverProblems = [];

    let destroyed = false;

    const refreshChecks = () => {
        if (checkHandle !== null) {
            clearTimeout(checkHandle);
        }

        checkHandle = setTimeout(() => {
            checkHandle = null;

            /*
             * A pane can be torn out between scheduling this and running it, and the
             * editor is gone by then. Found the hard way: closing a pane threw
             * "Cannot read properties of undefined (reading 'getWrapper')" from a check
             * queued moments earlier — harmless to the user, but an uncaught error in
             * the console is how real ones get overlooked.
             */
            if (destroyed || !el.isConnected) {
                return;
            }

            const local = config.editable ? checkLegalFieldsLocally(editor, config) : [];
            const problems = mergeProblems(serverProblems, local);

            renderProblems(editor, config, problems);
            chromeApi.sync(problems.length);
        }, 50);
    };

    layoutState.refresh = refreshChecks;

    // The format switch and the custom-size control live in `wireChrome`, another scope,
    // but must drive this one dirty state: the editor tracks dirtiness by snapshot, which
    // a geometry change (size, format) does not touch, so they signal it explicitly.
    layoutState.isDirty = () => dirty;

    layoutState.markDirty = () => {
        dirty = true;
        announce(true);
        renderStatus(config.strings.unsaved, root);
    };

    layoutState.resetSaved = () => {
        savedSnapshot = snapshot(editor);
        dirty = false;
        announce(false);
        // Server problems belong to the version that produced them. A new clean baseline —
        // which is only ever a format/version switch (the sole callers of resetSaved) — must
        // not keep showing the previous format's server-side problems. The local checks re-run
        // through refreshChecks below, and the next save of this format re-populates the set.
        serverProblems = [];
        renderStatus('', root);
        refreshChecks();
    };

    layoutState.save = () => saveVersion(editor, config);

    editor.on('load', async () => {
        const loadedSaved = await loadVersion(editor, config);
        await editor.Canvas.getDocument().fonts.ready;

        // The document is in place: the chrome can measure it now.
        chromeApi.attach();

        /*
         * The baseline is taken after everything the editor does to the loaded
         * document, not before.
         *
         * Opening a version normalises it: the bands get their names and their locks,
         * the legal fields get theirs, a preview text that used to be a child component
         * becomes content. None of that is the user's work, and taking the snapshot
         * first made every version announce unsaved changes the moment it opened — which
         * is how a warning stops being read.
         */
        savedSnapshot = snapshot(editor);
        layoutState.pristine = loadedSaved ? null : savedSnapshot;
        dirty = false;
        announce(false);

        refreshChecks();

        // `storage:*` never fires with the storage manager off, so change tracking has
        // to hang off the component and style events instead.
        // `component:styleUpdate` and not `style:update`: the latter is not an event
        // GrapesJS emits, so with it the style-based checks (font size, opacity,
        // hidden) never ran and the panel simply never mentioned them.
        editor.on('component:update component:styleUpdate component:add component:remove', () => {
            dirty = snapshot(editor) !== savedSnapshot;
            announce(dirty);
            renderStatus(dirty ? config.strings.unsaved : '', root);
            refreshChecks();
        });

        root.dispatchEvent(new CustomEvent('templates:loaded', { bubbles: true }));
    });

    root.querySelector('[data-tpl-save]')?.addEventListener('click', async () => {
        renderStatus(config.strings.saving, root);

        try {
            await saveVersion(editor, config);

            savedSnapshot = snapshot(editor);
            dirty = false;
            announce(false);
            serverProblems = [];
            refreshChecks();

            renderStatus(config.strings.saved, root);
            root.dispatchEvent(new CustomEvent('templates:saved', { bubbles: true }));
        } catch (error) {
            serverProblems = error.problems ?? [];
            refreshChecks();

            renderStatus(error.message, root);
            root.dispatchEvent(new CustomEvent('templates:validation-failed', {
                detail: { problems: serverProblems },
                bubbles: true,
            }));
        }
    });

    root.querySelector('[data-tpl-preview]')?.addEventListener('click', async () => {
        renderStatus(config.strings.rendering, root);

        try {
            /*
             * The preview renders what is stored, not what is on screen, so unsaved
             * work is saved first. Rendering the browser's copy instead would mean
             * previewing a document that never passed through the server's
             * sanitisation, and showing the user something the stored version would
             * not produce.
             */
            if (dirty && config.editable) {
                await saveVersion(editor, config);

                savedSnapshot = snapshot(editor);
                dirty = false;
                announce(false);
            }

            const url = await renderPreview(config);

            window.open(url, '_blank');
            renderStatus('', root);
            root.dispatchEvent(new CustomEvent('templates:preview-ready', { detail: { url }, bubbles: true }));
        } catch (error) {
            renderStatus(error.message, root);
        }
    });

    root.querySelector('[data-tpl-activate]')?.addEventListener('click', async () => {
        renderStatus(config.strings.activating, root);

        try {
            /*
             * Save first, always. Activation validates the STORED version by rendering
             * it, so activating with unsaved changes would put a layout into production
             * that is not the one on screen.
             */
            if (dirty) {
                await saveVersion(editor, config);

                savedSnapshot = snapshot(editor);
                dirty = false;
                announce(false);
            }

            await activateVersion(config);

            serverProblems = [];
            refreshChecks();
            renderStatus(config.strings.activated, root);
            root.dispatchEvent(new CustomEvent('templates:activated', { bubbles: true }));
        } catch (error) {
            serverProblems = error.problems ?? [];
            refreshChecks();
            renderStatus(error.message, root);
        }
    });


    /*
     * Tear the editor down when its container leaves the DOM.
     *
     * A pane replaces its content wholesale and tells nobody. Without this, GrapesJS
     * keeps its canvas iframe and every listener it registered, and the next pane load
     * mounts a second instance on top of the first.
     */
    const teardown = new MutationObserver(() => {
        if (el.isConnected) {
            return;
        }

        teardown.disconnect();
        destroyed = true;
        announce(false);

        if (checkHandle !== null) {
            clearTimeout(checkHandle);
            checkHandle = null;
        }

        try {
            editor.destroy();
        } catch {
            // Already gone: nothing to undo, and throwing here would break whatever
            // removed the pane.
        }
    });

    teardown.observe(document.body, { childList: true, subtree: true });

    return { editor, announce };
}

/* ============================================================================
 * The chrome.
 *
 * With `panels: { defaults: [] }` GrapesJS renders no shell of its own, so every
 * control the user touches is wired here to a command or to a class. Nothing in this
 * section can reach the PDF: the canvas decorations are delivered as `canvasCss` and
 * keyed off classes on the canvas body, and the compositor never sees either.
 * ========================================================================= */

/** CSS px per millimetre at 100% zoom. */
const PX_PER_MM = 96 / 25.4;

/** Width of a ruler, matching `--tpl-ruler` in the stylesheet. */
const RULER = 17;

/**
 * Paper sizes, in millimetres. The catalogue names the format; this maps it.
 *
 * Mirror of the server's one source, `PageGeometry::SHEET_SIZES`. Two hand-kept
 * maps is how A3 came to render as A4 in the editor while the PDF was correct —
 * keep them in step.
 */
const PAPER_SIZES = {
    A3: { width: 297, height: 420 },
    A4: { width: 210, height: 297 },
    A5: { width: 148, height: 210 },
    LETTER: { width: 215.9, height: 279.4 },
};

/**
 * The fixed canvas height of a roll (talão), in millimetres.
 *
 * A roll has no fixed height: it is measured when the document is rendered. The
 * canvas, though, needs a sheet to lay out on, so a roll is edited on a strip of the
 * roll's own width and this generous height.
 */
const ROLL_CANVAS_HEIGHT = 297;

/** The formats offered when the host declares none: the A4 sheet, as ever. */
const DEFAULT_PAGE_FORMATS = [{ id: 'A4', label: 'A4', medium: 'sheet', paper_format: 'A4' }];

/** @returns {{width: number, height: number}} the canvas paper a geometry stands for. */
function resolvePaper(geometry, fallbackFormat) {
    if (geometry?.medium === 'roll' && Number(geometry.width_mm) > 0) {
        return { width: Number(geometry.width_mm), height: ROLL_CANVAS_HEIGHT };
    }

    // A label: a sheet sized in mm. A fixed box, edited like a small A4 sheet.
    if (isLabelGeometry(geometry)) {
        return { width: Number(geometry.width_mm), height: Number(geometry.height_mm) };
    }

    return PAPER_SIZES[geometry?.paper_format ?? fallbackFormat] ?? PAPER_SIZES.A4;
}

/**
 * A label: a sheet whose size is given explicitly in mm, not by a named CSS format.
 * It is edited absolutely like an A4 sheet, but has no header/body/footer zones — a
 * 30mm label has no room for them.
 */
function isLabelGeometry(geometry) {
    return geometry?.medium === 'sheet'
        && Number(geometry.width_mm) > 0
        && Number(geometry.height_mm) > 0;
}

/** The geometry a format entry stands for, without the entry's own id and label. */
function geometryOf(format) {
    const { id, label, initial_layout, ...geometry } = format ?? {};

    return geometry;
}

function sameGeometry(a, b) {
    if (!a || !b || a.medium !== b.medium) {
        return false;
    }

    if (a.medium === 'roll') {
        return Number(a.width_mm) === Number(b.width_mm);
    }

    // A sheet in mm (a label) is identified by its mm size; a named sheet by its format.
    if (isLabelGeometry(a) || isLabelGeometry(b)) {
        return Number(a.width_mm) === Number(b.width_mm)
            && Number(a.height_mm) === Number(b.height_mm);
    }

    return (a.paper_format ?? 'A4') === (b.paper_format ?? 'A4');
}

function applyPaperVars(root, paper) {
    root.style.setProperty('--tpl-paper-width', paper.width + 'mm');
    root.style.setProperty('--tpl-paper-height', paper.height + 'mm');
}

/** Parse a JSON data attribute, falling back to a default on absence or garbage. */
function parseJson(value, fallback) {
    try {
        return value ? JSON.parse(value) : fallback;
    } catch {
        return fallback;
    }
}

/**
 * The decorations drawn on the sheet while it is being edited.
 *
 * Delivered through `canvasCss` rather than living in `editor.css`, and that is a
 * correction rather than a preference: the rules used to be written as
 * `.gjs-cv-canvas [data-tpl-legal="1"]`, and `.gjs-cv-canvas` is the element that
 * *wraps* the canvas iframe. A descendant selector does not cross an iframe boundary,
 * so the legal field marking RF-C06 asks for had never rendered once. What looked like
 * it working was GrapesJS's own component outline, which is the same grey dashed line
 * on every block, legal or not — measured: `rgba(170, 170, 170, 0.7)` on both.
 *
 * Every rule hangs off a class on the canvas body, so the toolbar can turn each one on
 * and off, and the printed document carries none of them.
 */
function canvasDecorations(paper, rollMode = false) {
    return `
        /*
         * The page width in the canvas. The print stylesheet fixes .page to the A4 sheet
         * width; a roll is 80/58mm and its content must wrap to that width (not to 210mm
         * and get clipped by the narrower body). A roll also has no fixed height — it
         * flows to its content — so it is not boxed to the sheet height here.
         */
        .page {
            width: ${paper.width}mm;
            box-sizing: border-box;
            ${rollMode ? 'height: auto; min-height: 0; overflow: visible;'
                + ' padding: var(--margin-top) var(--margin-right) var(--margin-bottom) var(--margin-left);' : ''}
        }

        /*
         * The canvas document is the sheet, and nothing more.
         *
         * GrapesJS sizes its iframe to the whole canvas area, so the body used to be a
         * grey expanse with the page floating in it: the "Body" badge covered an area
         * the document does not have, and clicking beside the paper selected it. Giving
         * the body the paper's own size makes the two coincide — what you can select is
         * what will print.
         *
         * Canvas only. The compositor builds its own document and never reads this.
         */
        html {
            background: var(--tpl-desk);
        }

        body {
            width: ${paper.width}mm;
            min-height: ${paper.height}mm;
            height: ${paper.height}mm;
            margin: 0;
            background: #fff;
        }

        /*
         * The selection, in the licence's colour rather than GrapesJS's blue. These
         * two live inside the canvas document, so they belong here and not in
         * editor.css — the outline is drawn on the block itself.
         */
        .gjs-selected {
            outline: 2px solid var(--tpl-accent) !important;
            outline-offset: 1px;
        }

        .gjs-hovered {
            outline: 1px solid color-mix(in srgb, var(--tpl-accent) 45%, transparent) !important;
        }

        body.tpl-view-clean .gjs-selected,
        body.tpl-view-clean .gjs-hovered {
            outline: 0 !important;
        }

        body.tpl-view-legal [data-tpl-legal="1"] {
            outline: 1px dashed color-mix(in srgb, var(--tpl-accent) 60%, transparent);
            outline-offset: 1px;
        }

        /* Off by default: on an invoice twelve of nineteen fields are legal, and a
           sheet outlined from top to bottom marks nothing at all. The permanent mark
           is a hairline at the field's left edge; the outline is for checking. */
        body:not(.tpl-view-legal) [data-tpl-legal="1"] {
            position: relative;
        }

        body:not(.tpl-view-legal) [data-tpl-legal="1"]::before {
            content: '';
            position: absolute;
            left: -3px;
            top: 0;
            bottom: 0;
            width: 1.5px;
            background: color-mix(in srgb, var(--tpl-accent) 45%, transparent);
        }

        body.tpl-view-zones .zone {
            outline: 1px dashed #dfe6ee;
            outline-offset: 0;
        }

        /* A block the local checks flagged, until it is resolved. Never hidden by the
           clean view: a problem the user cannot see is a problem they cannot fix. */
        [data-tpl-problem="1"] {
            outline: 2px solid #dc2626 !important;
            outline-offset: 1px;
        }

        /* A fixed-text code with a required field still empty. The payload it would
           compose is valid and meaningless — "tel:" with no number — so no server rule
           can refuse it, and this mark is the only thing that tells the user before they
           save. Dashed, to read as unfinished rather than as wrong. */
        [data-tpl-code-incomplete="1"] {
            outline: 2px dashed #dc2626 !important;
            outline-offset: 1px;
        }

        body.tpl-view-clean [data-tpl-legal="1"]::before {
            display: none;
        }

        /*
         * GrapesJS's own outline, drawn on every highlightable component, is the
         * busiest thing on the canvas and has to go too. Done in CSS rather than by
         * toggling its command: the command reported itself inactive while the class
         * was on the body, so asking it to turn off did nothing at all.
         */
        body.tpl-view-clean.gjs-dashed [data-gjs-highlightable],
        body.tpl-view-clean.gjs-dashed [data-gjs-highlightable] * {
            outline: 0 !important;
        }

        /* A code slot is empty until the server generates the image, so the canvas has
           to show that something occupies that space. Same iframe problem as above:
           these two carried the same wrapper ancestor and had never rendered
           either. */
        .block--code {
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px dashed #94a3b8;
            background: color-mix(in srgb, #e2e8f0 60%, transparent);
        }

        .block--code__hint {
            font-size: 0.5rem;
            text-align: center;
            color: #64748b;
        }

        /* Gridlines while the table is being placed. Never printed: the compositor
           reads print.css, and this is not in it. */
        .block--lines table.lines th,
        .block--lines table.lines td {
            outline: 1px dotted #cbd5e1;
        }

        body.tpl-view-clean .block--lines table.lines th,
        body.tpl-view-clean .block--lines table.lines td {
            outline: 0;
        }

        body.tpl-view-clean .block--code {
            border: 0;
            background: transparent;
        }
    `;
}

/**
 * Wire the toolbar, the panel tabs, the selection header, the counts and the rulers.
 *
 * @returns {{sync: function(number): void}} what the save and check cycle calls back
 */
function wireChrome(editor, config, chrome, paper, layoutState) {
    const root = config.root;

    // A roll flows: no sheet rulers, no header/body/footer bands, no fixed page height.
    // Reassigned by applyPaper: the format tabs switch medium in place (A4↔Talão), so the
    // canvas mode is not frozen at mount — the chrome, the rulers and drawRulers all read
    // whatever the current format is.
    let rollMode = config.geometry?.medium === 'roll';

    // A label is a fixed sheet with rulers but no zones — it is too small for bands.
    let labelMode = isLabelGeometry(config.geometry);

    const views = { legal: false, zones: !rollMode && !labelMode, rulers: !rollMode, clean: false };

    // The ruler and zone toggles are sheet-only; a roll has neither, a label keeps rulers.
    if (rollMode) {
        chrome.toolbar?.querySelectorAll('[data-tpl-toggle="rulers"],[data-tpl-toggle="zones"]')
            .forEach((button) => { (button.closest('.tpl-tool') ?? button).hidden = true; });
    } else if (labelMode) {
        chrome.toolbar?.querySelectorAll('[data-tpl-toggle="zones"]')
            .forEach((button) => { (button.closest('.tpl-tool') ?? button).hidden = true; });
    }

    /* ------------------------------------------------------------------ views */

    const applyViews = () => {
        const body = editor.Canvas.getBody();

        if (body) {
            body.classList.toggle('tpl-view-legal', views.legal && !views.clean);
            body.classList.toggle('tpl-view-zones', views.zones && !views.clean);
            body.classList.toggle('tpl-view-clean', views.clean);
        }

        root.classList.toggle('tpl-rulers-off', !views.rulers || views.clean);

        chrome.toolbar?.querySelectorAll('[data-tpl-toggle]').forEach((button) => {
            button.classList.toggle('is-on', views[button.dataset.tplToggle] === true);
        });

        drawRulers();
    };

    chrome.toolbar?.querySelectorAll('[data-tpl-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const name = button.dataset.tplToggle;

            views[name] = !views[name];

            applyViews();
        });
    });

    /* ------------------------------------------------------------------- zoom */

    // Set the moment the user picks a zoom of their own: from then on, fitting is
    // theirs to ask for and the pane resizing may not overrule it.
    let userChoseZoom = false;

    /** Air around the sheet, in pixels. Dead space is dead space. */
    const AIR = 12;

    // The zoom ceiling. It has to clear the fit zoom of the smallest page, not just a
    // comfortable hand-zoom: a 50×30mm label fits the pane at ~850%, and a ceiling of 400%
    // would clamp that fit and leave the small label stranded in grey while a 100×50 label
    // nearly filled — the asymmetry that read as "one format goes full-screen".
    const MAX_ZOOM = 1000;

    const setZoom = (percent) => {
        const clamped = Math.min(MAX_ZOOM, Math.max(10, Math.round(percent)));

        editor.Canvas.setZoom(clamped);

        /*
         * The drawing area is the sheet, at the sheet's own size.
         *
         * This replaces four separate corrections — a `top` offset on the frame's
         * container, two probes to measure where that offset landed, a width override
         * and an auto margin — and it fixes moving blocks, which none of them could.
         * GrapesJS computes a dragged block's position from measured rects minus a
         * viewport delta it derives from its own model: a `top` we set in CSS shows up
         * in the rect and not in the delta, so every drag came out displaced by exactly
         * the centring offset. With the canvas the same size as the paper there is no
         * offset left to be invisible.
         *
         * The grey around the sheet is then the grid centring this row, outside the
         * editor entirely.
         */
        const scale = clamped / 100;

        chrome.mount.style.width = Math.round(paper.width * PX_PER_MM * scale) + 'px';
        chrome.mount.style.height = Math.round(paper.height * PX_PER_MM * scale) + 'px';

        if (chrome.zoom) {
            chrome.zoom.value = clamped + '%';
        }

        drawRulers();
    };

    /*
     * The zoom is typed as well as stepped. Two clicks of 25% to go from 52% to 100% is
     * two clicks too many when the number is right there.
     */
    if (chrome.zoom) {
        const applyTypedZoom = () => {
            const typed = parseFloat(String(chrome.zoom.value).replace(',', '.'));

            if (Number.isFinite(typed)) {
                userChoseZoom = true;
                setZoom(typed);
            } else {
                // Restore what it was: an empty box is not a zoom.
                setZoom(editor.Canvas.getZoom() || 100);
            }
        };

        chrome.zoom.addEventListener('change', applyTypedZoom);
        chrome.zoom.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                applyTypedZoom();
                chrome.zoom.blur();
            }
        });
        chrome.zoom.addEventListener('focus', () => chrome.zoom.select());
    }

    /**
     * Fit the whole sheet in the visible area.
     *
     * Pure arithmetic now: the drawing area is the paper, so there is no margin of
     * GrapesJS's own to measure around it. The air is the only slack.
     */
    const fitZoom = () => {
        const outer = chrome.canvas;

        if (!outer || outer.clientHeight < 40) {
            return;
        }

        const paperPx = { width: paper.width * PX_PER_MM, height: paper.height * PX_PER_MM };

        setZoom(Math.floor(Math.min(
            (outer.clientWidth - RULER - AIR * 2) / paperPx.width,
            (outer.clientHeight - RULER - AIR * 2) / paperPx.height,
        ) * 100));
    };

    const commands = {
        'zoom-in': () => {
            userChoseZoom = true;
            setZoom(editor.Canvas.getZoom() * 1.25);
        },
        'zoom-out': () => {
            userChoseZoom = true;
            setZoom(editor.Canvas.getZoom() / 1.25);
        },
        'zoom-fit': () => {
            userChoseZoom = false;
            fitZoom();
        },
        'restore-legal': () => {
            const inserted = restoreMissingLegalFields(editor, config);

            renderStatus(
                inserted === 0
                    ? config.strings['action.restore_legal_none']
                    : config.strings['action.restore_legal_done'].replace(':count', String(inserted)),
                root,
            );
        },
    };

    chrome.toolbar?.querySelectorAll('[data-tpl-command]').forEach((button) => {
        button.addEventListener('click', () => commands[button.dataset.tplCommand]?.());
    });

    /* ------------------------------------------------------------ page format */

    /**
     * Put the canvas on the paper of a geometry: the CSS variables, the canvas body,
     * the mount's size and the rulers. The one path both the initial mount and a
     * format change take.
     */
    /*
     * The geometry-dependent canvas rules, re-applied on every format change.
     *
     * `canvasDecorations()` bakes `.page` and the drag mode at mount, from the format the
     * editor opened on. The format tabs switch medium in place (A4→Talão), so those baked
     * values are wrong after a cross-medium switch: a roll kept the A4 `.page` (210mm,
     * overflow:hidden, no padding) clipped into the narrow body — the "A4 cut in half" the
     * talão showed. These rules are the single source for the live canvas, written into a
     * managed <style> in the canvas document (so they never reach the saved layout CSS) and
     * given one extra specificity step (`html .page`) so they beat print.css and the baked
     * decorations whatever the injection order.
     */
    const applyCanvasGeometry = (geometry) => {
        const roll = geometry?.medium === 'roll';

        // Absolute placement for a sheet; the native sorter (flow) for a roll.
        editor.setDragMode?.(roll ? '' : 'absolute');

        const doc = editor.Canvas.getDocument?.();

        if (! doc?.head) {
            return;
        }

        let style = doc.getElementById('tpl-geom');

        if (! style) {
            style = doc.createElement('style');
            style.id = 'tpl-geom';
            doc.head.appendChild(style);
        }

        const m = geometry?.margins ?? {};
        const mm = (value) => (Number(value) || 0) + 'mm';

        style.textContent = 'html .page{width:' + paper.width + 'mm;box-sizing:border-box;'
            + (roll
                ? 'height:auto;min-height:0;overflow:visible;padding:'
                    + mm(m.top) + ' ' + mm(m.right) + ' ' + mm(m.bottom) + ' ' + mm(m.left) + ';}'
                : 'height:' + paper.height + 'mm;overflow:hidden;padding:0;}');
    };

    /*
     * The chrome that depends on the medium: a roll has no rulers and no zone bands, a
     * label has rulers but no zones. Re-evaluated on every switch so the tabs cannot leave
     * a roll wearing the sheet's rulers.
     */
    const syncModeChrome = () => {
        views.zones  = ! rollMode && ! labelMode && views.zones;
        views.rulers = ! rollMode;

        const setToggleHidden = (name, hidden) => {
            chrome.toolbar?.querySelectorAll('[data-tpl-toggle="' + name + '"]')
                .forEach((button) => { (button.closest('.tpl-tool') ?? button).hidden = hidden; });
        };

        setToggleHidden('rulers', rollMode);
        setToggleHidden('zones', rollMode || labelMode);

        applyViews();
    };

    /*
     * The canvas body size and geometry style, applied to whatever frame is current.
     *
     * Also bound to `canvas:frame:load:body`: loadProjectData (a format that has been saved
     * at least once — the default A4 here) rebuilds the iframe asynchronously, which drops
     * the inline body size and the managed <style> and would leave a switch back to that
     * format showing the previous one's canvas. Re-running it on the rebuilt frame is what
     * makes switching back to A4 take effect. `height: auto` on a roll's .page lets the body
     * (sized to ROLL_CANVAS_HEIGHT) provide the scroll area while the page flows.
     */
    const applyCanvasToFrame = () => {
        const body = editor.Canvas.getBody?.();

        if (body) {
            body.style.width = paper.width + 'mm';
            body.style.height = paper.height + 'mm';
            body.style.minHeight = paper.height + 'mm';
        }

        applyCanvasGeometry(config.geometry);
        applyViews();
    };

    const applyPaper = (geometry) => {
        Object.assign(paper, resolvePaper(geometry, config.paperFormat));
        applyPaperVars(root, paper);

        // The canvas mode follows the format: the tabs change medium in place.
        rollMode  = geometry?.medium === 'roll';
        labelMode = isLabelGeometry(geometry);

        applyCanvasToFrame();
        syncModeChrome();

        if (userChoseZoom) {
            setZoom(editor.Canvas.getZoom() || 100);
        } else {
            fitZoom();
        }

        if (!rollMode) {
            drawRulers();
        }
    };

    /*
     * A format can carry its own starting layout (a roll is a single column, not the A4
     * sheet clipped). Loading it replaces the canvas the way the first open does: the
     * page variables first, then the layout's own rules, then its markup, then the same
     * identity and locking a freshly parsed document gets.
     */
    const loadLayout = (layout) => {
        editor.setComponents(layout.html);

        // setStyle replaces the whole sheet and re-renders the canvas in one step. The
        // Css.clear()+addRules path updates the model but leaves the already-mounted canvas
        // iframe showing the old rules, so a format switch kept the previous positions and
        // the new layout's fields stacked at 0,0.
        editor.setStyle(pageVariables(config) + (layout.css || ''));

        applyZoneIdentity(editor, config);
        lockLegalFields(editor);

        // What is on the canvas now is a starting point again, not work.
        layoutState.pristine = snapshot(editor);
        layoutState.refresh();
    };

    const formatSelect = chrome.toolbar?.querySelector('[data-tpl-format]');

    if (formatSelect) {
        const formats = config.pageFormats;
        const current = formats.find((format) => sameGeometry(geometryOf(format), config.geometry))
            ?? formats[0];

        // Nothing declared by the version: it is, and stays, the first format offered.
        config.geometry = config.geometry ?? geometryOf(current);

        formatSelect.replaceChildren(...formats.map((format) => {
            const option = document.createElement('option');

            option.value = format.id;
            option.textContent = format.label ?? format.id;

            return option;
        }));
        formatSelect.value = current.id;
        formatSelect.disabled = config.locked || !config.editable;
        // A single offer is no choice: leave the control out.
        formatSelect.closest('.tpl-tools')?.toggleAttribute('hidden', formats.length < 2);

        formatSelect.addEventListener('change', async () => {
            const chosen = formats.find((format) => format.id === formatSelect.value);

            if (!chosen) {
                return;
            }

            const layout = chosen.initial_layout;
            const hasLayout = layout && typeof layout.html === 'string' && layout.html !== '';

            if (hasLayout && config.editable && !config.locked) {
                const hasContent = layoutState.pristine === null || snapshot(editor) !== layoutState.pristine;

                if (hasContent) {
                    const confirmed = await confirmDialog(
                        config.strings.formatSwitchConfirm
                            || 'Trocar de formato substitui o layout atual. Continuar?',
                        {
                            root: config.root,
                            confirmLabel: config.strings['dialog.confirm'],
                            cancelLabel: config.strings['dialog.cancel'],
                        },
                    );

                    if (!confirmed) {
                        // Declined: keep the layout, change only the geometry.
                        config.geometry = geometryOf(chosen);
                        applyPaper(config.geometry);

                        return;
                    }
                }

                loadLayout(layout);
            }

            config.geometry = geometryOf(chosen);
            applyPaper(config.geometry);
            // The rules depend on the geometry (V-16 is skipped for a roll): re-check.
            layoutState.refresh();
        });
    }

    /* ----------------------------------------------------------- format tabs */

    /*
     * Switching page format in place. Each format is its own version lineage (per-format
     * drafts on the server), so a switch is not a geometry swap on the current version: it
     * fetches the target format's own version, rewires the lifecycle URLs to it, and loads
     * its layout — all without navigating, so the workspace pane is never left for the
     * full-page editor. Same-medium only (A3↔A4↔A5, 50×30↔100×50); a type never mixes a
     * page format with email, which has a single format and no tabs.
     */
    const formatTabs = root.querySelectorAll('[data-tpl-format-tab]');

    if (formatTabs.length > 0 && config.formatSwitchUrl) {
        let switching = false;

        // The custom ("Personalizado") size inputs, shown only while the custom format is
        // active. Changing them resizes the page live; Save persists the size with the
        // geometry. The server clamps the dimensions into bounds whatever is typed here.
        const customControl = root.querySelector('[data-tpl-custom-size]');
        const widthInput    = root.querySelector('[data-tpl-custom-width]');
        const heightInput   = root.querySelector('[data-tpl-custom-height]');

        let currentFormat = [...formatTabs].find((tab) => tab.getAttribute('aria-current') === 'page')?.dataset.tplFormatTab
            ?? config.pageFormats.find((format) => sameGeometry(geometryOf(format), config.geometry))?.id;

        const isCustomFormat = (formatId) => config.pageFormats.some((format) => format.id === formatId && format.custom === true);

        const updateCustomControl = () => {
            if (! customControl) {
                return;
            }

            const custom = isCustomFormat(currentFormat);

            customControl.hidden = ! custom;

            // Do not fight the user's own typing: only refill when the field is not focused.
            if (custom && widthInput && heightInput
                && document.activeElement !== widthInput && document.activeElement !== heightInput) {
                widthInput.value  = Math.round(Number(config.geometry?.width_mm) || 0);
                heightInput.value = Math.round(Number(config.geometry?.height_mm) || 0);
            }
        };

        const applyCustomSize = () => {
            if (! isCustomFormat(currentFormat) || ! widthInput || ! heightInput) {
                return;
            }

            const clamp = (value) => Math.max(20, Math.min(1200, Math.round(Number(value) || 0)));

            config.geometry = {
                ...config.geometry,
                medium: 'sheet',
                width_mm: clamp(widthInput.value),
                height_mm: clamp(heightInput.value),
            };

            applyPaper(config.geometry);
            // A size change is real work: mark it so preview/activate save it first and
            // leaving the page warns. The snapshot would not otherwise notice a geometry change.
            layoutState.markDirty?.();
        };

        [widthInput, heightInput].forEach((input) => {
            input?.addEventListener('change', applyCustomSize);
        });

        const markActiveTab = (formatId) => {
            formatTabs.forEach((tab) => {
                const isActive = tab.dataset.tplFormatTab === formatId;

                tab.classList.toggle('font-semibold', isActive);
                tab.classList.toggle('ring-1', isActive);
                tab.classList.toggle('ring-slate-300', isActive);
                tab.classList.toggle('opacity-70', !isActive);

                if (isActive) {
                    tab.setAttribute('aria-current', 'page');
                } else {
                    tab.removeAttribute('aria-current');
                }
            });
        };

        const renderSwitched = (info) => {
            if (info.project_data && Object.keys(info.project_data).length > 0) {
                // A format edited before: its saved project data carries its own styles, the
                // same path a normal load takes.
                editor.loadProjectData(info.project_data);
                applyZoneIdentity(editor, config);
                lockLegalFields(editor);

                layoutState.pristine = snapshot(editor);
                layoutState.refresh();
            } else {
                // A fresh format draft carries its format's starting layout as HTML, not yet
                // project data; loadLayout resets the canvas to it and marks it pristine.
                loadLayout({ html: info.html || config.initialHtml, css: info.css || '' });
            }
        };

        const switchFormat = async (formatId) => {
            const active = [...formatTabs].find((tab) => tab.getAttribute('aria-current') === 'page');

            if (switching || active?.dataset.tplFormatTab === formatId) {
                return;
            }

            switching = true;

            try {
                // Each format is its own version, so a switch is not a discard: the current
                // format's unsaved work is saved to ITS version first, the same rule preview
                // and activate follow. A failed save aborts the switch.
                if (config.editable && layoutState.isDirty?.()) {
                    renderStatus(config.strings.saving, root);
                    await layoutState.save();
                    layoutState.resetSaved?.();
                }

                const url = config.formatSwitchUrl.replace('__FORMAT__', encodeURIComponent(formatId));
                const response = await fetch(url, { headers: requestHeaders(config) });

                if (! response.ok) {
                    throw new Error(config.strings.loadFailed);
                }

                const info = await response.json();

                config.saveUrl     = info.urls.save;
                config.loadUrl     = info.urls.load;
                config.previewUrl  = info.urls.preview;
                config.activateUrl = info.urls.activate;
                // A version may carry no geometry of its own — a legacy default-format version
                // (the first A4) predates the geometry column and stores [] (or null). Fall
                // back to the FORMAT's own geometry from the registry, never to the format left
                // on screen: `?? config.geometry` kept the previous format because [] is truthy
                // and a missing geometry fell through to the one being switched away from, so
                // the canvas loaded A4's content but stayed shaped like the talão.
                const hasGeometry = (value) => Boolean(value)
                    && (Array.isArray(value) ? value.length > 0 : Object.keys(value).length > 0);
                const formatEntry = config.pageFormats.find((format) => format.id === info.format);
                const formatGeometry = formatEntry ? geometryOf(formatEntry) : null;

                config.geometry = hasGeometry(info.geometry)
                    ? info.geometry
                    : (formatGeometry ?? config.geometry);

                // Load the format's layout first, then size and restyle the canvas: applyPaper
                // writes the managed geometry <style> and must run after loadLayout's
                // setComponents/setStyle, and fitZoom then measures the sheet with content in it.
                renderSwitched(info);
                applyPaper(config.geometry);
                currentFormat = info.format;
                markActiveTab(info.format);
                updateCustomControl();

                // In the full-page editor, keep the address bar on the version now shown, so
                // a reload returns to this format. In a pane the workspace owns the URL, so
                // only touch it when the URL is the editor route itself.
                if (info.urls.edit && location.pathname.includes('/versions/')) {
                    history.replaceState(null, '', info.urls.edit);
                }

                // The loaded version is the new clean baseline: no stale "unsaved" from the
                // one we left, no snapshot belonging to the previous format.
                layoutState.resetSaved?.();
            } catch (error) {
                renderStatus(error.message, root);
            } finally {
                switching = false;
            }
        };

        formatTabs.forEach((tab) => {
            tab.addEventListener('click', () => switchFormat(tab.dataset.tplFormatTab));
        });

        // Reflect the format the editor opened on (custom inputs visible if it is custom).
        updateCustomControl();
    }

    /* ------------------------------------------------------------------- tabs */

    root.querySelectorAll('[data-tpl-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            root.querySelectorAll('[data-tpl-tab]').forEach((other) => {
                other.classList.toggle('is-active', other === tab);
            });

            root.querySelectorAll('[data-tpl-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.tplPanel !== tab.dataset.tplTab;
            });

            if (tab.dataset.tplTab === 'layers') {
                refreshLayers();
            }
        });
    });

    /* -------------------------------------------------- legal fields and Delete */

    /*
     * With no delete icon on the toolbar (there is nothing to click — `removable:
     * false`, set by `lockLegalFields()`, already suppresses it), the keyboard is the
     * only door left, and GrapesJS binds `Delete` and `Backspace` to
     * `core:component-delete` itself. Replacing that keymap is one interception point,
     * not several: the command is still GrapesJS's own when the selected block is not
     * legal.
     *
     * The refusal already existed — a legal block simply would not go away — but a
     * block that does not disappear without saying why reads as a broken editor.
     */
    // No `data-tpl-collection` fallback here, unlike `renderSelection`: `data-tpl-legal` is
    // only ever derived from `data-tpl-binding` (`applyLegalMarking` in both
    // components.js and table.js), never from a collection, so there is nothing to fall
    // back to.
    const legalFieldName = (component) => {
        const attributes = component.getAttributes?.() ?? {};
        const binding = config.bindings?.find((item) => item.key === attributes['data-tpl-binding']);

        return binding?.label ?? component.get?.('name') ?? component.getName?.() ?? '';
    };

    const isLegalField = (component) => (component.getAttributes?.() ?? {})['data-tpl-legal'] === '1';

    // A legal field blocks deletion only while it is the last block carrying its binding.
    // A redundant duplicate (a second block bound to the same legal field) is not the one
    // the law requires — the server validates every occurrence but requires none in
    // particular (V-10 fires only on total absence) — so removing it is allowed. This
    // mirrors `applyLegalMarking`'s toolbar rule, on the keyboard path that shows the
    // notice. Survivors are counted excluding the blocks being deleted, so selecting both
    // copies and pressing Delete is still refused: that would erase the field entirely.
    const legalBlocks = (ed) => {
        const selected = ed.getSelectedAll();
        const selectedCids = new Set(selected.map((component) => component.cid));
        const dataFields = editor.getWrapper()?.find('[data-tpl-type="data-field"]') ?? [];

        return selected.filter((component) => {
            if (!isLegalField(component)) {
                return false;
            }

            const binding = (component.getAttributes?.() ?? {})['data-tpl-binding'];
            const survivors = dataFields.filter(
                (candidate) => candidate.getAttributes()['data-tpl-binding'] === binding
                    && !selectedCids.has(candidate.cid),
            ).length;

            return survivors === 0;
        });
    };

    editor.Keymaps.remove('core:component-delete');

    // `prevent: true` matches the default GrapesJS registers for this command: it calls
    // `preventDefault()` on the native keydown before the handler runs, same as before.
    editor.Keymaps.add('pf:component-delete', 'backspace, delete', (ed) => {
        const blocked = legalBlocks(ed);

        if (blocked.length > 0) {
            // Soft lock: a legal field CAN be removed, but deliberately. The confirm is the
            // guard against an accidental Delete, not the compliance boundary — the problems
            // panel flags a missing legal field live, and the server refuses to save a layout
            // without it (spec 8.1). Declining leaves the block untouched.
            //
            // The dialog is async, so the delete cannot run inline: `prevent: true` has
            // already stopped the native delete, and the command runs only once the user
            // confirms. Returning here (without deleting) is the "ask first" path.
            confirmDialog(
                config.strings['confirm.delete_legal'].replace(':field', legalFieldName(blocked[0])),
                {
                    root: config.root,
                    confirmLabel: config.strings['dialog.confirm'],
                    cancelLabel: config.strings['dialog.cancel'],
                },
            ).then((confirmed) => {
                if (confirmed) {
                    ed.runCommand('core:component-delete');
                }
            });

            return;
        }

        ed.runCommand('core:component-delete');
    }, { prevent: true });

    /* --------------------------------------------------------------- selection */

    /**
     * Which block is being edited, and under which rules.
     *
     * GrapesJS's own answer is "Selected: Body #ikaw". What the user needs is the
     * field's name, whether the law is holding it, and on which pages it has to appear
     * — the three things that decide whether they may move or delete it.
     */
    const renderSelection = () => {
        if (!chrome.selected) {
            return;
        }

        const component = editor.getSelected();
        const attributes = component?.getAttributes?.() ?? {};
        const key = attributes['data-tpl-binding'] ?? attributes['data-tpl-collection'] ?? '';
        const binding = config.bindings?.find((item) => item.key === key);

        /*
         * With nothing chosen, the panels are hidden rather than shown empty.
         *
         * GrapesJS renders the style manager for the wrapper when there is no
         * selection, so the panel filled up with blank fields and placeholder-grey
         * dropdowns — a screenful of what reads as disabled controls, and the first
         * thing anyone points at.
         */
        root.classList.toggle('tpl-no-selection', !component);

        if (!component) {
            chrome.selected.innerHTML =
                `<p class="tpl-selected-empty">${escapeHtml(config.strings['chrome.nothing_chosen'])}</p>`;

            drawRulers();

            return;
        }

        const name = binding?.label ?? component.get('name') ?? component.getName?.() ?? '';
        const chip = attributes['data-tpl-legal'] === '1'
            ? `<span class="tpl-chip">${icons.shieldCheck} ${escapeHtml(config.strings['chrome.legal_chip'])}</span>`
            : '';

        const meta = [key, binding?.type, scopeLabel(binding, config)].filter(Boolean);

        chrome.selected.innerHTML =
            `<p class="tpl-selected-name">${escapeHtml(name)}${chip}</p>`
            + `<p class="tpl-selected-meta">${escapeHtml(meta.join(' · '))}</p>`;

        drawRulers();
    };

    editor.on('component:selected component:deselected', renderSelection);

    /* ----------------------------------------------------------------- counts */

    const sync = (problemCount = 0) => {
        if (!chrome.counts) {
            return;
        }

        const wrapper = editor.getWrapper();

        if (!wrapper) {
            return;
        }

        const count = (selector) => wrapper.find(selector).length;

        const parts = [
            plural(config.strings['chrome.count_fields'], count('[data-tpl-type="data-field"]')),
            plural(config.strings['chrome.count_tables'], count('[data-tpl-type="line-table"]')),
            plural(config.strings['chrome.count_codes'], count('[data-tpl-type="code-slot"]')),
        ];

        let html = `<span>${escapeHtml(parts.join(' · '))}</span>`;

        if (problemCount > 0) {
            html += `<span class="tpl-counts-bad">${icons.warning} `
                + `${escapeHtml(plural(config.strings['chrome.count_problems'], problemCount))}</span>`;
        }

        chrome.counts.innerHTML = html;

        drawRulers();
    };

    /* ---------------------------------------------------------------- rulers */

    /**
     * Rulers built from the sheet's measured position, never from numbers written by
     * hand: the canvas is a zoomed iframe, and a tick placed with an assumed scale
     * comes away from the paper by the second centimetre.
     *
     * Only public APIs are used to locate the sheet — the frame element, the canvas
     * body and the zoom — because `Canvas.getElementPos()`, which would answer this in
     * one call, is marked private in GrapesJS and would be a silent break on upgrade.
     */
    function drawRulers() {
        // A roll has no rulers (it is not placed by coordinate).
        if (rollMode || !chrome.rulers?.length) {
            return;
        }

        const frame = editor.Canvas.getFrameEl?.();
        const body = editor.Canvas.getBody?.();
        const page = body?.querySelector('.page');

        if (!frame || !page) {
            return;
        }

        const zoom = (editor.Canvas.getZoom?.() ?? 100) / 100;
        const frameBox = frame.getBoundingClientRect();
        const pageBox = page.getBoundingClientRect();

        // The sheet in the host document's own pixels.
        const sheet = {
            left: frameBox.left + pageBox.left * zoom,
            top: frameBox.top + pageBox.top * zoom,
            width: pageBox.width * zoom,
            height: pageBox.height * zoom,
        };

        const selected = editor.getSelected()?.getEl?.();
        const selectedBox = selected?.getBoundingClientRect?.();

        chrome.rulers.forEach((ruler) => {
            const axis = ruler.dataset.tplRuler;
            const horizontal = axis === 'top';
            const rulerBox = ruler.getBoundingClientRect();

            const length = horizontal ? paper.width : paper.height;
            const scale = (horizontal ? sheet.width : sheet.height) / length;
            const origin = (horizontal ? sheet.left - rulerBox.left : sheet.top - rulerBox.top);

            ruler.textContent = '';

            if (scale <= 0) {
                return;
            }

            // Every 5mm, numbered every 20: denser than that is illegible below 50%.
            for (let mm = 0; mm <= length; mm += 5) {
                const at = origin + mm * scale;

                if (at < -10 || at > (horizontal ? rulerBox.width : rulerBox.height) + 10) {
                    continue;
                }

                const major = mm % 20 === 0;
                const tick = document.createElement('span');

                tick.className = 'tpl-tick' + (major ? ' tpl-tick--major' : '');
                tick.style[horizontal ? 'left' : 'top'] = at + 'px';
                ruler.appendChild(tick);

                if (major && mm > 0 && mm < length - 8) {
                    const label = document.createElement('span');

                    label.className = 'tpl-tick-label';
                    label.textContent = mm;
                    label.style[horizontal ? 'left' : 'top'] = at + 'px';
                    ruler.appendChild(label);
                }
            }

            /*
             * The chosen block's extent, projected onto the ruler. This is the answer
             * to the question the user actually asks — does this fit? — and it is the
             * same measurement the truncation rules make on the rendered PDF.
             */
            if (selectedBox && selectedBox.width > 0 && !views.clean) {
                const span = document.createElement('span');

                span.className = 'tpl-ruler-span';

                if (horizontal) {
                    span.style.left = (frameBox.left + selectedBox.left * zoom - rulerBox.left) + 'px';
                    span.style.width = (selectedBox.width * zoom) + 'px';
                } else {
                    span.style.top = (frameBox.top + selectedBox.top * zoom - rulerBox.top) + 'px';
                    span.style.height = (selectedBox.height * zoom) + 'px';
                }

                ruler.appendChild(span);
            }
        });
    }

    /* --------------------------------------------------------------- layers */

    /**
     * Rebuild the layer tree, rooted at the sheet and without the blocks' internals.
     *
     * Re-rooting is what forces the rebuild, and it happens when the tab is opened —
     * the moment the tree is looked at. It must not touch the components: a pass that
     * set flags on them fired change events after the load had taken its snapshot, and
     * the version opened already marked as having unsaved work.
     */
    const refreshLayers = () => {
        const wrapper = editor.getWrapper();
        const page = wrapper?.find('.page')?.[0];

        if (!wrapper) {
            return;
        }

        try {
            editor.Layers.setRoot(page ?? wrapper);
        } catch {
            // A GrapesJS without that API is not a reason to break the panel.
        }
    };

    /* --------------------------------------------------------------- lifetime */

    /**
     * Everything that needs the canvas document to exist.
     *
     * Called by the load cycle in `mountEditor` once the version is in place, and not
     * from a `load` handler of its own. That was the first shape and it did not work:
     * two handlers on the same event, and the chrome's ran while the document was
     * still being swapped in — measured by the canvas body carrying none of the view
     * classes and the zoom label still showing its placeholder. Ordering by event
     * registration is guesswork; ordering by the caller is not.
     */
    const attach = () => {
        applyPaper(config.geometry);
        applyViews();
        renderSelection();
        refreshLayers();
        fitZoom();

        const canvasEl = editor.Canvas.getElement?.();

        canvasEl?.addEventListener('scroll', drawRulers, { passive: true });

        /*
         * Both boxes are watched, and that is the fix for a sheet that sat small in a
         * large canvas.
         *
         * The fit was computed once, against whatever height the pane had at that
         * instant, and the pane is still settling then — the validation strip appears,
         * the workspace finishes its own layout. Watching only the outer element missed
         * it: what grows is GrapesJS's canvas inside, and the outer box can keep its
         * size while the inner one gains a hundred pixels. Measured on a taller window:
         * the sheet fitted at 59% inside a box that had room for 76%, leaving a
         * hundred-pixel ring of grey on every side.
         */
        const observer = new ResizeObserver(() => (userChoseZoom ? drawRulers() : fitZoom()));

        [chrome.canvas, canvasEl].forEach((element) => element && observer.observe(element));

        editor.on('destroy', () => observer.disconnect());
    };

    chrome.canvas?.addEventListener('scroll', drawRulers, { passive: true });
    window.addEventListener('resize', drawRulers);

    editor.on('component:update component:add component:remove', drawRulers);

    // loadProjectData (a saved format, e.g. the default A4) rebuilds the canvas iframe
    // asynchronously; re-apply the body size and geometry style to the new frame, or a
    // switch back to that format keeps the previous format's canvas.
    editor.on('canvas:frame:load:body', () => applyCanvasToFrame());

    // The tree has to follow the geometry; see `reparentToZone`. A roll has no bands, and
    // flow mode reorders in the tree already, so there is nothing to reparent. A label has
    // no bands either — a header plus footer would exceed its height and scatter blocks.
    // The mode is checked when the drag ends, not when the handler is registered: a format
    // tab can turn the sheet into a roll (and back) after mount.
    editor.on('component:drag:end', ({ target }) => {
        if (rollMode || labelMode) {
            return;
        }

        reparentToZone(editor, config, target);
    });

    return { sync, drawRulers, fitZoom, attach };
}

/** The scope a legal binding declares, as a sentence. */
function scopeLabel(binding, config) {
    if (!binding?.legal) {
        return '';
    }

    return binding.scope === 'every_page'
        ? config.strings['chrome.scope_all']
        : config.strings['chrome.scope_any'];
}

/**
 * Pick a branch out of a Laravel pluralisation string (`":count campo|:count campos"`).
 * The strings arrive already translated but not resolved, because the count only
 * exists in the browser.
 */
function plural(template, count) {
    const branches = String(template ?? '').split('|');
    const chosen = count === 1 ? branches[0] : (branches[1] ?? branches[0]);

    return String(chosen ?? '').replace(':count', String(count));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character]));
}

function renderProblems(editor, config, problems) {
    const panel = (config.root ?? document).querySelector('[data-tpl-problems]');

    // The component ids that should carry the problem outline after this pass. The markers
    // are reconciled against this set at every exit below, and never blanket-cleared up
    // front: clearing a still-problematic component and then re-adding its marker are two
    // `component:update` events, and the update listener re-runs this function — an infinite
    // re-render loop that calls panel.replaceChildren() (resetting the problems list's scroll
    // to the top) dozens of times a second. It only bites once a problem points at a live
    // component — e.g. after a save adds server-side problems — which is why an
    // all-missing-fields layout never showed it.
    const wanted = new Set();

    // Remove the marker only from components that are no longer a problem; a component that
    // stays a problem keeps its '1' untouched, so no attribute changes => no component:update
    // => the checks settle after one pass.
    const reconcileMarkers = () => {
        editor.getWrapper().find('[data-tpl-problem]').forEach((component) => {
            if (!wanted.has(component.getId()) && component.getAttributes()['data-tpl-problem'] !== undefined) {
                component.addAttributes({ 'data-tpl-problem': null });
            }
        });
    };

    if (!panel) {
        reconcileMarkers();

        return;
    }

    panel.replaceChildren();
    panel.hidden = problems.length === 0;

    if (problems.length === 0) {
        reconcileMarkers();

        return;
    }

    const heading = document.createElement('p');
    heading.className = 'tpl-problems__heading';
    heading.textContent = config.strings['problems.heading'].replace(':count', String(problems.length));
    panel.append(heading);

    /*
     * Grouped into structure and legal fields (spec 8.3). The two answer different
     * questions — "is this document well formed" and "will the law be satisfied" — and
     * a single flat list makes the second easy to lose inside the first.
     */
    const groups = [
        { key: 'structure', label: config.strings['problems.structure'], items: [] },
        { key: 'legal', label: config.strings['problems.legal'], items: [] },
        // R-xx come from the rendered PDF at activation, and they carry a page rather
        // than a component — a different kind of answer, so a group of their own.
        { key: 'render', label: config.strings['problems.render'], items: [] },
    ];

    problems.forEach((problem) => {
        const code = String(problem.code ?? '');

        if (code.startsWith('R-')) {
            groups[2].items.push(problem);

            return;
        }

        const number = Number(code.replace(/^V-/, ''));

        (Number.isFinite(number) && number >= 10 ? groups[1] : groups[0]).items.push(problem);
    });

    groups.filter((group) => group.items.length > 0).forEach((group) => {
        const heading = document.createElement('p');
        heading.className = 'tpl-problems__group';
        heading.textContent = group.label;
        panel.append(heading);

        const list = document.createElement('ul');
        list.className = 'tpl-problems__list';

        group.items.forEach((problem) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tpl-problems__item';
            button.textContent = `${problem.code} — ${problem.message}`;

            // The server calls it `component_id`, the local checks `componentId`.
            const componentId = problem.componentId ?? problem.component_id ?? '';
            const component = componentId
                ? editor.getWrapper().find('#' + componentId)[0]
                : undefined;

            if (component) {
                wanted.add(component.getId());

                if (component.getAttributes()['data-tpl-problem'] !== '1') {
                    component.addAttributes({ 'data-tpl-problem': '1' });
                }

                button.addEventListener('click', () => {
                    editor.select(component);
                    editor.Canvas.scrollTo(component);
                });
            } else {
                button.disabled = true;
            }

            item.append(button);
            list.append(item);
        });

        panel.append(list);
    });

    reconcileMarkers();
}

/** @returns {object} everything the editor needs, read once from the container. */
function readConfig(el) {
    return {
        loadUrl: el.dataset.loadUrl,
        saveUrl: el.dataset.saveUrl,
        previewUrl: el.dataset.previewUrl,
        activateUrl: el.dataset.activateUrl,
        catalogueUrl: el.dataset.catalogueUrl,
        // `__FORMAT__` is replaced with the chosen format id on an in-place format switch.
        formatSwitchUrl: el.dataset.formatSwitchUrl,
        printStylesheet: el.dataset.printStylesheet,
        fontFamily: el.dataset.fontFamily,
        fontFaces: JSON.parse(el.dataset.fontFaces || '[]'),
        paperFormat: el.dataset.paperFormat,
        // Not `formats`: the catalogue's field formats already own that name.
        pageFormats: (() => {
            const list = parseJson(el.dataset.formats, []);

            return Array.isArray(list) && list.length > 0 ? list : DEFAULT_PAGE_FORMATS;
        })(),
        // The version's geometry as the host stored it; null means "the default".
        geometry: (() => {
            const value = parseJson(el.dataset.geometry, null);

            return value && typeof value === 'object' && !Array.isArray(value) && value.medium ? value : null;
        })(),
        locked: el.dataset.locked === '1' || el.dataset.locked === 'true',
        margins: JSON.parse(el.dataset.margins || '{}'),
        editable: el.dataset.editable === '1',
        initialHtml: el.dataset.initialHtml || '<div class="page"></div>',
        initialCss: el.dataset.initialCss || '',
        strings: JSON.parse(el.dataset.strings || '{}'),
        csrfToken: el.dataset.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '',
        tenantHeader: el.dataset.tenantHeader || '',
        tenantId: el.dataset.tenant || '',
    };
}

/**
 * Fields, formats, legibility limits and fixture, from the server.
 *
 * A failure here is fatal rather than degraded: without the catalogue the binding
 * select would be empty, and an editor that lets someone draw fields that do not
 * exist is worse than one that refuses to open.
 */
async function loadCatalogue(config) {
    const response = await fetch(config.catalogueUrl, { headers: requestHeaders(config) });

    if (!response.ok) {
        throw new Error(config.strings['error.catalogue_failed']);
    }

    const payload = await response.json();

    return {
        bindings: payload.bindings ?? [],
        // The document's graph (spec 4.1): the nodes offered as "Model", each with
        // the fields offered as "Data source" once that node is chosen.
        nodes: payload.nodes ?? [],
        formats: payload.formats ?? [],
        // The content types a fixed-text code may carry (spec 7.2), each with the
        // fields it declares — configuration, so a new type needs no bundle change.
        codeTypes: payload.code_types ?? {},
        visibilityLimits: payload.visibility_limits ?? {},
        ruleWidths: payload.rule_widths ?? {},
        imageMaxBytes: payload.image_max_bytes ?? 512 * 1024,
        fixture: payload.fixture ?? {},
        zones: payload.zones ?? {},
    };
}

/** The page geometry `print.css` reads, from the same config the compositor uses. */
function pageVariables(config) {
    const sides = ['top', 'right', 'bottom', 'left'];
    // A roll carries its own margins in its geometry (3mm); the `config.margins` from the
    // type is the sheet's. Prefer the geometry's when it has them.
    const margins = config.geometry?.margins ?? config.margins;
    const declarations = sides
        .map((side) => `--margin-${side}:${Number(margins[side] || 0)}mm`)
        .concat([
            `--header-height:${Number(config.zones?.header_height ?? 35)}mm`,
            `--footer-height:${Number(config.zones?.footer_height ?? 20)}mm`,
        ])
        .join(';');

    return `.page{${declarations}}`;
}

/**
 * Keep the tree agreeing with the geometry after a block is moved.
 *
 * A block's zone is read from where it sits in the tree, not from where it is on the
 * page: `LayoutDocument` walks the ancestors and takes the `data-tpl-zone` it finds.
 * GrapesJS's absolute drag mode, though, only rewrites `top` and `left` — it never
 * reparents. So dragging a block out of the header and into the body left it a child of
 * the header, and the structure said one thing while the sheet showed another.
 *
 * That is not cosmetic. The compositor renders the header as a band repeated on every
 * sheet, so a block that looks like it belongs to the body would print on all of them.
 *
 * After a move, the block is put in the zone it now sits in, and its `top` is rewritten
 * to be relative to that band.
 */
function reparentToZone(editor, config, component) {
    const zones = config.zones ?? {};
    const el = component?.getEl?.();
    const page = editor.Canvas.getBody?.()?.querySelector('.page');

    if (!el || !page || !component.getAttributes) {
        return;
    }

    // Only blocks move between zones; a zone itself does not.
    if (!component.getAttributes()['data-tpl-type']) {
        return;
    }

    const marginTop = Number(config.margins?.top ?? 15);
    const header = Number(zones.header_height ?? 0);
    const footer = Number(zones.footer_height ?? 0);
    const pageHeight = (config.paper ?? PAPER_SIZES[config.paperFormat] ?? PAPER_SIZES.A4).height;
    const bodyHeight = pageHeight - marginTop - Number(config.margins?.bottom ?? 15) - header - footer;

    // Where the block's top edge is, in millimetres from the top of the printable area.
    const pageBox = page.getBoundingClientRect();
    const box = el.getBoundingClientRect();
    const fromTop = (box.top - pageBox.top) / PX_PER_MM - marginTop;

    const target = fromTop < header
        ? 'header'
        : (fromTop < header + bodyHeight ? 'body' : 'footer');

    const current = zoneOf(component);

    if (current === target || !current) {
        return;
    }

    const destination = editor.getWrapper()
        ?.find(`[data-tpl-zone="${target}"]`)?.[0];

    if (!destination) {
        return;
    }

    // The band's own offset, so the block keeps the place it was dropped in.
    const bandStart = target === 'header' ? 0 : (target === 'body' ? header : header + bodyHeight);

    destination.append(component);
    component.addStyle({ top: round(fromTop - bandStart) + 'mm' });
}

/** The zone a component currently lives in, by walking its ancestors. */
function zoneOf(component) {
    let node = component?.parent?.();

    while (node) {
        const zone = node.getAttributes?.()['data-tpl-zone'];

        if (zone) {
            return zone;
        }

        node = node.parent?.();
    }

    return null;
}

/** Millimetres, to the half — the unit the rest of the layout is written in. */
function round(value) {
    return Math.round(value * 2) / 2;
}

/**
 * Give the three bands their name and their locks, on the document as loaded.
 *
 * The zone types declare both, but `isComponent` only runs while GrapesJS parses HTML.
 * A version that has been saved once comes back through `loadProjectData`, where each
 * component carries the type it was stored with — and the first save stored them as
 * generic components, because the zone types had no `isComponent` at all until now.
 * So a saved layout keeps nameless, deletable bands however the types are declared.
 *
 * Measured in the layer tree: "Body, Div, Div, Div" where the document's three bands
 * should be. This walks the loaded document and applies the identity by attribute,
 * which works for a fresh parse and for a stored project alike.
 */
function applyZoneIdentity(editor, config) {
    const wrapper = editor.getWrapper();

    if (!wrapper) {
        return;
    }

    wrapper.find('[data-tpl-zone]').forEach((component) => {
        const zone = component.getAttributes()['data-tpl-zone'];
        const name = config.strings['zone.' + zone];

        if (!name) {
            return;
        }

        component.set({
            name,
            'custom-name': name,
            removable: false,
            copyable: false,
            draggable: false,
        });
    });

    // The sheet is the container, not a layer of its own.
    wrapper.find('.page').forEach((component) => component.set({
        name: config.strings['zone.page'],
        layerable: false,
        removable: false,
        copyable: false,
        draggable: false,
    }));
}

/**
 * Re-apply the legal locking to every data field on the canvas.
 *
 * A component's `init()` runs while the model is still being built and its traits do
 * not exist yet, so disabling the binding trait from there silently does nothing. This
 * runs once the document is in place, and again for every field added afterwards.
 */
function lockLegalFields(editor) {
    const wrapper = editor.getWrapper();

    /*
     * There is not always a document to lock, and getting this wrong cost the editor
     * every saved version.
     *
     * `component:add` also fires from inside `loadProjectData`, while GrapesJS builds
     * the loaded page: the wrapper is constructed before the page that owns it is
     * installed, so `getWrapper()` is undefined for the whole of that pass. Throwing
     * here does not just skip the locking — the exception travels up through
     * `loadProjectData` and out of `loadVersion`, so the load stops half done and the
     * editor is left with a canvas of zero height and no blocks. Measured: any version
     * that had been saved once opened blank, because a saved version is exactly the one
     * that comes back through `loadProjectData`.
     *
     * `loadVersion` calls this again when it finishes, with the wrapper in place.
     */
    if (!wrapper) {
        return;
    }

    wrapper
        .find('[data-tpl-type="data-field"]')
        .forEach((component) => component.onBindingChange?.());
}

async function loadVersion(editor, config) {
    const response = await fetch(config.loadUrl, { headers: requestHeaders(config) });

    if (!response.ok) {
        renderStatus(config.strings.loadFailed, config.root);

        return false;
    }

    const payload = await response.json();
    const projectData = payload.data?.project_data;
    let loadedSaved = false;

    // An empty layout means a version that has never been saved: what is already on
    // the canvas came from the document type's initial layout, and loading nothing
    // over it would wipe it.
    if (projectData && Object.keys(projectData).length > 0) {
        editor.loadProjectData(projectData);

        // The page the load installed is a new document: its fields have never been
        // through the locking, and the `component:add` that would have done it fired
        // while the wrapper did not yet exist.
        applyZoneIdentity(editor, config);
        lockLegalFields(editor);
        loadedSaved = true;
    }

    return loadedSaved;
}

/**
 * The email editor: GrapesJS with the MJML preset instead of the page editor.
 *
 * It shares none of the page setup — no paper, zones, rulers, format tabs or page blocks.
 * The MJML plugin bundles mjml-browser, so MJML→HTML is compiled in the browser; the
 * server only stores and serves the compiled HTML. Save, preview and activate reuse the
 * host's own toolbar buttons and the shared network helpers.
 */
async function mountEmailEditor(el, config, chrome) {
    const root = config.root;

    // Email mode: lets the MJML device switch own the frame width (see editor.css).
    root.classList.add('tpl-email');

    const editor = grapesjs.init({
        container: el,
        height: '100%',
        fromElement: false,
        storageManager: false,
        plugins: [grapesjsMjml],
        panels: { defaults: [] },
        blockManager: { appendTo: chrome.blocks },
        styleManager: { appendTo: chrome.styles, sectors: [] },
        traitManager: { appendTo: chrome.traits },
        layerManager: { appendTo: chrome.layers, showWrapper: false },
    });

    config.geometry = config.geometry ?? { medium: 'email' };

    // The page editor gives the mount an explicit pixel size through its zoom; the email
    // editor has no zoom, so the mount would collapse to 0×0 (a grid item with no intrinsic
    // size) and GrapesJS's canvas with it. Size the mount to the canvas pane and keep it in
    // step, so the MJML frame has somewhere to render.
    const sizeToCanvas = () => {
        if (!chrome.canvas) {
            return;
        }

        el.style.width = chrome.canvas.clientWidth + 'px';
        el.style.height = chrome.canvas.clientHeight + 'px';
        editor.refresh?.();
    };

    sizeToCanvas();

    const canvasObserver = new ResizeObserver(sizeToCanvas);

    if (chrome.canvas) {
        canvasObserver.observe(chrome.canvas);
    }

    editor.on('destroy', () => canvasObserver.disconnect());

    // The page toolbar's controls — zoom, rulers, zones, clean view, legal restore — mean
    // nothing for an email; hide them, leaving only the host's Save/Preview/Activate.
    chrome.toolbar?.querySelectorAll('[data-tpl-toggle],[data-tpl-command],[data-tpl-zoom]')
        .forEach((control) => { (control.closest('.tpl-tool') ?? control).hidden = true; });

    // The starting MJML, carried by the email format's `initial_layout.mjml`.
    const initialMjml = (config.pageFormats || [])
        .map((format) => format.initial_layout?.mjml)
        .find(Boolean) || '<mjml><mj-body></mj-body></mjml>';

    try {
        const response = await fetch(config.loadUrl, { headers: requestHeaders(config) });
        const data = response.ok ? await response.json() : {};
        const projectData = data.data?.project_data;

        if (projectData && Object.keys(projectData).length > 0) {
            editor.loadProjectData(projectData);
        } else {
            // The MJML plugin registers the parser, so setting MJML as components parses it.
            editor.setComponents(initialMjml);
        }
    } catch {
        editor.setComponents(initialMjml);
    }

    const marker = root.querySelector('[data-templates-dirty]');
    let dirty = false;

    const announce = (isDirty) => {
        dirty = isDirty;

        if (marker) {
            marker.hidden = !isDirty;
        }

        root.dispatchEvent(new CustomEvent('templates:dirty', { detail: { dirty: isDirty }, bubbles: true }));
    };

    // Loading the starting MJML fires `update` too; those are not the user's edits, so
    // ignore everything until the first render settles, or the email opens announcing
    // unsaved changes it never had.
    let ready = false;

    editor.on('update', () => {
        if (ready && !dirty) {
            announce(true);
            renderStatus(config.strings.unsaved, root);
        }
    });

    requestAnimationFrame(() => requestAnimationFrame(() => { ready = true; }));

    // The compiled, inlined email HTML and the editor's own project data.
    const saveEmail = async () => {
        const compiled = editor.runCommand('mjml-code-to-html');

        if (compiled?.errors?.length) {
            const error = new Error(config.strings.saveFailed);
            error.problems = compiled.errors.map((problem) => ({
                code: 'MJML', page: null, message: problem.message ?? String(problem),
            }));

            throw error;
        }

        const response = await fetch(config.saveUrl, {
            method: 'PUT',
            headers: { ...requestHeaders(config), 'Content-Type': 'application/json' },
            body: JSON.stringify({
                project_data: editor.getProjectData(),
                html: compiled.html,
                css: '',
                geometry: config.geometry,
            }),
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const error = new Error(payload.message || config.strings.saveFailed);
            error.problems = payload.problems ?? [];

            throw error;
        }

        announce(false);

        return response.json();
    };

    root.querySelector('[data-tpl-save]')?.addEventListener('click', async () => {
        renderStatus(config.strings.saving, root);

        try {
            await saveEmail();
            renderStatus(config.strings.saved, root);
            root.dispatchEvent(new CustomEvent('templates:saved', { bubbles: true }));
        } catch (error) {
            renderStatus(error.message, root);
            root.dispatchEvent(new CustomEvent('templates:validation-failed', {
                detail: { problems: error.problems ?? [] }, bubbles: true,
            }));
        }
    });

    root.querySelector('[data-tpl-preview]')?.addEventListener('click', async () => {
        renderStatus(config.strings.rendering, root);

        try {
            if (dirty && config.editable) {
                await saveEmail();
            }

            // The email preview endpoint serves the stored HTML; a blob URL opens it as a page.
            const url = await renderPreview(config);

            window.open(url, '_blank');
            renderStatus('', root);
        } catch (error) {
            renderStatus(error.message, root);
        }
    });

    root.querySelector('[data-tpl-activate]')?.addEventListener('click', async () => {
        renderStatus(config.strings.activating, root);

        try {
            if (dirty) {
                await saveEmail();
            }

            await activateVersion(config);
            renderStatus(config.strings.activated, root);
            root.dispatchEvent(new CustomEvent('templates:activated', { bubbles: true }));
        } catch (error) {
            renderStatus(error.message, root);
        }
    });

    root.dispatchEvent(new CustomEvent('templates:loaded', { bubbles: true }));
}

async function saveVersion(editor, config) {
    const response = await fetch(config.saveUrl, {
        method: 'PUT',
        headers: { ...requestHeaders(config), 'Content-Type': 'application/json' },
        body: JSON.stringify({
            project_data: editor.getProjectData(),
            html: editor.getHtml(),
            css: editor.getCss(),
            // The page format picked in the toolbar; the host persists it.
            geometry: config.geometry ?? null,
        }),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        const error = new Error(payload.message || config.strings.saveFailed);
        error.problems = payload.problems ?? [];

        throw error;
    }

    return response.json();
}

/** @returns {Promise<string>} a blob URL for the rendered PDF. */
async function renderPreview(config) {
    const response = await fetch(config.previewUrl, {
        method: 'POST',
        headers: requestHeaders(config),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));

        throw new Error(payload.message || config.strings.previewFailed);
    }

    return URL.createObjectURL(await response.blob());
}

/**
 * Put the stored version into production.
 *
 * The problems it can come back with are the R-xx rules: they name a page rather than a
 * component, because they were measured on the rendered PDF.
 */
async function activateVersion(config) {
    const response = await fetch(config.activateUrl, {
        method: 'POST',
        headers: requestHeaders(config),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        const error = new Error(payload.message || config.strings.activateFailed);
        error.problems = payload.problems ?? [];

        throw error;
    }

    return response.json();
}

function requestHeaders(config) {
    return {
        'X-CSRF-TOKEN': config.csrfToken,
        ...(config.tenantHeader ? { [config.tenantHeader]: config.tenantId } : {}),
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    };
}

/** A stable string for the current layout, to tell a real change from a redraw. */
function snapshot(editor) {
    return JSON.stringify(editor.getProjectData());
}

/**
 * @param {string} message
 * @param {ParentNode} [scope]  the widget's root; the document only as a last resort
 */
function renderStatus(message, scope) {
    const target = (scope ?? document).querySelector('[data-tpl-status]');

    if (target) {
        target.textContent = message || '';
    }
}

/**
 * The banner that explains a refusal, in place of a browser `alert()`.
 *
 * The timer is global to the editor on purpose: two notices in a row replace each
 * other, and the second does not inherit the first one's countdown.
 */
let noticeTimer = null;

/**
 * @param {string} message
 * @param {ParentNode} [scope]  the widget's root; the document only as a last resort
 * @param {string} [closeLabel]  `aria-label` for the icon-only close button — the button
 *   carries no visible text, so a screen reader needs it announced explicitly
 */
function showNotice(message, scope, closeLabel) {
    const target = (scope ?? document).querySelector('[data-tpl-notice]');

    if (!target) {
        return;
    }

    target.innerHTML =
        icons.warning + `<span>${escapeHtml(message)}</span>`
        + '<button type="button" class="tpl-notice-close" data-tpl-notice-close '
        + `aria-label="${escapeHtml(closeLabel ?? '')}">`
        + icons.close + '</button>';

    target.hidden = false;

    target.querySelector('[data-tpl-notice-close]')?.addEventListener('click', () => {
        target.hidden = true;
    });

    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => {
        target.hidden = true;
    }, 6000);
}
