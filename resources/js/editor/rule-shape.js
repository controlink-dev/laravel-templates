/** Thicker than the thickest rule (0.79mm) with room to spare: no line is this tall. */
const LINE_MAX_MM = 1.5;

const BLACK = ['#000', '#000000', 'black', 'rgb(0, 0, 0)', 'rgb(0,0,0)'];

/**
 * Whether a line/rectangle block's style describes a filled shape rather than a rule
 * (CGE-76): taller than any rule thickness, or a fill colour other than black. An
 * unfilled rule is always black with its thickness as its height, so either difference
 * means someone made a rectangle.
 */
export function looksFilled(style = {}) {
    const height = String(style.height ?? '');
    const millimetres = height.endsWith('mm') ? parseFloat(height) : NaN;
    const background = String(style['background-color'] ?? '').trim().toLowerCase();

    return (Number.isFinite(millimetres) && millimetres > LINE_MAX_MM)
        || (background !== '' && !BLACK.includes(background));
}

/**
 * The style a line/rectangle block writes (CGE-97). On a sheet, as ever: a filled shape
 * keeps its own height and fill (black if none); an unfilled one is a rule, its thickness
 * its height. On a roll, nothing: the printer draws a rule as a line of dashes across the
 * grid, and a size or a colour on it is a style it cannot print (V-21).
 */
export function ruleStyle({ roll, thickness, filled, style = {} }) {
    if (roll) {
        return {};
    }

    return filled
        ? { 'background-color': style['background-color'] || '#000000' }
        : { height: thickness + 'mm', 'background-color': '#000000' };
}

/**
 * The element a line/rectangle block is: an `hr` on a roll — a void element, so nothing
 * that follows it can be read as inside it — and a `div` on a sheet, as ever.
 */
export function ruleTagName(roll) {
    return roll ? 'hr' : 'div';
}
