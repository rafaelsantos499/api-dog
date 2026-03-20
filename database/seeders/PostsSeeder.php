<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use App\Models\Posts;
use App\Models\User;

class PostsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = User::all();
        if ($users->isEmpty()) {
            $this->command->info('No users found, skipping PostsSeeder.');
            return;
        }

        foreach ($users as $user) {
            // cria 3 posts por usuário
            for ($i = 0; $i < 3; $i++) {
                $uuid = (string) Str::uuid();
                $baseDir = "user_photos/{$user->uuid}/{$uuid}";
                $filename = $uuid . '.webp';

                try {
                    $resp = Http::timeout(10)->get('https://picsum.photos/1200/800');
                    $image = $resp->body();
                } catch (\Throwable $e) {
                    $image = '';
                }

                Storage::disk('public')->put("{$baseDir}/original/{$filename}", $image);
                Storage::disk('public')->put("{$baseDir}/feed/{$filename}", $image);
                Storage::disk('public')->put("{$baseDir}/thumb/{$filename}", $image);

                Posts::create([
                    'uuid' => $uuid,
                    'user_id' => $user->id,
                    'original_path' => "{$baseDir}/original/{$filename}",
                    'feed_path'     => "{$baseDir}/feed/{$filename}",
                    'thumb_path'    => "{$baseDir}/thumb/{$filename}",
                    'weight'        => rand(1,50),
                    'age'           => rand(1,15),
                    'title'         => "Seeded photo {$i} for user {$user->id}",
                    'description'   => 'Seeded image for tests',
                    'is_published'  => true,
                    'published_at'  => now(),
                ]);
            }
        }
    }
}
