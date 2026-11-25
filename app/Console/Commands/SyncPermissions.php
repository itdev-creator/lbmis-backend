<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the roles and permissions defined in config/lbmis.php and gives each role
 * exactly the permissions listed there. Safe to run any number of times; every deploy runs it.
 */
class SyncPermissions extends Command
{
    protected $signature = 'lbmis:sync-permissions';

    protected $description = 'Create and update roles and permissions from config/lbmis.php';

    public function handle(PermissionRegistrar $registrar): int
    {
        /** @var array<string, string> $permissions */
        $permissions = config('lbmis.permissions', []);
        /** @var array<string, array{label: string, outlet_scope: string, permissions: list<string>}> $roles */
        $roles = config('lbmis.roles', []);

        $registrar->forgetCachedPermissions();

        $names = array_keys($permissions);
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Permissions removed from the config are deleted, so no role keeps them by accident.
        Permission::query()->where('guard_name', 'web')->whereNotIn('name', $names)->delete();

        foreach ($roles as $roleName => $definition) {
            $role = Role::findOrCreate($roleName, 'web');
            $granted = $definition['permissions'] === ['*'] ? $names : $definition['permissions'];

            $unknown = array_diff($granted, $names);
            if ($unknown !== []) {
                $this->error("Role [{$roleName}] lists unknown permissions: ".implode(', ', $unknown));

                return self::FAILURE;
            }

            $role->syncPermissions($granted);
            $this->line(sprintf('  %-16s %d permissions', $roleName, count($granted)));
        }

        $registrar->forgetCachedPermissions();
        $this->info('Roles and permissions are up to date.');

        return self::SUCCESS;
    }
}
