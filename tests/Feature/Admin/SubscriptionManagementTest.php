<?php

namespace Tests\Feature\Admin;

use App\Enums\SubscriptionStatus;
use App\Models\Franchisee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();
    }

    public function test_manager_assigns_a_plan_and_the_old_one_is_cancelled(): void
    {
        $manager = User::factory()->role('manager')->create();
        $franchisee = Franchisee::factory()->create();
        $old = Subscription::factory()->create(['franchisee_id' => $franchisee->id]);
        $newPlan = Plan::factory()->withModules(['tickets'])->create();

        Livewire::actingAs($manager)->test('pages::admin.franchisees')
            ->call('choosePlan', $franchisee->id)
            ->set('plan_id', $newPlan->id)
            ->call('savePlan')
            ->assertHasNoErrors();

        $this->assertSame(SubscriptionStatus::Cancelled, $old->fresh()?->status);
        $this->assertSame($newPlan->id, $franchisee->activeSubscription()?->plan_id);
    }

    public function test_admin_staff_cannot_assign_plans(): void
    {
        $adminStaff = User::factory()->role('admin-staff')->create();
        $franchisee = Franchisee::factory()->create();

        Livewire::actingAs($adminStaff)->test('pages::admin.franchisees')
            ->call('choosePlan', $franchisee->id)
            ->assertForbidden();
    }

    public function test_plan_needs_at_least_one_valid_module(): void
    {
        $admin = User::factory()->role('admin')->create();

        Livewire::actingAs($admin)->test('pages::admin.plans')
            ->call('edit')
            ->set('name', 'Gold')
            ->set('code', 'gold')
            ->set('price', '120')
            ->set('modules', ['not-a-module'])
            ->call('save')
            ->assertHasErrors(['modules.0']);

        $this->assertDatabaseMissing('plans', ['code' => 'GOLD']);
    }

    public function test_franchise_owner_sees_their_plan(): void
    {
        $franchisee = Franchisee::factory()->create();
        $plan = Plan::factory()->create(['name' => 'Starter Pack']);
        Subscription::factory()->create(['franchisee_id' => $franchisee->id, 'plan_id' => $plan->id]);
        $owner = User::factory()->franchiseOwner($franchisee)->create();

        $this->actingAs($owner)->get(route('subscription.show'))
            ->assertOk()
            ->assertSee('Starter Pack');
    }
}
