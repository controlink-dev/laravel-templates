<?php

namespace Controlink\Templates\Output\EscPos;

use RuntimeException;

/**
 * A character the thermal printer cannot print (CGE-97): not in CP858 and with no
 * transliteration. The receipt builder turns it into an E-03 naming the field; it never
 * becomes a `?` on paper.
 */
final class UnencodableText extends RuntimeException
{
    public function __construct(public readonly ?string $field, public readonly string $char)
    {
        parent::__construct(sprintf(
            'The character "%s" (U+%04X)%s has no CP858 equivalent.',
            $char,
            mb_ord($char, 'UTF-8') ?: 0,
            $field === null ? '' : ' in '.$field,
        ));
    }
}
