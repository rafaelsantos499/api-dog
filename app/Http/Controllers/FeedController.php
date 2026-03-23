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

        $page = (int) $request->query('page', 1);
        $cacheKey = sprintf('feed:perpage:%d:page:%d', $perPage, $page);

        $payload = Cache::store('redis')->get($cacheKey);
        if (!$payload) {
            $query = Posts::query()
                ->where('is_published', true)
                ->whereNotNull('feed_path')
                ->orderByDesc('published_at')
                ->orderByDesc('created_at')
                ->select(['uuid', 'feed_path']);

            $paginator = $query->paginate($perPage)->withQueryString();

            $svc = app(\App\Services\StorageService::class);

            $collection = $paginator->getCollection()->transform(function ($item) use ($svc) {
                return [
                    'uuid' => $item->uuid,
                    'feed_url' => $item->feed_path ? $svc->url($item->feed_path) : null,
                ];
            });

            $paginator->setCollection($collection);

            $payload = $paginator->toArray();
            Cache::store('redis')->put($cacheKey, $payload, 15);
        }

        return response()->json($payload);
    }
}
