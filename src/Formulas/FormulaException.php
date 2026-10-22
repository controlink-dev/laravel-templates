<?php

namespace Controlink\Templates\Formulas;

use RuntimeException;

/**
 * A formula the closed language rejects. `reason` is a stable machine code
 * (syntax | unknown_function | arity, plus whatever later stages add) so the
 * host can translate it instead of showing parser internals to the user.
 */
final class FormulaException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
