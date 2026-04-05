<?php

namespace App\Http\Controllers;

use App\Models\PostLike;
use App\Models\Posts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class FeedController extends Controller
{
    
    /**
     * Retorna o feed paginado usando paginação por cursor.
     *
     * Uso do cursor: o endpoint retorna `meta.next_cursor` (base64 de "published_at|id").
     * Os clientes devem enviar esse valor no parâmetro de query `cursor` para obter a próxima página.
     *
     * @OA\Get(
     *     path="/feed",
     *     tags={"Feed"},
     *     summary="Retorna o feed paginado por cursor",
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Itens por página (máx 100)",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="cursor",
     *         in="query",
     *         description="Token opaco retornado em `meta.next_cursor` da resposta anterior. Formato: base64('published_at|id'). Envie para buscar a próxima página.",
     *         @OA\Schema(type="string", nullable=true)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Feed paginado por cursor",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="uuid", type="string"),
     *                     @OA\Property(property="feed_url", type="string", nullable=true),
     *                     @OA\Property(property="published_at", type="string", format="date-time"),
     *                     @OA\Property(property="id", type="integer")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="next_cursor", type="string", nullable=true, description="Token base64 para buscar a próxima página; null quando não houver mais itens")
     *             )
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 15;

        $cursor = $request->query('cursor');
        $cursorLabel = $cursor ? $cursor : 'start';
        $cacheKey = sprintf('feed:perpage:%d:cursor:%s', $perPage, $cursorLabel);

        /** @var \Illuminate\Cache\Repository $redisStore */
        $redisStore = Cache::store('redis');
        $lock       = null;
        $payload    = $redisStore->get($cacheKey);

        if (!$payload) {
            $lock = $redisStore->lock('lock:' . $cacheKey, 10);
            try {
                $lock->block(5);
                $payload = $redisStore->get($cacheKey);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                $lock = null;
            }
        }

        if (!$payload) {
            $query = Posts::query()
                ->where('is_published', true)
                ->whereNotNull('feed_path')
                ->select(['id','uuid', 'feed_path', 'published_at']);

            if ($cursor) {
                try {
                    $decoded = base64_decode($cursor, true);
                    if ($decoded !== false && strpos($decoded, '|') !== false) {
                        [$cursorPublishedAt, $cursorId] = explode('|', $decoded, 2);
                        if ($cursorPublishedAt !== '' && $cursorId !== '') {
                            $query->where(function ($q) use ($cursorPublishedAt, $cursorId) {
                                $q->where('published_at', '<', $cursorPublishedAt)
                                  ->orWhere(function ($q2) use ($cursorPublishedAt, $cursorId) {
                                      $q2->where('published_at', $cursorPublishedAt)
                                         ->where('id', '<', (int) $cursorId);
                                  });
                            });
                        }
                    }
                } catch (\Throwable $e) {
                    
                }
            }

            $query->orderByDesc('published_at')->orderByDesc('id')->limit($perPage);

            $items = $query->get();

            $svc = app(\App\Services\StorageService::class);

            $data = $items->map(function ($item) use ($svc) {
                return [
                    'uuid' => $item->uuid,
                    'feed_url' => $item->feed_path ? $svc->url($item->feed_path) : null,
                    'published_at' => $item->published_at ? $item->published_at->toDateTimeString() : null,
                    'id' => $item->id,
                ];
            })->toArray();

            $nextCursor = null;
            if (count($data) === $perPage) {
                $last = end($data);
                $nextCursor = base64_encode(sprintf('%s|%s', $last['published_at'], $last['id']));
            }

            $payload = [
                'data' => $data,
                'meta' => [
                    'per_page' => $perPage,
                    'next_cursor' => $nextCursor,
                ],
            ];

            $redisStore->put($cacheKey, $payload, 15);
        }

        optional($lock)->release();

        return response()->json($payload);
    }

    /**
     * Feed personalizado do usuário autenticado, ordenado por wager (weight).
     *
     * O score de ranking combina o `weight` do post com a recência (`published_at`).
     * O cursor codifica `weight|id` (base64) para permitir paginação estável.
     *
     * @OA\Get(
     *     path="/feed/personal",
     *     tags={"Feed"},
     *     summary="Feed pessoal do usuário autenticado (ordenado por wager)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Itens por página (máx 100)",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="cursor",
     *         in="query",
     *         description="Token opaco retornado em `meta.next_cursor`. Formato: base64('weight|id').",
     *         @OA\Schema(type="string", nullable=true)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Feed pessoal paginado por cursor",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="uuid", type="string"),
     *                     @OA\Property(property="feed_url", type="string", nullable=true),
     *                     @OA\Property(property="published_at", type="string", format="date-time"),
     *                     @OA\Property(property="weight", type="integer", description="Wager score do post"),
     *                     @OA\Property(property="likes", type="integer"),
     *                     @OA\Property(property="liked", type="boolean", description="Se o usuário autenticado curtiu o post"),
     *                     @OA\Property(property="id", type="integer")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="next_cursor", type="string", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function personal(Request $request)
    {
        $user    = $request->user();
        $userId  = $user->id;

        $perPage = (int) $request->query('per_page', 15);
        $perPage = $perPage > 0 && $perPage <= 100 ? $perPage : 15;

        $cursor      = $request->query('cursor');
        $cursorLabel = $cursor ?: 'start';
        $cacheKey    = sprintf('feed:personal:user:%d:perpage:%d:cursor:%s', $userId, $perPage, $cursorLabel);

        /** @var \Illuminate\Cache\Repository $redisStore */
        $redisStore = Cache::store('redis');
        $lock       = null;
        $payload    = $redisStore->get($cacheKey);

        if (!$payload) {
            $lock = $redisStore->lock('lock:' . $cacheKey, 10);
            try {
                // faz o request esperar até 5 segundos para adquirir a trava, caso outro processo já esteja gerando o cache.
                $lock->block(5); 
                $payload = $redisStore->get($cacheKey);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                $lock = null;
            }
        }

        if (!$payload) {
            $query = Posts::query()
                ->where('is_published', true)
                ->whereNotNull('feed_path')
                ->select(['id', 'uuid', 'feed_path', 'published_at', 'weight', 'likes']);

            if ($cursor) {
                try {
                    $decoded = base64_decode($cursor, true);
                    if ($decoded !== false && strpos($decoded, '|') !== false) {
                        [$cursorWeight, $cursorId] = explode('|', $decoded, 2);
                        if ($cursorWeight !== '' && $cursorId !== '') {
                            $query->where(function ($q) use ($cursorWeight, $cursorId) {
                                $q->where('weight', '<', (int) $cursorWeight)
                                  ->orWhere(function ($q2) use ($cursorWeight, $cursorId) {
                                      $q2->where('weight', (int) $cursorWeight)
                                         ->where('id', '<', (int) $cursorId);
                                  });
                            });
                        }
                    }
                } catch (\Throwable $e) {
                    // ignora cursor inválido; retorna primeira página
                }
            }

            $query->orderByDesc('weight')->orderByDesc('id')->limit($perPage);

            $items = $query->get();

            $svc = app(\App\Services\StorageService::class);

            // "liked" não entra no cache: sobreposto abaixo com estado sempre fresco
            $data = $items->map(function ($item) use ($svc) {
                return [
                    'uuid'         => $item->uuid,
                    'feed_url'     => $item->feed_path ? $svc->url($item->feed_path) : null,
                    'published_at' => $item->published_at ? $item->published_at->toDateTimeString() : null,
                    'weight'       => (int) $item->weight,
                    'likes'        => (int) $item->likes,
                    'id'           => $item->id,
                ];
            })->toArray();

            $nextCursor = null;
            if (count($data) === $perPage) {
                $last       = end($data);
                $nextCursor = base64_encode(sprintf('%s|%s', $last['weight'], $last['id']));
            }

            $payload = [
                'data' => $data,
                'meta' => [
                    'per_page'    => $perPage,
                    'next_cursor' => $nextCursor,
                ],
            ];

            $redisStore->put($cacheKey, $payload, 300);
        }

        optional($lock)->release();

        // overlay do "liked": fora do cache para sempre refletir likes/unlikes recentes
        $postIds  = array_column($payload['data'], 'id');
        $likedMap = [];
        try {
            $redis   = Redis::connection();
            $results = $redis->pipeline(function ($pipe) use ($postIds, $userId) {
                foreach ($postIds as $pid) {
                    $pipe->sismember("post:{$pid}:liked_by", $userId);
                }
            });
            foreach ($postIds as $i => $pid) {
                $likedMap[$pid] = (bool) ($results[$i] ?? false);
            }
        } catch (\Throwable $e) {
            // Redis indisponível: fallback para DB em lote
            $likedMap = PostLike::where('user_id', $userId)
                ->whereIn('post_id', $postIds)
                ->pluck('post_id')
                ->mapWithKeys(fn($pid) => [$pid => true])
                ->all();
        }

        $response = $payload;
        foreach ($response['data'] as &$item) {
            $item['liked'] = $likedMap[$item['id']] ?? false;
        }
        unset($item);

        return response()->json($response);
    }
}
