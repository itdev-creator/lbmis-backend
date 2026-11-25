<?php

namespace Tests\Feature\Admin;

use App\Models\Franchisee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();
        $this->admin = User::factory()->role('admin')->create();
    }

    public function test_admin_creates_a_staff_user_with_assigned_outlets(): void
    {
        $outlets = Outlet::factory()->count(2)->create();

        Livewire::actingAs($this->admin)->test('pages::admin.users')
            ->call('create')
            ->set('name', 'Siti Technician')
            ->set('email', 'Siti@Example.com')
            ->set('role', 'staff')
            ->call('addOutlet', $outlets[0]->id)
            ->call('addOutlet', $outlets[1]->id)
            ->set('password', 'a-Strong-password-123')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::query()->where('email', 'siti@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('staff'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('a-Strong-password-123', $user->password));
        $this->assertEqualsCanonicalizing($outlets->pluck('id')->all(), $user->assignedOutlets()->pluck('outlets.id')->all());
    }

    public function test_franchise_owner_needs_a_franchisee_company(): void
    {
        Livewire::actingAs($this->admin)->test('pages::admin.users')
            ->call('create')
            ->set('name', 'Owner')
            ->set('email', 'owner@example.com')
            ->set('role', 'franchise-owner')
            ->set('password', 'a-Strong-password-123')
            ->call('save')
            ->assertHasErrors(['franchisee_id']);
    }

    public function test_changing_role_clears_franchisee_and_outlets(): void
    {
        $franchisee = Franchisee::factory()->create();
        $user = User::factory()->franchiseOwner($franchisee)->create();

        Livewire::actingAs($this->admin)->test('pages::admin.users')
            ->call('edit', $user->id)
            ->set('role', 'manager')
            ->call('save')
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->hasRole('manager'));
        $this->assertFalse($user->hasRole('franchise-owner'));
        $this->assertNull($user->franchisee_id);
    }

    public function test_admin_cannot_demote_or_deactivate_themselves(): void
    {
        Livewire::actingAs($this->admin)->test('pages::admin.users')
            ->call('edit', $this->admin->id)
            ->set('role', 'staff')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertTrue($this->admin->fresh()?->hasRole('admin'));
    }

    public function test_non_admins_cannot_manage_users(): void
    {
        $manager = User::factory()->role('manager')->create();

        Livewire::actingAs($manager)->test('pages::admin.users')
            ->call('create')
            ->assertForbidden();
    }
}
