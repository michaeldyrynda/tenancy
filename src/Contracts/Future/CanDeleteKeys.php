<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts\Future;

use Stancl\Tenancy\Tenant;

/**
 * This interface will be part of the StorageDriver interface in 3.x.
 */
interface CanDeleteKeys
{
    /**
     * Delete keys from the storage.
     *
     * @param string[] $keys
     */
    public function deleteMany(array $keys, Tenant $tenant = null): void;
}
