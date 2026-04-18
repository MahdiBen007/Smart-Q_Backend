<?php

namespace Database\Seeders;

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
        if (! (bool) env('SEED_OPERATIONAL_SCENARIO', false)) {
            return;
        }

        $this->call([
            OperationalScenarioSeeder::class,
        ]);
    }
}
