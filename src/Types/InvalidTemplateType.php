<?php

namespace Controlink\Templates\Types;

use InvalidArgumentException;

final class InvalidTemplateType extends InvalidArgumentException
{
    public static function unknown(string $code): self
    {
        return new self("Unknown template type [{$code}]. Declare it in config/templates.php under `types`.");
    }

    public static function because(string $code, string $reason): self
    {
        return new self("Template type [{$code}] is invalid: {$reason}");
    }
}
