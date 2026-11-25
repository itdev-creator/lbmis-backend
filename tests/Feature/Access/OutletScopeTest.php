<?php

namespace Tests\Feature\Access;

use App\Models\Franchisee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OutletScopeTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $corporate;

    private Outlet $ownFranchise;

    private Outlet $otherFranchise;

    private Franchisee $franchisee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('lbmis:sync-permissions')->assertSuccessful();

        $this->franchisee = Franchisee::factory()->create();
        $this->corporate = Outlet::factory()->create(['code' => 'CORP01']);
        $this->ownFranchise = Outlet::factory()->franchise($this->franchisee)->create(['code' => 'FRAN01']);
        $this->otherFranchise = Outlet::factory()->franchise()->create(['code' => 'FRAN99']);
    }

    /**
     * @return list<string>
     */
    private function visibleCodes(User $user): array
    {
        return Outlet::query()->visibleTo($user)->orderBy('code')->pluck('code')->all();
    }

    public function test_admin_manager_and_admin_staff_see_every_outlet(): void
    {
        foreach (['admin', 'manager', 'admin-staff'] as $role) {
            $user = User::factory()->role($role)->create();
            $this->assertSame(['CORP01', 'FRAN01', 'FRAN99'], $this->visibleCodes($user), $role);
        }
    }

    public function test_staff_see_only_assigned_outlets(): void
    {
        $staff = User::factory()->role('staff')->create();
        $this->assertSame([], $this->visibleCodes($staff));

        $staff->assignedOutlets()->attach($this->corporate);
        $this->assertSame(['CORP01'], $this->visibleCodes($staff));
    }

    public function test_franchise_owner_sees_only_own_company_outlets(): void
    {
        $owner = User::factory()->franchiseOwner($this->franchisee)->create();

        $this->assertSame(['FRAN01'], $this->visibleCodes($owner));
    }

    public function test_franchise_owner_without_company_sees_nothing(): void
    {
        $owner = User::factory()->role('franchise-owner')->create(['franchisee_id' => null]);

        $this->assertSame([], $this->visibleCodes($owner));
    }

    public function test_outlets_page_lists_only_visible_outlets(): void
    {
        $owner = User::factory()->franchiseOwner($this->franchisee)->create();

        $this->actingAs($owner)->get(route('outlets.index'))
            ->assertOk()
            ->assertSee('FRAN01')
            ->assertDontSee('CORP01')
            ->assertDontSee('FRAN99');
    }

    public function test_franchise_outlet_requires_a_franchisee(): void
    {
        $admin = User::factory()->role('admin')->create();

        Livewire::actingAs($admin)->test('pages::outlets.index')
            ->set('code', 'NEW01')
            ->set('name', 'New outlet')
            ->set('outlet_type', 'franchise')
            ->set('franchisee_id', null)
            ->call('save')
            ->assertHasErrors(['franchisee_id']);

        $this->assertDatabaseMissing('outlets', ['code' => 'NEW01']);
    }

    public function test_corporate_outlet_never_keeps_a_franchisee(): void
    {
        $admin = User::factory()->role('admin')->create();

        Livewire::actingAs($admin)->test('pages::outlets.index')
            ->set('code', 'jv01')
            ->set('name', 'JV outlet')
            ->set('outlet_type', 'jv')
            ->set('franchisee_id', $this->franchisee->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('outlets', ['code' => 'JV01', 'type' => 'jv', 'franchisee_id' => null]);
    }

    public function test_staff_cannot_create_outlets(): void
    {
        $staff = User::factory()->role('staff')->create();

        Livewire::actingAs($staff)->test('pages::outlets.index')
            ->call('create')
            ->assertForbidden();
    }
}
