<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Tenant;

interface StorageDriver
{
    public function createTenant(Tenant $tenant): void;

    public function updateTenant(Tenant $tenant): void;

    public function deleteTenant(Tenant $tenant): void;

    /**
     * Find a tenant using an id.
     *
     * @throws \Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException
     */
    public function findById(string $id): Tenant;

    /**
     * Find a tenant using a domain name.
     */
    public function findByDomain(string $domain): Tenant;

    /**
     * Get all tenants.
     *
     * @param string[] $ids
     * @return Tenant[]
     */
    public function all(array $ids = []): array;

    /**
     * Ensure a tenant can be created.
     *
     * @throws TenantCannotBeCreatedException
     */
    public function ensureTenantCanBeCreated(Tenant $tenant): void;

    /**
     * Set default tenant (will be used for get/put when no tenant is supplied).
     *
     * @return self
     */
    public function withDefaultTenant(Tenant $tenant);

    /**
     * Get a value from storage.
     *
     * @param ?Tenant $tenant
     * @return mixed
     */
    public function get(string $key, Tenant $tenant = null);

    /**
     * Get multiple values from storage.
     *
     * @param ?Tenant $tenant
     * @return void
     */
    public function getMany(array $keys, Tenant $tenant = null);

    /**
     * Put a value into storage.
     *
     * @param ?Tenant $tenant
     */
    public function put(string $key, mixed $value, Tenant $tenant = null): void;

    /**
     * Put multiple values into storage.
     *
     * @param mixed[string] $kvPairs
     * @param ?Tenant $tenant
     */
    public function putMany(array $kvPairs, Tenant $tenant = null): void;
}
