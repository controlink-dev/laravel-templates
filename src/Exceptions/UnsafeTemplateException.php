<?php

namespace Controlink\Templates\Exceptions;

use RuntimeException;

/**
 * Raised when a composed document carries script, an inline event handler, or a CSS
 * `url()` that is not a data URI (spec 7.1, "the compositor's own safety guards").
 *
 * A template can only have come from the editor, which offers no way to write any of
 * this — so this is evidence of tampering, not a user mistake, and `DocumentComposer`
 * refuses the document rather than sanitising it.
 */
class UnsafeTemplateException extends RuntimeException {}
