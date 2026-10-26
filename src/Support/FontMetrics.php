<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Output\Pdf\DocumentComposer;
use RuntimeException;

/**
 * Advance widths read straight from a TrueType file (CGE-89).
 *
 * The compositor paginates by arithmetic, so a line-table row that wraps must know how
 * many lines it takes before the engine sees it. The widths come from the very TTF the
 * PDF embeds (`templates.fonts`), not from a guess per character. Only `head`, `hhea`,
 * `hmtx` and `cmap` are read; kerning is ignored, which the caller's safety margin covers.
 */
class FontMetrics
{
    /** @var array<string, self> */
    private static array $cache = [];

    /**
     * @param  array<int, int>  $glyphOf  code point → glyph index
     * @param  list<int>  $advances  glyph index → advance, in font units
     */
    private function __construct(
        private readonly int $unitsPerEm,
        private readonly array $glyphOf,
        private readonly array $advances,
    ) {}

    /** The first configured family, at the face nearest `$weight` (700 bold, else 400). */
    public static function fromConfig(int $weight = 400): self
    {
        $family = (array) (array_values((array) config('templates.fonts'))[0] ?? []);
        $faces  = (array) ($family['weights'] ?? []);

        if ($faces === []) {
            throw new RuntimeException('No template font is configured.');
        }

        $face = $faces[$weight >= 600 && isset($faces[700]) ? 700 : 400] ?? reset($faces);

        return self::fromFile(DocumentComposer::fontPath($face['path']));
    }

    public static function fromFile(string $path): self
    {
        return self::$cache[$path] ??= self::parse($path);
    }

    /** The printed width of `$text` at `$fontPt`, in millimetres. */
    public function widthMm(string $text, float $fontPt): float
    {
        $units = 0;

        foreach (mb_str_split($text) as $character) {
            $glyph  = $this->glyphOf[mb_ord($character)] ?? 0;
            $units += $this->advances[$glyph] ?? end($this->advances);
        }

        return $units / $this->unitsPerEm * $fontPt * 25.4 / 72;
    }

    private static function parse(string $path): self
    {
        $data = @file_get_contents($path);

        if ($data === false) {
            throw new RuntimeException("Template font file is missing: {$path}");
        }

        $tables = [];
        $count  = self::u16($data, 4);

        for ($i = 0; $i < $count; $i++) {
            $record = 12 + 16 * $i;
            $tables[substr($data, $record, 4)] = self::u32($data, $record + 8);
        }

        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $required) {
            if (! isset($tables[$required])) {
                throw new RuntimeException("Font {$path} has no '{$required}' table.");
            }
        }

        $unitsPerEm = self::u16($data, $tables['head'] + 18);
        $metrics    = self::u16($data, $tables['hhea'] + 34);

        $advances = [];
        for ($glyph = 0; $glyph < $metrics; $glyph++) {
            $advances[] = self::u16($data, $tables['hmtx'] + 4 * $glyph);
        }

        return new self($unitsPerEm, self::cmap($data, $tables['cmap']), $advances);
    }

    /**
     * The Unicode cmap: format 12 (3/10) when present, else format 4 (3/1).
     *
     * @return array<int, int>
     */
    private static function cmap(string $data, int $offset): array
    {
        $subtables = [];
        $count     = self::u16($data, $offset + 2);

        for ($i = 0; $i < $count; $i++) {
            $record   = $offset + 4 + 8 * $i;
            $platform = self::u16($data, $record);
            $encoding = self::u16($data, $record + 2);

            $subtables["{$platform}/{$encoding}"] = $offset + self::u32($data, $record + 4);
        }

        if (isset($subtables['3/10']) && self::u16($data, $subtables['3/10']) === 12) {
            return self::format12($data, $subtables['3/10']);
        }

        $table = $subtables['3/1'] ?? $subtables['0/3'] ?? null;

        if ($table === null || self::u16($data, $table) !== 4) {
            throw new RuntimeException('Font has no Unicode cmap.');
        }

        return self::format4($data, $table);
    }

    /** @return array<int, int> */
    private static function format4(string $data, int $table): array
    {
        $segments  = self::u16($data, $table + 6) / 2;
        $ends      = $table + 14;
        $starts    = $ends + 2 * $segments + 2;
        $deltas    = $starts + 2 * $segments;
        $rangeBase = $deltas + 2 * $segments;
        $map       = [];

        for ($s = 0; $s < $segments; $s++) {
            $end         = self::u16($data, $ends + 2 * $s);
            $start       = self::u16($data, $starts + 2 * $s);
            $delta       = self::u16($data, $deltas + 2 * $s);
            $rangeOffset = self::u16($data, $rangeBase + 2 * $s);

            if ($start === 0xFFFF) {
                continue;
            }

            for ($code = $start; $code <= $end; $code++) {
                if ($rangeOffset === 0) {
                    $glyph = ($code + $delta) & 0xFFFF;
                } else {
                    $glyph = self::u16($data, $rangeBase + 2 * $s + $rangeOffset + 2 * ($code - $start));
                    $glyph = $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
                }

                if ($glyph !== 0) {
                    $map[$code] = $glyph;
                }
            }
        }

        return $map;
    }

    /** @return array<int, int> */
    private static function format12(string $data, int $table): array
    {
        $groups = self::u32($data, $table + 12);
        $map    = [];

        for ($g = 0; $g < $groups; $g++) {
            $record = $table + 16 + 12 * $g;
            $start  = self::u32($data, $record);
            $end    = self::u32($data, $record + 4);
            $glyph  = self::u32($data, $record + 8);

            // Only the planes text in a document actually uses; a full-range group
            // would otherwise allocate a million entries.
            for ($code = $start; $code <= min($end, 0x2FFFF); $code++) {
                $map[$code] = $glyph + ($code - $start);
            }
        }

        return $map;
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('n', $data, $offset)[1];
    }

    private static function u32(string $data, int $offset): int
    {
        return unpack('N', $data, $offset)[1];
    }
}
