<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\Future\CanDeleteKeys;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Exceptions\DomainsOccupiedByOtherTenantException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Exceptions\TenantDoesNotExistException;
use Stancl\Tenancy\Exceptions\TenantWithThisIdAlreadyExistsException;
use Stancl\Tenancy\Tenant;

class RedisStorageDriver implements StorageDriver, CanDeleteKeys
{
    /** @var Application */
    protected $app;

    /** @var Redis */
    protected $redis;

    /** @var Tenant The default tenant. */
    protected $tenant;

    public function __construct(Application $app, Redis $redis)
    {
        $this->app = $app;
        $this->redis = $redis->connection($app['config']['tenancy.storage_drivers.redis.connection'] ?? 'tenancy');
    }

    /**
     * Get the current tenant.
     *
     * @return Tenant
     */
    protected function tenant()
    {
        return $this->tenant ?? $this->app[Tenant::class];
    }

    public function withDefaultTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function ensureTenantCanBeCreated(Tenant $tenant): void
    {
        // Tenant ID
        if ($this->redis->exists("tenants:{$tenant->id}")) {
            throw new TenantWithThisIdAlreadyExistsException($tenant->id);
        }

        // Domains
        if ($this->redis->exists(...array_map(fn($domain) => "domains:$domain", $tenant->domains))) {
            throw new DomainsOccupiedByOtherTenantException;
        }
    }

    public function findByDomain(string $domain): Tenant
    {
        $id = $this->getTenantIdByDomain($domain);
        if (! $id) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $this->findById($id);
    }

    public function findById(string $id): Tenant
    {
        $data = $this->redis->hgetall("tenants:$id");

        if (! $data) {
            throw new TenantDoesNotExistException($id);
        }

        return $this->makeTenant($data);
    }

    public function getTenantIdByDomain(string $domain): ?string
    {
        return $this->redis->hget("domains:$domain", 'tenant_id') ?: null;
    }

    public function createTenant(Tenant $tenant): void
    {
        $this->redis->transaction(function ($pipe) use ($tenant) {
            foreach ($tenant->domains as $domain) {
                $pipe->hmset("domains:$domain", ['tenant_id' => $tenant->id]);
            }

            $data = [];
            foreach ($tenant->data as $key => $value) {
                $data[$key] = json_encode($value, JSON_THROW_ON_ERROR);
            }

            $pipe->hmset("tenants:{$tenant->id}", array_merge($data, ['_tenancy_domains' => json_encode($tenant->domains, JSON_THROW_ON_ERROR)]));
        });
    }

    public function updateTenant(Tenant $tenant): void
    {
        $id = $tenant->id;

        $old_domains = json_decode($this->redis->hget("tenants:$id", '_tenancy_domains'), true, 512, JSON_THROW_ON_ERROR);
        $deleted_domains = array_diff($old_domains, $tenant->domains);
        $domains = $tenant->domains;

        $data = [];
        foreach ($tenant->data as $key => $value) {
            $data[$key] = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $this->redis->transaction(function ($pipe) use ($id, $data, $deleted_domains, $domains) {
            foreach ($deleted_domains as $deleted_domain) {
                $pipe->del("domains:$deleted_domain");
            }

            foreach ($domains as $domain) {
                $pipe->hset("domains:$domain", 'tenant_id', $id);
            }

            $pipe->hmset("tenants:$id", array_merge($data, ['_tenancy_domains' => json_encode($domains, JSON_THROW_ON_ERROR)]));
        });
    }

    public function deleteTenant(Tenant $tenant): void
    {
        $this->redis->transaction(function ($pipe) use ($tenant) {
            foreach ($tenant->domains as $domain) {
                $pipe->del("domains:$domain");
            }

            $pipe->del("tenants:{$tenant->id}");
        });
    }

    /**
     * Return a list of all tenants.
     *
     * @param string[] $ids
     * @return Tenant[]
     */
    public function all(array $ids = []): array
    {
        $hashes = array_map(fn($hash) => "tenants:{$hash}", $ids);

        if (! $hashes) {
            // Prefix is applied to all functions except scan().
            // This code applies the correct prefix manually.
            $redis_prefix = $this->redis->getOption($this->redis->client()::OPT_PREFIX);

            $all_keys = $this->redis->keys('tenants:*');

            $hashes = array_map(fn($key) => // Left strip $redis_prefix from $key
substr($key, strlen($redis_prefix)), $all_keys);
        }

        return array_map(fn($tenant) => $this->makeTenant($this->redis->hgetall($tenant)), $hashes);
    }

    /**
     * Make a Tenant instance from low-level array data.
     */
    protected function makeTenant(array $data): Tenant
    {
        foreach ($data as $key => $value) {
            $data[$key] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }

        $domains = $data['_tenancy_domains'];
        unset($data['_tenancy_domains']);

        return Tenant::fromStorage($data)->withDomains($domains);
    }

    public function get(string $key, Tenant $tenant = null)
    {
        $tenant ??= $this->tenant();

        $json_data = $this->redis->hget("tenants:{$tenant->id}", $key);
        if ($json_data === false) {
            return;
        }

        return json_decode($json_data, true, 512, JSON_THROW_ON_ERROR);
    }

    public function getMany(array $keys, Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant();

        $result = [];
        $values = $this->redis->hmget("tenants:{$tenant->id}", $keys);
        foreach ($keys as $i => $key) {
            $result[$key] = json_decode($values[$i], true, 512, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    public function put(string $key, $value, Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant();
        $this->redis->hset("tenants:{$tenant->id}", $key, json_encode($value, JSON_THROW_ON_ERROR));
    }

    public function putMany(array $kvPairs, Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant();

        foreach ($kvPairs as $key => $value) {
            $kvPairs[$key] = json_encode($value, JSON_THROW_ON_ERROR);
        }

        $this->redis->hmset("tenants:{$tenant->id}", $kvPairs);
    }

    public function deleteMany(array $keys, Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant();

        $this->redis->hdel("tenants:{$tenant->id}", ...$keys);
    }
}
