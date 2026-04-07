<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PersistViewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $postId;
    public int $userId;

    public function __construct(int $postId, int $userId)
    {
        $this->postId = $postId;
        $this->userId = $userId;
    }

    public function handle(): void
    {
        DB::transaction(function () {
            // insertOrIgnore: se o usuário já visualizou, não faz nada
            $inserted = DB::table('post_views')->insertOrIgnore([
                'post_id'    => $this->postId,
                'user_id'    => $this->userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted === 0) {
                // Visualização duplicada, não incrementa
                return;
            }

            $count = DB::table('post_views')->where('post_id', $this->postId)->count();

            DB::table('posts')->where('id', $this->postId)->update(['views' => $count]);

            try {
                Redis::connection()->set("post:{$this->postId}:views_count", $count);
            } catch (\Throwable $_) {
                // ignora erros do Redis
            }
        });
    }
}
