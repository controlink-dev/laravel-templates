/* ============================================================================
 * Editor panels: reduced style manager, layers, legal field repair and the local
 * indicative validation (spec 6.6, RF-L03, RF-L04).
 *
 * Every check in this file is a courtesy to the user, shown while they work. None of
 * it is a guarantee: the decisive validation is the server's (spec 3.2, last bullet).
 * ========================================================================= */

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
export function styleManagerSectors(strings) {
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
            id: 'tpl-box',
            name: strings['sector.box'],
            open: false,
            properties: [
                number('width', 'width', ['mm'], { default: '180mm' }),
                number('height', 'height', ['mm'], { default: '0mm' }),
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
        {
            id: 'tpl-position',
            name: strings['sector.position'],
            open: false,
            properties: [
                number('top', 'top', ['mm'], { default: '0mm' }),
                number('left', 'left', ['mm'], { default: '0mm' }),
            ],
        },
    ];
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
    const present = new Set(
        editor.getWrapper()
            .find('[data-tpl-type="data-field"]')
            .map((component) => component.getAttributes()['data-tpl-binding']),
    );

    const missing = config.bindings.filter(
        (binding) => binding.legal && binding.type !== 'qr' && binding.type !== 'barcode'
            && !present.has(binding.key),
    );

    missing.forEach((binding, index) => {
        // `every_page` fields have to live in a repeating zone, or they cannot
        // appear on every page (spec 6.3). The rest land in the body.
        const zoneClass = binding.scope === 'every_page' ? 'zone--header' : 'zone--body';
        const zone = editor.getWrapper().find('.' + zoneClass)[0];

        if (!zone) {
            return;
        }

        zone.append({
            type: 'data-field',
            attributes: {
                class: 'block block--field',
                'data-tpl-type': 'data-field',
                'data-tpl-binding': binding.key,
                'data-tpl-format': defaultFormatFor(binding),
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

    return missing.length;
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
        // not repeat (spec 6.3).
        if (binding.scope === 'every_page' && !isInRepeatingZone(component)) {
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

function defaultFormatFor(binding) {
    return binding.default_format ?? '';
}

function format(template, replacements) {
    return Object.entries(replacements).reduce(
        (text, [key, value]) => text.replace(':' + key, String(value)),
        String(template ?? ''),
    );
}
