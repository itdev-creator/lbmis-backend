<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SyncPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_every_role_and_permission_and_can_run_twice(): void
    {
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            array_keys((array) config('lbmis.roles')),
            Role::query()->pluck('name')->all(),
        );
        $this->assertSame(count((array) config('lbmis.permissions')), Permission::query()->count());
        $this->assertSame(Permission::query()->count(), Role::findByName('admin')->permissions()->count());
    }

    public function test_create_admin_command_makes_a_verified_admin(): void
    {
        $this->artisan('lbmis:create-admin', ['email' => 'IT@Example.com', '--name' => 'IT', '--no-interaction' => true])
            ->assertSuccessful();

        $user = User::query()->where('email', 'it@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
    }
}
