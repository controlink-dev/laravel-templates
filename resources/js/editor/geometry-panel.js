import { roundTo } from './anchors.js';

/**
 * A version's page margins and header/footer heights (CGE-50).
 *
 * They used to come from the document type alone, and the "Height" the style panel
 * offered on a header or footer only stretched the element: the body stayed where the
 * type put it and the PDF ignored the change. Now they are the version's own, kept in its
 * `geometry` like a custom page size, and edited here:
 *
 * - the sheet selected (a click on its margin): the four margins and both heights;
 * - a header or footer selected: that band's height.
 *
 * A label has no bands (heights of 0) and gets the margins only; a roll gets nothing.
 */

/** The least height the body keeps between header and footer — the server's rule too. */
export const MIN_BODY_MM = 20;

const SIDES = ['top', 'right', 'bottom', 'left'];

/**
 * A change applied to the geometry, rounded to the tenth and never negative.
 *
 * `effective` is what the page is laid out with now (`{margins, zones}`), `paper` the
 * sheet size in mm. A change that leaves the body under `MIN_BODY_MM`, or no width
 * between the side margins, is refused: the geometry comes back unchanged with the
 * string key of the reason.
 *
 * @returns {{geometry: object, error: string|null}}
 */
export function applyGeometryChange(geometry, effective, change, paper) {
    const mm = (value) => Math.max(0, roundTo(Number(value) || 0, 0.1));

    const margins = { ...effective.margins };
    const zones = { ...effective.zones };

    Object.entries(change.margins ?? {}).forEach(([side, value]) => {
        margins[side] = mm(value);
    });
    Object.entries(change.zones ?? {}).forEach(([key, value]) => {
        zones[key] = mm(value);
    });

    const n = (value) => Number(value) || 0;
    const body = paper.height - n(margins.top) - n(margins.bottom) - n(zones.header_height) - n(zones.footer_height);

    if (body < MIN_BODY_MM) {
        return { geometry, error: 'geometry.body_too_small' };
    }

    if (n(margins.left) + n(margins.right) >= paper.width) {
        return { geometry, error: 'geometry.margins_too_wide' };
    }

    return { geometry: { ...geometry, margins, zones }, error: null };
}

/**
 * The panel, above the style sectors. `read()` returns `{geometry, effective, paper,
 * mode}` for the current page; `onChange(geometry)` applies an accepted change.
 */
export function mountGeometryPanel(editor, config, { root, read, onChange }) {
    if (!root) {
        return { refresh() {} };
    }

    const panel = document.createElement('div');
    panel.className = 'tpl-geometry';
    panel.hidden = true;
    root.prepend(panel);

    const strings = config.strings;

    const field = (label, value, apply) => {
        const wrap = document.createElement('label');
        wrap.className = 'tpl-geometry__field';

        const caption = document.createElement('span');
        caption.textContent = label;

        const input = document.createElement('input');
        input.type = 'number';
        input.step = '0.1';
        input.min = '0';
        input.className = 'gjs-field';
        input.value = String(roundTo(Number(value) || 0, 0.1));

        const unit = document.createElement('span');
        unit.textContent = 'mm';

        input.addEventListener('change', () => apply(input.value));
        wrap.append(caption, input, unit);

        return wrap;
    };

    const error = document.createElement('p');
    error.className = 'tpl-geometry__error';
    error.setAttribute('role', 'alert');

    function render() {
        const selected = editor.getSelected();
        const state = read();
        const zone = selected?.getAttributes?.()['data-tpl-zone'];
        const isPage = selected?.getClasses?.().includes('page');
        const hasBands = Number(state.effective.zones.header_height) > 0 || Number(state.effective.zones.footer_height) > 0;
        const editable = config.editable !== false && state.mode !== 'roll';

        panel.replaceChildren();
        error.textContent = '';

        const keys = [];

        if (editable && isPage) {
            SIDES.forEach((side) => keys.push(['margins', side, strings['geometry.margin.' + side]]));

            if (hasBands) {
                keys.push(['zones', 'header_height', strings['geometry.header_height']]);
                keys.push(['zones', 'footer_height', strings['geometry.footer_height']]);
            }
        } else if (editable && hasBands && (zone === 'header' || zone === 'footer')) {
            keys.push(['zones', zone + '_height', strings['geometry.' + zone + '_height']]);
        }

        panel.hidden = keys.length === 0;

        if (panel.hidden) {
            return;
        }

        const title = document.createElement('div');
        title.className = 'tpl-geometry__title';
        title.textContent = strings['geometry.page'];
        panel.append(title);

        keys.forEach(([group, key, label]) => {
            panel.append(field(label, state.effective[group][key], (value) => {
                const current = read();
                const result = applyGeometryChange(current.geometry, current.effective, { [group]: { [key]: value } }, current.paper);

                if (result.error) {
                    render();
                    error.textContent = strings[result.error] ?? result.error;
                    panel.append(error);

                    return;
                }

                onChange(result.geometry);
                render();
            }));
        });
    }

    editor.on('component:toggled', render);

    return { refresh: render };
}
