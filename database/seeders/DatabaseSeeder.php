<?php

namespace Database\Seeders;

use App\Enums\OutletStage;
use App\Enums\OutletType;
use App\Models\Brand;
use App\Models\Department;
use App\Models\Franchisee;
use App\Models\Outlet;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Demo data for local development only: `php artisan migrate:fresh --seed`.
 * Every demo user signs in with the password "password".
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (app()->environment('production', 'staging')) {
            throw new RuntimeException('Demo data is for local development only.');
        }

        Artisan::call('lbmis:sync-permissions');
        $this->call(DepartmentSeeder::class);

        $brand = Brand::query()->firstOrCreate(['name' => 'LaundryBar']);

        // Sample plans: names, prices and modules are placeholders to edit on the Plans page.
        $basic = Plan::query()->firstOrCreate(['code' => 'BASIC'], [
            'name' => 'Basic', 'price' => 0, 'billing_period' => 'monthly',
            'modules' => ['outlets'], 'description' => 'Outlet profile only.',
        ]);
        Plan::query()->firstOrCreate(['code' => 'PRO'], [
            'name' => 'Pro', 'price' => 99, 'billing_period' => 'monthly',
            'modules' => ['outlets', 'documents', 'tickets'],
            'description' => 'Licence reminders and maintenance tickets.',
        ]);

        $franchisee = Franchisee::factory()->create(['company_name' => 'Demo Franchise Sdn Bhd']);
        Subscription::factory()->create(['franchisee_id' => $franchisee->id, 'plan_id' => $basic->id]);

        $corporate = Outlet::factory()->count(5)->create(['brand_id' => $brand->id]);
        Outlet::factory()->count(3)->franchise($franchisee)->create(['brand_id' => $brand->id]);
        Outlet::factory()->create([
            'brand_id' => $brand->id, 'type' => OutletType::JointVenture, 'stage' => OutletStage::Opening,
        ]);

        $ops = Department::query()->where('code', 'OPS')->first();

        $demo = [
            ['Admin', 'admin@lbmis.test', 'admin'],
            ['Manager', 'manager@lbmis.test', 'manager'],
            ['Admin Staff', 'adminstaff@lbmis.test', 'admin-staff'],
            ['Staff', 'staff@lbmis.test', 'staff'],
        ];
        foreach ($demo as [$name, $email, $role]) {
            User::factory()->role($role)->create([
                'name' => $name, 'email' => $email, 'department_id' => $ops?->id,
            ]);
        }

        User::query()->where('email', 'staff@lbmis.test')->firstOrFail()
            ->assignedOutlets()->sync($corporate->take(2)->pluck('id')->all());

        User::factory()->franchiseOwner($franchisee)->create([
            'name' => 'Franchise Owner', 'email' => 'owner@lbmis.test',
        ]);
    }
}
