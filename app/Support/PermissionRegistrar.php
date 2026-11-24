<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar as SpatiePermissionRegistrar;

/**
 * Spatie's registrar, with a permission cache that is cheap to rebuild
 * when there are thousands of clients.
 *
 * Every client gets its own `client_admin` and `client_user` roles, so
 * role count grows with client count. Spatie rebuilds its cache from
 * `Permission::with('roles')`, and eager loading a many-to-many creates
 * one Role model per pivot row: with 1,600 clients that is ~16,000
 * models built and thrown away, a 150 MB+ spike on whichever request
 * first finds the cache cold. The cache is cleared every time a client
 * is created or anyone switches team, so that request comes often, and
 * at PHP's default 128 MB limit it dies.
 *
 * This builds the same collection from one query per table, sharing
 * one Role instance across every permission that has it. The cache
 * Spatie serializes from it is identical; only the build is cheaper.
 */
class PermissionRegistrar extends SpatiePermissionRegistrar
{
    protected function getPermissionsWithRoles(): EloquentCollection
    {
        $permissions = $this->getPermissionClass()::query()->select()->get();
        $roles = $this->getRoleClass()::query()->select()->get()->keyBy(fn ($role) => $role->getKey());

        $rolesByPermission = [];
        $pivot = DB::table(config('permission.table_names.role_has_permissions'))
            ->select([$this->pivotPermission, $this->pivotRole])
            ->cursor();
        foreach ($pivot as $row) {
            $role = $roles->get($row->{$this->pivotRole});
            if ($role !== null) {
                $rolesByPermission[$row->{$this->pivotPermission}][] = $role;
            }
        }

        return $permissions->each(fn ($permission) => $permission->setRelation(
            'roles',
            new EloquentCollection($rolesByPermission[$permission->getKey()] ?? []),
        ));
    }
}
