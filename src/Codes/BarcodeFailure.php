<?php

namespace Controlink\Templates\Codes;

use RuntimeException;

/**
 * Why a barcode could not be made for a given value.
 *
 * A thermal printer cannot print half a barcode, and a barcode that does not scan is
 * worse than no barcode, so both causes stop the print instead of degrading it. The
 * offending value travels with the exception so the host can say which record broke.
 */
final class BarcodeFailure extends RuntimeException
{
    public function __construct(string $message, public readonly string $content)
    {
        parent::__construct($message);
    }

    /** The value would need bars narrower than 2 printer dots, which 203 dpi heads smear. */
    public static function doesNotFit(string $content, float $widthMm): self
    {
        return new self(
            sprintf('The barcode "%s" does not fit in %s mm at 2 dots per module.', $content, rtrim(rtrim(number_format($widthMm, 2, '.', ''), '0'), '.')),
            $content,
        );
    }

    /** Code 128 subset B carries printable ASCII only. */
    public static function unencodable(string $content): self
    {
        return new self(
            sprintf('The barcode "%s" has characters Code 128 cannot carry (printable ASCII only).', $content),
            $content,
        );
    }
}
