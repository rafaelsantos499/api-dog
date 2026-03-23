<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Posts;

class FeedController extends Controller
{
    
    /**
     * Return paginated feed.
     *
     * @OA\Get(
     *     path="/feed",
     *     tags={"Feed"},
     *     summary="Get paginated feed of posts (uuid + feed_path)",
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Items per page (max 100)",
     *         @OA\Schema(type="integer", default=15)
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Paginated feed",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="uuid", type="string"),
     *                     @OA\Property(property="feed_path", type="string")
     *                 )
     *             ),
     *             @OA\Property(
     *                 property="meta",
     *                 type="object",
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer"),
     *                 @OA\Property(property="last_page", type="integer")
     *             ),
     *             @OA\Property(
     *                 property="links",
     *                 type="object",
     *                 @OA\Property(property="first", type="string"),
     *                 @OA\Property(property="last", type="string"),
     *                 @OA\Property(property="prev", type="string", nullable=true),
     *                 @OA\Property(property="next", type="string", nullable=true)
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

        $payload = Cache::store('redis')->get($cacheKey);
        if (!$payload) {
            $query = Posts::query()
                ->where('is_published', true)
                ->whereNotNull('feed_path')
                ->select(['uuid', 'feed_path', 'published_at']);

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

            Cache::store('redis')->put($cacheKey, $payload, 15);
        }

        return response()->json($payload);
    }
}
