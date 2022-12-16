<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface TenantDatabaseManager
{
    /**
     * Create a database.
     *
     * @param  string $name Name of the database.
     */
    public function createDatabase(string $name): bool;

    /**
     * Delete a database.
     *
     * @param  string $name Name of the database.
     */
    public function deleteDatabase(string $name): bool;

    /**
     * Does a database exist.
     */
    public function databaseExists(string $name): bool;
}
