<?php

namespace Controlink\Templates\Support;

use Controlink\Templates\Contracts\TenantResolver;

class NullTenantResolver implements TenantResolver
{
    public function currentId(): ?string
    {
        return null;
    }

    public function all(): iterable
    {
        return ['default'];
    }

    public function runAs(string $tenantId, callable $callback): mixed
    {
        return $callback();
    }
}
