<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * The 14 departments from the system design. Safe to run on staging/production:
 *   php artisan db:seed --class=DepartmentSeeder --force
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'CRM' => 'CRM & Support',
            'MGMT' => 'Management',
            'TECH' => 'Technical',
            'FRAN' => 'Franchise',
            'PROJ' => 'Project',
            'BRAND' => 'Branding',
            'SALES' => 'Sales',
            'AUDIT' => 'Audit',
            'ADMIN' => 'Admin / HR',
            'IT' => 'IT',
            'COLL' => 'Collector',
            'FIN' => 'Finance',
            'VEND' => 'Vending',
            'OPS' => 'Operation',
        ];

        foreach ($departments as $code => $name) {
            Department::query()->updateOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
