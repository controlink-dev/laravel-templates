<?php

namespace Controlink\Templates\Contracts;

interface TenantResolver
{
    /** The current tenant's identifier, or null when there is none (mode `none`, or console). */
    public function currentId(): ?string;

    /** Every tenant identifier, for commands that iterate. */
    public function all(): iterable;

    /**
     * Run $callback with the tenant's database in scope. Implementations that switch a
     * connection MUST purge and reconnect it so `getDatabaseName()` reflects the tenant.
     */
    public function runAs(string $tenantId, callable $callback): mixed;
}
