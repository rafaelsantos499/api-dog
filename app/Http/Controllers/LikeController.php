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

            // SADD é atômico: retorna 1 se adicionou, 0 se já era membro.
            // Elimina a race condition entre SISMEMBER e MULTI/EXEC.
            $added = $redis->sadd($setKey, $userId);

            if ($added === 0) {
                $current = $redis->get($countKey) ?? $photo->likes;
                return response()->json(['likes' => (int) $current]);
            }

            $current = $redis->incr($countKey);
            $redis->expire($setKey, 2592000); // TTL de 30 dias no set

            PersistLikeJob::dispatch($postId, $userId, 'like');

            return response()->json(['likes' => (int) $current]);
        } catch (\Throwable $e) {
            PersistLikeJob::dispatch($postId, $userId, 'like');
            return response()->json(['likes' => (int) $photo->likes]);
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

            // SREM é atômico: retorna 1 se removeu, 0 se não era membro.
            // Elimina a race condition entre SISMEMBER e MULTI/EXEC.
            $removed = $redis->srem($setKey, $userId);

            if ($removed === 0) {
                $current = $redis->get($countKey) ?? $photo->likes;
                return response()->json(['likes' => (int) $current]);
            }

            // decrementa fora de MULTI: obtém o valor real pós-decremento
            $new = (int) $redis->decr($countKey);
            if ($new < 0) {
                $redis->set($countKey, 0);
                $new = 0;
            }

            PersistLikeJob::dispatch($postId, $userId, 'unlike');

            return response()->json(['likes' => $new]);
        } catch (\Throwable $e) {
            PersistLikeJob::dispatch($postId, $userId, 'unlike');
            return response()->json(['likes' => (int) $photo->likes]);
        }
    }
}
