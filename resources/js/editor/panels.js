/* ============================================================================
 * Editor panels: reduced style manager, layers, legal field repair and the local
 * indicative validation (spec 6.6, RF-L03, RF-L04).
 *
 * Every check in this file is a courtesy to the user, shown while they work. None of
 * it is a guarantee: the decisive validation is the server's (spec 3.2, last bullet).
 * ========================================================================= */

import { resolvePaper } from './editor.js';
import { defaultSpecFor, specToAttributes } from './formats.js';
import { PX_PER_MM, roundTo, snapOffsets } from './anchors.js';

/**
 * The Style Manager the user gets: typography, colour, box and position, and nothing
 * else. No flex, no grid, no transitions — properties that mean nothing on paper and
 * that the PDF engine would ignore or, worse, honour in a way the canvas does not.
 */
/**
 * The style panel, declared property by property.
 *
 * Three attempts got here. Passing the property names as strings keeps GrapesJS's own
 * definitions and its own English labels, and it has no translation layer. Passing
 * `{ property, label }` translates the label and *replaces* the definition — measured,
 * the size field came out a plain text box, losing the units every legibility rule
 * depends on. Setting the label afterwards on the model does not re-render it.
 *
 * So each property is declared in full. Which is the better answer anyway: the units of
 * a template are millimetres and points, never pixels, and the choices a user is
 * offered for a weight should be "Normal" and "Negrito" rather than 100 to 900.
 */
export function styleManagerSectors(strings, remembered = {}) {
    /*
     * Every property carries a default, and that is what makes the panel readable.
     *
     * Without one, a property the layout does not set shows a dash: the user opens
     * "Contorno" and sees three empty boxes with no idea what they would do. The
     * default is shown as the field's own hint and is the value `print.css` already
     * applies, so what the panel says and what the sheet does agree.
     */
    const number = (property, key, units, extra = {}) => ({
        property,
        label: strings['prop.' + key],
        type: 'number',
        units,
        unit: units[0],
        min: 0,
        ...extra,
    });

    const choice = (property, key, options) => ({
        property,
        label: strings['prop.' + key],
        type: 'select',
        default: options[0].id,
        options,
    });

    const colour = (property, key, fallback) => ({
        property,
        label: strings['prop.' + key],
        type: 'color',
        default: fallback,
    });

    // The sections the user left open or closed win over the defaults (CGE-53).
    return [
        {
            id: 'tpl-typography',
            name: strings['sector.typography'],
            open: true,
            properties: [
                number('font-size', 'font_size', ['pt'], { min: 1, step: 0.5, default: '9pt' }),
                choice('font-weight', 'font_weight', [
                    { id: '400', label: strings['weight.normal'] },
                    { id: '700', label: strings['weight.bold'] },
                ]),
                choice('font-style', 'font_style', [
                    { id: 'normal', label: strings['style.normal'] },
                    { id: 'italic', label: strings['style.italic'] },
                ]),
                choice('text-align', 'text_align', [
                    { id: 'left', label: strings['align.left'] },
                    { id: 'center', label: strings['align.center'] },
                    { id: 'right', label: strings['align.right'] },
                    { id: 'justify', label: strings['align.justify'] },
                ]),
                number('line-height', 'line_height', ['pt'], { default: '11pt' }),
                number('letter-spacing', 'letter_spacing', ['pt'], { default: '0pt' }),
                colour('color', 'color', '#000000'),
            ],
        },
        {
            // Exact placement by number: dragging an absolute block over a zoomed canvas is
            // imprecise, so the offsets are editable here in millimetres. All four sides
            // (CGE-51): the starting layouts pin totals to the right and the bottom, and a
            // panel without them showed those blocks as unplaced. No `min`: a block a hair
            // off the sheet has to show its negative offset, not a reassuring 0. Writing
            // one side drops the opposite one (position-panel.js), and the hint shows the
            // measured value for a side the block does not set.
            id: 'tpl-position',
            name: strings['sector.position'],
            open: false,
            properties: [
                number('left', 'left', ['mm'], { min: undefined, default: '' }),
                number('top', 'top', ['mm'], { min: undefined, default: '' }),
                number('right', 'right', ['mm'], { min: undefined, default: '' }),
                number('bottom', 'bottom', ['mm'], { min: undefined, default: '' }),
            ],
        },
        {
            id: 'tpl-box',
            name: strings['sector.box'],
            open: false,
            properties: [
                // No fixed default: the hint is the measured size (position-panel.js),
                // never a 180mm the block does not have.
                number('width', 'width', ['mm'], { default: '' }),
                number('height', 'height', ['mm'], { default: '' }),
                number('padding', 'padding', ['mm'], { default: '0mm' }),
                colour('background-color', 'background_color', 'transparent'),
            ],
        },
        {
            id: 'tpl-border',
            name: strings['sector.border'],
            open: false,
            properties: [
                number('border-width', 'border_width', ['mm'], { default: '0mm' }),
                choice('border-style', 'border_style', [
                    { id: 'none', label: strings['border.none'] },
                    { id: 'solid', label: strings['border.solid'] },
                    { id: 'dashed', label: strings['border.dashed'] },
                    { id: 'dotted', label: strings['border.dotted'] },
                ]),
                colour('border-color', 'border_color', '#000000'),
            ],
        },
    ].map((sector) => ({ ...sector, open: remembered[sector.id] ?? sector.open }));
}

/**
 * Insert whichever legal fields are absent from the document, in their default
 * position (RF-L03).
 *
 * A version created through the API, or one whose document type gained a legal field
 * after it was drawn, can legitimately be missing blocks. The alternative to this
 * button is a version that can never be activated and no way to see why.
 *
 * @returns {number} how many blocks were inserted
 */
export function restoreMissingLegalFields(editor, config) {
    const wrapper = editor.getWrapper();

    // Legal TEXT fields already on the sheet, by binding.
    const presentFields = new Set(
        wrapper.find('[data-tpl-type="data-field"]')
            .map((component) => component.getAttributes()['data-tpl-binding']),
    );

    // A legal qr/barcode is a code-slot, not a data field. The server's `checkLegalCode`
    // matches it by `data-tpl-binding === key` (V-10), so a slot already bound to the legal
    // key is what counts as present — a fixed, unbound slot never satisfies it.
    const presentCodes = new Set(
        wrapper.find('[data-tpl-type="code-slot"]')
            .map((component) => component.getAttributes()['data-tpl-binding'])
            .filter(Boolean),
    );

    const missingFields = config.bindings.filter(
        (binding) => binding.legal && binding.type !== 'qr' && binding.type !== 'barcode'
            && !presentFields.has(binding.key),
    );

    const missingCodes = config.bindings.filter(
        (binding) => binding.legal && (binding.type === 'qr' || binding.type === 'barcode')
            && !presentCodes.has(binding.key),
    );

    // `every_page` fields have to live in a repeating zone, or they cannot appear on every
    // page (spec 6.3). The rest land in the body.
    const zoneFor = (scope) =>
        wrapper.find('.' + (scope === 'every_page' ? 'zone--header' : 'zone--body'))[0];

    missingFields.forEach((binding, index) => {
        const zone = zoneFor(binding.scope);

        if (!zone) {
            return;
        }

        zone.append({
            type: 'data-field',
            attributes: {
                class: 'block block--field',
                'data-tpl-type': 'data-field',
                'data-tpl-binding': binding.key,
                ...defaultFormatFor(binding),
            },
            style: {
                position: 'absolute',
                left: '10mm',
                top: 10 + index * 6 + 'mm',
                'font-size': (config.visibilityLimits.font_size_min_pt ?? 7) + 'pt',
                color: '#000000',
            },
        });
    });

    // Each restored code slot is born bound to its legal key (dynamic) and sized at the legal
    // minimum, so it satisfies V-10 (present) and V-17 (big enough) the moment it lands; the
    // user then drags it where it belongs. Stacked below the text column, same left edge, so a
    // fresh restore never overflows a narrow page (A5, roll) or overlaps the text fields.
    const codeSide = Number(config.visibilityLimits?.qr_size_min_mm ?? 30);
    const codesTop = 10 + missingFields.length * 6 + 4;

    missingCodes.forEach((binding, index) => {
        const zone = zoneFor(binding.scope);

        if (!zone) {
            return;
        }

        const lastDot = binding.key.lastIndexOf('.');

        zone.append({
            type: 'code-slot',
            attributes: {
                class: 'block block--code',
                'data-tpl-type': 'code-slot',
                'data-tpl-code-source': 'dynamic',
                'data-tpl-node': lastDot === -1 ? binding.key : binding.key.slice(0, lastDot),
                'data-tpl-binding': binding.key,
            },
            style: {
                position: 'absolute',
                left: '10mm',
                top: codesTop + index * (codeSide + 4) + 'mm',
                width: codeSide + 'mm',
                height: codeSide + 'mm',
            },
        });
    });

    const missingTables = missingMandatoryTables(editor, config);
    const body = wrapper.find('.zone--body')[0];

    if (body) {
        missingTables.forEach((binding) => restoreTable(editor, config, body, binding));
    }

    return missingFields.length + missingCodes.length + (body ? missingTables.length : 0);
}

/** The mandatory collections (the invoice's lines, its tax summary) with no table bound. */
function missingMandatoryTables(editor, config) {
    const bound = new Set(
        editor.getWrapper().find('[data-tpl-type="line-table"]')
            .map((component) => component.getAttributes()['data-tpl-collection']),
    );

    return (config.bindings ?? []).filter(
        (binding) => binding.type === 'collection' && binding.mandatory && !bound.has(binding.key),
    );
}

/**
 * A mandatory table put back (CGE-52): bound to its collection, under the last table of
 * the body (the flow stacks tables by their top), the printable width, with the declared
 * columns — on a talão without those marked `roll: false`, which do not fit 58mm.
 */
function restoreTable(editor, config, body, binding) {
    const tables = body.find('[data-tpl-type="line-table"]');
    const below = tables.reduce((bottom, table) => {
        const box = measuredBoxOf(table);

        return box ? Math.max(bottom, box.top + box.height) : bottom;
    }, -4);

    const margins = config.geometry?.margins ?? config.margins ?? {};
    const paperWidth = Number(config.paper?.width ?? 210);
    const width = paperWidth - Number(margins.left ?? 15) - Number(margins.right ?? 15);
    const isRoll = config.geometry?.medium === 'roll';

    // A talão flows in tree order: the table goes right after the last one, not at the
    // very end under the certification.
    const last = tables[tables.length - 1];
    const parent = isRoll && last ? last.parent() : body;
    const at = isRoll && last ? last.index() + 1 : undefined;

    const [table] = parent.append({
        type: 'line-table',
        attributes: {
            class: isRoll ? 'flow' : 'block block--lines',
            'data-tpl-type': 'line-table',
            'data-tpl-collection': binding.key,
        },
        // The starting layouts' table size.
        style: isRoll ? { 'font-size': '7pt' } : {
            position: 'absolute',
            left: '0mm',
            top: roundTo(below + 4, 0.5) + 'mm',
            width: roundTo(width, 0.5) + 'mm',
            'font-size': '7pt',
        },
    }, at === undefined ? {} : { at });

    table?.setColumns?.(table.defaultColumns().filter(
        (column) => !isRoll || binding.columns?.find((declared) => declared.key === column.key)?.roll !== false,
    ));
    table?.renderPreview?.();
}

/** A block's box in mm inside its band, from the canvas (null before it is rendered). */
function measuredBoxOf(component) {
    const el = component.getEl?.();

    if (!el) {
        return null;
    }

    return { top: el.offsetTop / PX_PER_MM, height: el.offsetHeight / PX_PER_MM };
}

/**
 * Live check (CGE-52): a mandatory collection with no table. Its own code, not V-02: a
 * local V-02 would hide the server's V-02 for single mandatory fields.
 */
export function checkMandatoryTablesLocally(editor, config) {
    return missingMandatoryTables(editor, config).map((binding) => ({
        code: 'L-02',
        componentId: '',
        message: format(config.strings['check.missing_table'], { field: binding.label }),
    }));
}

/**
 * The indicative checks, run in the browser on every change (RF-L04).
 *
 * They cover only what can be decided locally and cheaply: the limits that are a
 * property of one block's own resolved style. Contrast against an effective
 * background, occlusion between blocks and truncation against the longest fixture
 * value need layout and are the server's job (V-12, V-15, the truncation rule).
 *
 * @returns {Array<{code: string, componentId: string, message: string}>}
 */
export function checkLegalFieldsLocally(editor, config) {
    const problems = [];
    const limits = config.visibilityLimits ?? {};
    const fields = editor.getWrapper().find('[data-tpl-type="data-field"]');

    const present = new Map();
    fields.forEach((component) => present.set(component.getAttributes()['data-tpl-binding'], component));

    config.bindings
        .filter((binding) => binding.legal && binding.type !== 'qr' && binding.type !== 'barcode')
        .forEach((binding) => {
            if (!present.has(binding.key)) {
                problems.push({
                    code: 'V-10',
                    componentId: '',
                    message: format(config.strings['check.missing'], { field: binding.label }),
                });
            }
        });

    fields.forEach((component) => {
        const binding = config.bindings.find((item) => item.key === component.getAttributes()['data-tpl-binding']);

        if (!binding?.legal) {
            return;
        }

        const style = component.getStyle();
        const id = component.getId();

        const fontSize = parsePoints(style['font-size']);
        const minimum = Number(limits.font_size_min_pt ?? 7);

        if (fontSize !== null && fontSize < minimum) {
            problems.push({
                code: 'V-11',
                componentId: id,
                message: format(config.strings['check.font_size'], {
                    field: binding.label,
                    minimum,
                }),
            });
        }

        const opacity = style.opacity === undefined ? 1 : Number(style.opacity);

        if (opacity < Number(limits.opacity_min ?? 1)) {
            problems.push({
                code: 'V-13',
                componentId: id,
                message: format(config.strings['check.opacity'], { field: binding.label }),
            });
        }

        if (style.display === 'none' || style.visibility === 'hidden') {
            problems.push({
                code: 'V-13',
                componentId: id,
                message: format(config.strings['check.hidden'], { field: binding.label }),
            });
        }

        // `every_page` cannot be satisfied from the body: the body flows and does
        // not repeat (spec 6.3). A roll is a single page, so it satisfies `every_page`
        // wherever the field sits (the server skips V-16 for a roll the same way).
        if (config.geometry?.medium !== 'roll' && binding.scope === 'every_page' && !isInRepeatingZone(component)) {
            problems.push({
                code: 'V-16',
                componentId: id,
                message: format(config.strings['check.scope'], { field: binding.label }),
            });
        }
    });

    return problems;
}

/** Whether the component sits in a zone that repeats on every page. */
function isInRepeatingZone(component) {
    let parent = component.parent();

    while (parent) {
        const classes = parent.getClasses();

        if (classes.includes('zone--header') || classes.includes('zone--footer')) {
            return true;
        }

        if (classes.includes('zone--body')) {
            return false;
        }

        parent = parent.parent();
    }

    return false;
}

/**
 * Live "off the sheet" check (CGE-25 Peça 2).
 *
 * The server validates geometry only in the absolute zones (header/footer) at save;
 * the body is measured at render (spec §2). So a block dragged off the sheet in the
 * body gets no immediate warning. This fills that gap in the browser, comparing each
 * block's box against the printable area on the canvas (sheet minus margins, what R-04
 * measures) — compared in rendered pixels, so it holds whatever unit a drag leaves
 * behind. Header/footer keep the server's save-time V-04/V-14, so this skips them.
 *
 * It emits a DEDICATED code `L-OUT` (not V-04/V-14): see problems.js — reusing those
 * in LOCAL_CHECK_CODES would drop the server copies the browser cannot fully decide.
 *
 * @returns {Array<{code: string, componentId: string, message: string}>}
 */
export function checkOffSheetLocally(editor, config) {
    const metrics = pageMetrics(editor, config);

    if (!metrics) {
        return [];
    }

    const isRoll = config.geometry?.medium === 'roll';
    const problems = [];

    editor.getWrapper().find('[data-tpl-type]').forEach((component) => {
        // Header/footer are absolute zones the server already guards at save; the body
        // is where the server is silent, so that is what the live check covers.
        if (isInRepeatingZone(component)) {
            return;
        }

        if (!isOffSheet(component, metrics, isRoll)) {
            return;
        }

        problems.push({
            code: 'L-OUT',
            componentId: component.getId(),
            message: format(config.strings['check.off_sheet'], { field: blockLabel(component, config) }),
        });
    });

    return problems;
}

/**
 * The printable area of the sheet on the canvas, plus the mm/px scale and tolerance.
 *
 * The bound is the sheet MINUS its margins — the same box the server's R-04 measures
 * against. Snapping to the sheet edge would leave a block in the margin, which R-04
 * still rejects, so both the live check and the snap use the printable area, not the
 * paper edge. Returns null while the canvas is not rendered yet (the check stays silent).
 */
function pageMetrics(editor, config) {
    const page = editor.getWrapper().find('.page')[0];
    const el = page?.getEl();

    if (!el) {
        return null;
    }

    const pageRect = el.getBoundingClientRect();

    if (!(pageRect.width > 0)) {
        return null;
    }

    const paper = resolvePaper(config.geometry);
    const pxPerMm = pageRect.width / paper.width;
    const margins = config.geometry?.margins ?? config.margins ?? {};
    const mm = (side) => Number(margins[side] || 0);

    const printable = {
        left: pageRect.left + mm('left') * pxPerMm,
        top: pageRect.top + mm('top') * pxPerMm,
        right: pageRect.right - mm('right') * pxPerMm,
        bottom: pageRect.bottom - mm('bottom') * pxPerMm,
    };

    return {
        pageEl: el,
        printable,
        printableWidthMm: paper.width - mm('left') - mm('right'),
        pxPerMm,
        tolPx: 2,
    };
}

/**
 * How far a block's box sits outside the printable area, in canvas pixels.
 *
 * `dx`/`dy` are the shift to apply to bring the block inside (0 when inside). On a roll
 * the height is free (overflow:visible), so only the horizontal axis is measured.
 *
 * @returns {{dx: number, dy: number}}
 */
function sheetOverflow(block, bounds, isRoll, tol) {
    let dx = 0;
    let dy = 0;

    if (block.left < bounds.left - tol) {
        dx = bounds.left - block.left;
    } else if (block.right > bounds.right + tol) {
        dx = bounds.right - block.right;
    }

    if (!isRoll) {
        if (block.top < bounds.top - tol) {
            dy = bounds.top - block.top;
        } else if (block.bottom > bounds.bottom + tol) {
            dy = bounds.bottom - block.bottom;
        }
    }

    return { dx, dy };
}

/** Whether a rendered component's box falls outside the printable area. */
function isOffSheet(component, metrics, isRoll) {
    const el = component.getEl();

    if (!el) {
        return false;
    }

    const { dx, dy } = sheetOverflow(el.getBoundingClientRect(), metrics.printable, isRoll, metrics.tolPx);

    return dx !== 0 || dy !== 0;
}

/** A readable name for a block in a problem message: its binding label, else its own name. */
function blockLabel(component, config) {
    const binding = config.bindings?.find((item) => item.key === component.getAttributes()['data-tpl-binding']);

    return binding?.label
        || component.get('custom-name')
        || component.getName();
}

/**
 * "Bring onto the sheet" (CGE-25 Peça 3): clamp off-sheet blocks into the printable area.
 *
 * An off-sheet block sits off the canvas and cannot be clicked to select, so requiring a
 * selection would strand it. So: if the selected block is itself the off-sheet one, bring
 * just that one back; otherwise bring back EVERY off-sheet block. Each shift is applied to
 * the block's own `left`/`top` (in mm, the saved unit), so it works whatever positioning
 * context the block sits in and lands inside the printable area (the server's R-04 passes
 * too). On a roll the height is free, so only the horizontal axis is clamped and the width
 * capped at the printable width.
 *
 * @returns {number} how many blocks were brought back (0 if none were off-sheet).
 */
export function snapIntoSheet(editor, config) {
    const metrics = pageMetrics(editor, config);

    if (!metrics) {
        return 0;
    }

    const isRoll = config.geometry?.medium === 'roll';
    const tolMm = Number(config.geometry?.tolerance_mm ?? 0);
    const selected = editor.getSelected();

    const targets = (selected && isOffSheet(selected, metrics, isRoll))
        ? [selected]
        : editor.getWrapper().find('[data-tpl-type]')
            .filter((component) => !isInRepeatingZone(component) && isOffSheet(component, metrics, isRoll));

    let moved = 0;

    targets.forEach((component) => {
        if (snapComponent(component, metrics, isRoll, tolMm)) {
            moved += 1;
        }
    });

    return moved;
}

/** Clamp one block into the printable area. Returns true if it moved. */
function snapComponent(component, metrics, isRoll, tolMm) {
    const el = component.getEl();

    if (!el) {
        return false;
    }

    const pxPerMm = metrics.pxPerMm;
    const rect = el.getBoundingClientRect();
    const { dx, dy } = sheetOverflow(rect, metrics.printable, isRoll, metrics.tolPx);
    const style = component.getStyle();

    // CGE-51: the side the block is anchored by is corrected (a block pinned right gets
    // a new `right`, not a `left` beside it), rounded to the tenth of a millimetre.
    const overflow = {};

    if (dx > 0) {
        overflow.left = dx / pxPerMm;
    } else if (dx < 0) {
        overflow.right = -dx / pxPerMm;
    }

    if (!isRoll && dy > 0) {
        overflow.top = dy / pxPerMm;
    } else if (!isRoll && dy < 0) {
        overflow.bottom = -dy / pxPerMm;
    }

    const { set: next } = snapOffsets(style, overflow);

    if (isRoll && rect.width / pxPerMm > metrics.printableWidthMm + tolMm) {
        next.width = roundTo(metrics.printableWidthMm, 0.1) + 'mm';
    }

    if (Object.keys(next).length === 0) {
        return false;
    }

    component.addStyle(next);

    return true;
}

/**
 * Font size in points.
 *
 * Only `pt` and `mm` are accepted, because those are the units the reduced style
 * manager offers. Anything else returns null and the check stays silent rather than
 * inventing a number — a wrong "too small" warning on a field that is fine would
 * teach the user to ignore the panel.
 */
function parsePoints(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const match = value.trim().match(/^([\d.]+)(pt|mm)$/);

    if (!match) {
        return null;
    }

    const amount = Number(match[1]);

    return match[2] === 'mm' ? amount * (72 / 25.4) : amount;
}

/** The format attributes a restored legal field starts with: its type's default (CGE-49). */
function defaultFormatFor(binding) {
    return specToAttributes(defaultSpecFor(binding.type) ?? (binding.default_format ? { name: binding.default_format } : null));
}

function format(template, replacements) {
    return Object.entries(replacements).reduce(
        (text, [key, value]) => text.replace(':' + key, String(value)),
        String(template ?? ''),
    );
}

/**
 * Live check: a block on several lines whose text does not fit its box (CGE-52). What does
 * not fit is cut in the PDF, so this warns; for a legal value the activation refuses it.
 */
export function checkTextOverflowLocally(editor, config) {
    const problems = [];

    editor.getWrapper()?.find('[data-tpl-wrap="1"]').forEach((component) => {
        const el = component.getEl?.();

        if (el && el.scrollHeight > el.clientHeight + 1) {
            problems.push({
                code: 'L-WRAP',
                componentId: component.getId(),
                message: format(config.strings['check.text_overflow'], { field: blockLabel(component, config) }),
            });
        }
    });

    return problems;
}
