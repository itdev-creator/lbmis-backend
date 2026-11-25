<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();
    }

    /**
     * Which pages each role may open (200) or not (403).
     *
     * @return array<string, array{string, string, int}>
     */
    public static function pageAccess(): array
    {
        return [
            'admin: users' => ['admin', 'admin.users', 200],
            'admin: departments' => ['admin', 'admin.departments', 200],
            'admin: franchisees' => ['admin', 'admin.franchisees', 200],
            'admin: plans' => ['admin', 'admin.plans', 200],
            'admin: outlets' => ['admin', 'outlets.index', 200],

            'manager: users' => ['manager', 'admin.users', 403],
            'manager: franchisees' => ['manager', 'admin.franchisees', 200],
            'manager: plans' => ['manager', 'admin.plans', 403],
            'manager: outlets' => ['manager', 'outlets.index', 200],

            'admin staff: users' => ['admin-staff', 'admin.users', 403],
            'admin staff: franchisees' => ['admin-staff', 'admin.franchisees', 200],
            'admin staff: outlets' => ['admin-staff', 'outlets.index', 200],

            'staff: franchisees' => ['staff', 'admin.franchisees', 403],
            'staff: departments' => ['staff', 'admin.departments', 403],
            'staff: outlets' => ['staff', 'outlets.index', 200],
            'staff: subscription' => ['staff', 'subscription.show', 403],

            'franchise owner: users' => ['franchise-owner', 'admin.users', 403],
            'franchise owner: franchisees' => ['franchise-owner', 'admin.franchisees', 403],
            'franchise owner: outlets' => ['franchise-owner', 'outlets.index', 200],
            'franchise owner: subscription' => ['franchise-owner', 'subscription.show', 200],
        ];
    }

    #[DataProvider('pageAccess')]
    public function test_role_page_access(string $role, string $route, int $status): void
    {
        $user = $role === 'franchise-owner'
            ? User::factory()->franchiseOwner()->create()
            : User::factory()->role($role)->create();

        $this->actingAs($user)->get(route($route))->assertStatus($status);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('outlets.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_a_role_sees_dashboard_but_no_outlets(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('No role assigned yet');
        $this->actingAs($user)->get(route('outlets.index'))->assertForbidden();
    }

    public function test_deactivated_user_cannot_sign_in(): void
    {
        $user = User::factory()->role('staff')->inactive()->create();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_user_deactivated_while_signed_in_is_signed_out(): void
    {
        $user = User::factory()->role('staff')->create();
        $this->actingAs($user);

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
