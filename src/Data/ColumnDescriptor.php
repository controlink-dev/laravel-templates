<?php

namespace Controlink\Templates\Data;

/**
 * One column of a model, described in the terms the templates package speaks.
 *
 * `bindingType` uses the vocabulary `TemplateType::BINDING_TYPES` already carries —
 * `text`, `number`, `date` — so a calculated field is indistinguishable from a declared
 * one to everything downstream. A boolean is a `text` binding (it prints as a translated
 * yes/no label) and carries `isBoolean` separately, because only its fixture differs.
 *
 * `length` and `scale` are nullable and that is not laziness: SQLite does not report
 * them. Measured — `Schema::getColumns()` returns `varchar` and `numeric` under SQLite
 * where MySQL returns `varchar(150)` and `decimal(12,4)`. A guessed length would make the
 * generated fixture differ between the test suite and production, which matters because
 * that fixture is what the user lays a box out against on the canvas: a column measured
 * at 20 characters in the suite and 150 in production is a box that fits in one and
 * overflows in the other.
 *
 * `final class`, not `readonly class`: the package targets PHP 8.1, which has no class-
 * level `readonly`. Every promoted property is `public readonly` on its own instead.
 */
final class ColumnDescriptor
{
    public function __construct(
        public readonly string $name,
        public readonly string $bindingType,
        public readonly bool $isBoolean = false,
        public readonly ?int $length = null,
        public readonly ?int $scale = null,
        public readonly bool $nullable = false,
    ) {}
}
