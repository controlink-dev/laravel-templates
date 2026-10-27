<?php

namespace Controlink\Templates\Output\EscPos;

use Controlink\Templates\Output\LegalValueText;
use Controlink\Templates\Rendering\FormatSpec;
use Controlink\Templates\Rendering\TemplateRenderer;

/**
 * The legal checks over a receipt (CGE-97), what R-02 and R-05 are to the PDF:
 *
 * - E-01: a mandatory value is in no block of the receipt (one line, or the lines one
 *   text wrapped into), in any of the texts the field prints it as (`LegalValueText`, the
 *   same R-02 looks for);
 * - E-02: a legal QR code is not printed with the dataset's payload, byte for byte;
 * - E-03 / E-04: the builder's own problems, passed through.
 *
 * Read from the same `Receipt` the bytes and the preview are made from.
 */
final class EscPosValidator
{
    public function __construct(private readonly TemplateRenderer $renderer) {}

    /**
     * @param  list<array<string, mixed>>  $fields  the bindings (`key`, `label`, `type`, `mandatory`, `legal`, `default_format`)
     * @param  array<string, mixed>  $dataset  the values the receipt was built with
     * @param  array<string, list<FormatSpec|null>>  $placedFormats  every placed data field's format, by binding
     * @return list<array{code: string, component_id: ?string, message: string, key: string, subject: string}>
     */
    public function validate(Receipt $receipt, array $fields, array $dataset, array $placedFormats = []): array
    {
        $problems = [];
        $lines    = self::texts($receipt);
        $values   = new LegalValueText($this->renderer);

        foreach ($fields as $field) {
            if (! is_array($field) || ! isset($field['key'])) {
                continue;
            }

            $type  = (string) ($field['type'] ?? 'text');
            $label = (string) ($field['label'] ?? $field['key']);

            if ($type === 'qr') {
                if (($field['legal'] ?? false) && ! $this->hasQr($receipt, $field, $dataset)) {
                    $problems[] = $this->problem('E-02', 'qr_mismatch', $label);
                }

                continue;
            }

            // A collection is its lines, not a value; a barcode is not printed on a receipt.
            if (! ($field['mandatory'] ?? false) || in_array($type, ['collection', 'barcode'], true)) {
                continue;
            }

            $candidates = $values->candidates($field, $dataset, $placedFormats);

            // No text to look for: a value the dataset does not carry (a dataset problem,
            // as for R-02), or a field placed only through a formula.
            if ($candidates === [] || $this->onALine($lines, $candidates)) {
                continue;
            }

            $problems[] = $this->problem('E-01', 'value_missing', $label);
        }

        return [...$problems, ...$receipt->problems];
    }

    /**
     * Whether a legal QR is printed with the dataset's payload. A value the dataset does not
     * carry is a dataset problem, not this rule's (the builder reports the empty slot).
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, mixed>  $dataset
     */
    private function hasQr(Receipt $receipt, array $field, array $dataset): bool
    {
        $value = $dataset[$field['key']] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            return true;
        }

        foreach ($receipt->blocks as $block) {
            if ($block['kind'] === 'qr' && $block['payload'] === (string) $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * The receipt's texts, one per block: the consecutive lines of one `group` (the lines a
     * text wrapped into), each squashed and joined by one space. Without a `group`, the
     * consecutive lines with the same non-empty `sources` are one block; any other line, a
     * code or an image is a block of its own. Never across two blocks.
     *
     * @return list<string>
     */
    private static function texts(Receipt $receipt): array
    {
        $texts = [];
        $key   = null;

        foreach ($receipt->blocks as $block) {
            if ($block['kind'] !== 'line') {
                $key = null;

                continue;
            }

            $line  = self::squash($block['text']);
            $group = array_key_exists('group', $block)
                ? 'g:'.$block['group']
                : ($block['sources'] === [] ? null : 's:'.implode("\0", $block['sources']));

            if ($group !== null && $group === $key && $texts !== []) {
                $texts[count($texts) - 1] = self::squash($texts[count($texts) - 1].' '.$line);
            } else {
                $texts[] = $line;
            }

            $key = $group;
        }

        return $texts;
    }

    /**
     * Whether one block holds one of the texts whole: across the lines of a text that
     * wrapped (joined by a space, as the builder breaks on spaces), never across two blocks.
     *
     * @param  list<string>  $lines  already squashed, one per block (`texts`)
     * @param  list<string>  $candidates
     */
    private function onALine(array $lines, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            // White space first, as the builder collapses it: a tab or line break in the
            // value is a space on the receipt, not a `?` (it is not E-03 there either).
            $needle = self::squash(self::printed(self::squash($candidate)));

            if ($needle === '') {
                continue;
            }

            foreach ($lines as $line) {
                if (str_contains($line, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The text as the receipt carries it: in CP858, transliterated as the builder does, and
     * `?` where a character has no equivalent (the builder prints `?` there and reports E-03).
     */
    private static function printed(string $text): string
    {
        $out = '';

        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            try {
                $out .= Cp858::normalize($char);
            } catch (UnencodableText) {
                $out .= '?';
            }
        }

        return $out;
    }

    /** Spaces, NBSP included, as one: the grid pads with spaces and the formats put NBSP. */
    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $text));
    }

    /** @return array{code: string, component_id: null, message: string, key: string, subject: string} */
    private function problem(string $code, string $key, string $subject): array
    {
        return [
            'code'         => $code,
            'component_id' => null,
            'message'      => __('templates::editor.escpos.'.$key, ['subject' => $subject]),
            'key'          => $key,
            'subject'      => $subject,
        ];
    }
}
