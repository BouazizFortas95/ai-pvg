<?php

namespace Database\Seeders;

use App\Services\Motion\MotionLibrary;
use Illuminate\Database\Seeder;

class MotionComponentSeeder extends Seeder
{
    public function run(): void
    {
        $library = app(MotionLibrary::class);
        $count = $library->seedStarterComponents();
        $this->command?->info("Seeded {$count} original in-house motion components.");
    }
}

