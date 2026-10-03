<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the roles and permissions.
     */
    public function run(): void
    {
        // Clear the cached roles and permissions before changing them
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'view users',
            'create users',
            'edit users',
            'delete users',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // Clear the cache again so the new permissions can be found by name.
        // (DatabaseSeeder uses WithoutModelEvents, which stops Spatie clearing it automatically.)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('super-admin')->syncPermissions($permissions);

        Role::findOrCreate('admin')->syncPermissions($permissions);

        Role::findOrCreate('staff')->syncPermissions(['view users']);
    }
}
