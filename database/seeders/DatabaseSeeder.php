<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
        ]);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ])->assignRole('super-admin');

        User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
        ])->assignRole('admin');

        User::factory()->create([
            'name' => 'Staff User',
            'email' => 'staff@example.com',
        ])->assignRole('staff');

        // Users without any role
        User::factory(25)->create();

        // Put every user in a random department
        $departments = Department::all();

        User::query()->each(function (User $user) use ($departments) {
            $user->department()->associate($departments->random());
            $user->save();
        });

        $this->call(ProjectSeeder::class);
    }
}
