<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Seed projects and put users in them.
     */
    public function run(): void
    {
        $users = User::all();

        Project::factory(6)->create()->each(function (Project $project) use ($users) {
            $team = $users->random(4);

            // First user leads the project, the rest are members
            $project->users()->attach($team->first(), ['role' => 'lead']);
            $project->users()->attach($team->skip(1), ['role' => 'member']);
        });
    }
}
