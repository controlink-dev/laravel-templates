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
