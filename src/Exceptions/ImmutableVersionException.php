<?php

namespace Controlink\Templates\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to change the layout of an active or archived template
 * version (RA-03).
 *
 * Not a validation error: no input would make it acceptable. Every document already
 * issued points at that exact version, and a layout that changes underneath them makes
 * them unreproducible — so the write is refused wherever it comes from, not only on the
 * route that a user can reach.
 */
class ImmutableVersionException extends RuntimeException {}
