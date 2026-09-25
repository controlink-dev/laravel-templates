<?php

namespace Controlink\Templates\Output\Pdf;

/** SHA-256 of a PDF with `/CreationDate` and `/ModDate` neutralised, so a regenerated document hashes the same. */
final class PdfHash
{
    private const VOLATILE_METADATA = '/\/(CreationDate|ModDate)\s*\(D:[^)]*\)/';

    public static function of(string $pdf): string
    {
        return hash('sha256', (string) preg_replace(self::VOLATILE_METADATA, '/$1 (D:0)', $pdf));
    }
}
