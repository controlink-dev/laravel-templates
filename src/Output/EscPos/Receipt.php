<?php

namespace Controlink\Templates\Output\EscPos;

/**
 * A receipt laid on the printer's character grid (CGE-97): the one result the ESC/POS
 * bytes, the preview and the legal checks all read, so they cannot disagree.
 *
 * Blocks, in print order:
 * - `line`: `text` is exactly `columns` characters wide (`columns / 2` when `double`),
 *   aligned with spaces, every character one CP858 can print (`Cp858::normalize`). `bold`
 *   lists character ranges `[start, end)` (end exclusive). `sources` are the ids of the
 *   components whose text the line carries.
 * - `qr`: the payload, byte for byte; `binding` is the dataset key, null on a fixed slot.
 * - `image`: a PNG.
 *
 * `problems` are the builder's own (E-03 a character with no CP858 equivalent, E-04 an
 * element that does not translate): bytes must not be produced while there are any.
 */
final class Receipt
{
    /**
     * @param  list<array{kind: 'line', text: string, double: bool, bold: list<array{0: int, 1: int}>, sources: list<string>}|array{kind: 'qr', payload: string, binding: ?string}|array{kind: 'image', png: string}>  $blocks
     * @param  list<array{code: string, component_id: ?string, message: string, key: string, subject: string}>  $problems
     */
    public function __construct(
        public readonly int $columns,
        public readonly array $blocks,
        public readonly array $problems = [],
    ) {
    }

    /** The lines joined by `\n`, with `[QR]` and `[IMG]` where the codes and images go. What E-01 searches. */
    public function text(): string
    {
        return implode("\n", array_map(fn (array $block): string => match ($block['kind']) {
            'line'  => $block['text'],
            'qr'    => '[QR]',
            default => '[IMG]',
        }, $this->blocks));
    }
}
