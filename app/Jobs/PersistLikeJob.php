<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class PersistLikeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $postId;
    public int $userId;
    public string $action; // 'like' ou 'unlike'

    public function __construct(int $postId, int $userId, string $action = 'like')
    {
        $this->postId = $postId;
        $this->userId = $userId;
        $this->action = $action;
    }

    public function handle(): void
    {
        DB::transaction(function () {
            if ($this->action === 'like') {
                DB::table('post_likes')->insertOrIgnore([
                    'post_id' => $this->postId,
                    'user_id' => $this->userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('post_likes')
                    ->where('post_id', $this->postId)
                    ->where('user_id', $this->userId)
                    ->delete();
            }
            // Recalcula o contador canônico e persiste em `posts` e no Redis
            $count = DB::table('post_likes')->where('post_id', $this->postId)->count();

            DB::table('posts')->where('id', $this->postId)->update(['likes' => $count]);

            try {
                Redis::connection()->set("post:{$this->postId}:likes_count", $count);
            } catch (\Throwable $_) {
                // ignora erros do Redis
            }
        });
    }
}
