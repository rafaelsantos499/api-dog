<?php

namespace Database\Seeders;

use App\Models\PostComment;
use App\Models\Posts;
use App\Models\User;
use Illuminate\Database\Seeder;

class PostCommentsSeeder extends Seeder
{
    public function run(): void
    {
        $posts = Posts::all();
        $users = User::all();

        if ($posts->isEmpty() || $users->isEmpty()) {
            $this->command->info('No posts or users found, skipping PostCommentsSeeder.');
            return;
        }

        $phrases = [
            'Que pet lindo! 😍',
            'Adorei essa foto!',
            'Que fofura!',
            'Meu coração derreteu 🥰',
            'Lindo demais!',
            'Que amor de pet!',
            'Essa foto ficou incrível!',
            'Quero um igual!',
            'Olha esse olhar 😭',
            'Muito fofo mesmo!',
        ];

        foreach ($posts as $post) {
            $count = rand(2, 5);
            $commenters = $users->random(min($count, $users->count()));

            foreach ($commenters as $user) {
                PostComment::firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['body' => $phrases[array_rand($phrases)]]
                );
            }

            $post->update([
                'comments_count' => PostComment::where('post_id', $post->id)->count(),
            ]);
        }
    }
}
