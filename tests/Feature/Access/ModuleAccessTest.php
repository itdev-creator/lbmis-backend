<?php

namespace Tests\Feature\Access;

use App\Enums\SubscriptionStatus;
use App\Models\Franchisee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();

        // A throwaway route protected by module:tickets, only for these tests.
        Route::middleware(['web', 'auth', 'module:tickets'])
            ->get('/__test/module-tickets', fn () => 'tickets open');
    }

    public function test_internal_users_are_never_limited_by_plans(): void
    {
        $staff = User::factory()->role('staff')->create();

        $this->assertTrue($staff->canUseModule('tickets'));
        $this->assertTrue($staff->canUseModule('documents'));
    }

    public function test_free_modules_are_open_to_franchise_owners_without_a_plan(): void
    {
        $owner = User::factory()->franchiseOwner()->create();

        $this->assertTrue($owner->canUseModule('outlets'));
        $this->assertFalse($owner->canUseModule('tickets'));
    }

    public function test_active_plan_unlocks_only_its_modules(): void
    {
        $franchisee = Franchisee::factory()->create();
        $plan = Plan::factory()->withModules(['documents'])->create();
        Subscription::factory()->create(['franchisee_id' => $franchisee->id, 'plan_id' => $plan->id]);
        $owner = User::factory()->franchiseOwner($franchisee)->create();

        $this->assertTrue($owner->canUseModule('documents'));
        $this->assertFalse($owner->canUseModule('tickets'));
    }

    public function test_expired_or_cancelled_plans_unlock_nothing(): void
    {
        $franchisee = Franchisee::factory()->create();
        $plan = Plan::factory()->withModules(['documents', 'tickets'])->create();
        Subscription::factory()->expired()->create(['franchisee_id' => $franchisee->id, 'plan_id' => $plan->id]);
        Subscription::factory()->create([
            'franchisee_id' => $franchisee->id, 'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Cancelled,
        ]);
        $owner = User::factory()->franchiseOwner($franchisee)->create();

        $this->assertFalse($owner->canUseModule('documents'));
    }

    public function test_unknown_module_is_refused(): void
    {
        $admin = User::factory()->role('admin')->create();

        $this->assertFalse($admin->canUseModule('does-not-exist'));
    }

    public function test_locked_module_route_sends_owner_to_subscription_page(): void
    {
        $owner = User::factory()->franchiseOwner()->create();

        $this->actingAs($owner)
            ->get('/__test/module-tickets')
            ->assertRedirect(route('subscription.show'))
            ->assertSessionHas('module_locked', 'Maintenance tickets');
    }

    public function test_unlocked_module_route_lets_owner_through(): void
    {
        $franchisee = Franchisee::factory()->create();
        $plan = Plan::factory()->withModules(['tickets'])->create();
        Subscription::factory()->create(['franchisee_id' => $franchisee->id, 'plan_id' => $plan->id]);
        $owner = User::factory()->franchiseOwner($franchisee)->create();

        $this->actingAs($owner)->get('/__test/module-tickets')->assertOk()->assertSee('tickets open');
    }
}
