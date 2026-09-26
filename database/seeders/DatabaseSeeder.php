<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * `php artisan db:seed` builds the full demo: realistic staff and
     * students, this term's exams, Review Bot content and ten graduated
     * cohorts of history. ShowcaseSeeder describes what is in it, and
     * `php artisan demo:purge` takes it all out again.
     */
    public function run(): void
    {
        $this->call(ShowcaseSeeder::class);
    }
}
