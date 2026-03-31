<?php

namespace App\Http\Controllers;

use App\Jobs\PersistLikeJob;
use App\Models\Posts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class LikeController extends Controller
{
    /**
     * Curtir uma foto (resposta rápida via Redis, persistida assincronamente)
     *
     * @OA\Post(
     *     path="/photos/{photo}/like",
     *     tags={"UserPhoto"},
     *     summary="Curtir uma foto",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="photo",
     *         in="path",
     *         required=true,
     *         description="ID ou UUID da foto",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Contagem atual de likes",
     *         @OA\JsonContent(
     *             @OA\Property(property="likes", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Proibido")
     * )
     */
    public function like(Request $request, Posts $photo)
    {
        $user = $request->user();
        $userId = $user->id;
        $postId = $photo->id;

        $setKey = "photo:{$postId}:liked_by";
        $countKey = "photo:{$postId}:likes_count";

        try {
            $redis = Redis::connection();

            // se já curtiu, retorna a contagem atual
            if ($redis->sismember($setKey, $userId)) {
                $current = $redis->get($countKey) ?? $photo->likes;
                return response()->json(['likes' => (int)$current]);
            }

            // operação atômica: MULTI/EXEC
            $redis->multi();
            $redis->sadd($setKey, $userId);
            $redis->incr($countKey);
            $redis->exec();

            PersistLikeJob::dispatch($postId, $userId, 'like');

            $current = $redis->get($countKey) ?? $photo->likes;
            return response()->json(['likes' => (int)$current]);
        } catch (\Throwable $e) {
            PersistLikeJob::dispatch($postId, $userId, 'like');
            return response()->json(['likes' => (int)$photo->likes]);
        }
    }

    /**
     * Remover like de uma foto (resposta rápida via Redis, persistida assincronamente)
     *
     * @OA\Post(
     *     path="/photos/{photo}/unlike",
     *     tags={"UserPhoto"},
     *     summary="Remover like de uma foto",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="photo",
     *         in="path",
     *         required=true,
     *         description="ID ou UUID da foto",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Contagem atual de likes",
     *         @OA\JsonContent(
     *             @OA\Property(property="likes", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado"),
     *     @OA\Response(response=403, description="Proibido")
     * )
     */
    public function unlike(Request $request, Posts $photo)
    {
        $user = $request->user();
        $userId = $user->id;
        $postId = $photo->id;

        $setKey = "photo:{$postId}:liked_by";
        $countKey = "photo:{$postId}:likes_count";

        try {
            $redis = Redis::connection();

            if (! $redis->sismember($setKey, $userId)) {
                $current = $redis->get($countKey) ?? $photo->likes;
                return response()->json(['likes' => (int)$current]);
            }

            $redis->multi();
            $redis->srem($setKey, $userId);
            // decrementar sem ficar abaixo de zero
            $new = $redis->decr($countKey);
            if ($new < 0) {
                $redis->set($countKey, 0);
            }
            $redis->exec();

            PersistLikeJob::dispatch($postId, $userId, 'unlike');

            $current = $redis->get($countKey) ?? $photo->likes;
            return response()->json(['likes' => (int)$current]);
        } catch (\Throwable $e) {
            PersistLikeJob::dispatch($postId, $userId, 'unlike');
            return response()->json(['likes' => (int)$photo->likes]);
        }
    }
}
