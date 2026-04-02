<?php

namespace Database\Seeders;

use App\Models\PostLike;
use App\Models\Posts;
use App\Models\User;
use Illuminate\Database\Seeder;

class PostLikesSeeder extends Seeder
{
    public function run(): void
    {
        $posts = Posts::all();
        $users = User::all();

        if ($posts->isEmpty() || $users->isEmpty()) {
            $this->command->info('No posts or users found, skipping PostLikesSeeder.');
            return;
        }

        foreach ($posts as $post) {
            $likers = $users->random(min(rand(1, 5), $users->count()));

            foreach ($likers as $user) {
                PostLike::firstOrCreate([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                ]);
            }

            // atualiza o contador de likes no próprio post
            $post->update(['likes' => PostLike::where('post_id', $post->id)->count()]);
        }
    }
}
