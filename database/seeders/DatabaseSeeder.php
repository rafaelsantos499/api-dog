<?php

namespace Database\Seeders;

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
        User::factory(10)->create();

        User::factory()->create([
            'name' => 'Rafael Santos',
            'email' => 'rafael499@gmail.com',
        ]);
        
        $this->call(PostsSeeder::class);
        $this->call(PostLikesSeeder::class);
        $this->call(PostCommentsSeeder::class);
    }
}
