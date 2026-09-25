<?php

namespace Controlink\Templates\Models\Concerns;

use Controlink\Templates\Contracts\TenantResolver;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

trait HasTemplatesConnection
{
    public function getConnectionName(): ?string
    {
        if (config('templates.tenancy.mode') === 'connection') {
            $name = config('templates.tenancy.connection');

            if (! is_string($name) || $name === '') {
                throw new RuntimeException('templates.tenancy.mode is "connection" but templates.tenancy.connection is not set.');
            }

            return $name;
        }

        return parent::getConnectionName();
    }

    public static function bootHasTemplatesConnection(): void
    {
        if (config('templates.tenancy.mode') !== 'column') {
            return;
        }

        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->getModel()->getTable().'.tenant_id', app(TenantResolver::class)->currentId());
        });

        static::creating(function ($model): void {
            $model->tenant_id ??= app(TenantResolver::class)->currentId();
        });
    }
}
