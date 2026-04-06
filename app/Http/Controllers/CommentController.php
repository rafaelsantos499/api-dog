<?php

namespace App\Http\Controllers;

use App\Models\PostComment;
use App\Services\CommentValidationService;
use App\Models\Posts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class CommentController extends Controller
{
    /**
     * @OA\Get(
     *     path="/posts/{post}/comments",
     *     tags={"Comments"},
     *     summary="Lista comentários de um post",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Lista paginada de comentários",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="uuid", type="string"),
     *                 @OA\Property(property="body", type="string"),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="uuid", type="string"),
     *                     @OA\Property(property="name", type="string")
     *                 )
     *             )),
     *             @OA\Property(property="next_cursor", type="string", nullable=true)
     *         )
     *     )
     * )
     */
    public function index(Posts $post, Request $request)
    {
        $comments = $post->comments()
            ->with('user:id,uuid,name')
            ->cursorPaginate(20);

        return response()->json($comments);
    }

    /**
     * @OA\Post(
     *     path="/posts/{post}/comments",
     *     tags={"Comments"},
     *     summary="Adiciona um comentário ao post",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"body"},
     *             @OA\Property(property="body", type="string", maxLength=1000, example="Que pet lindo!")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Comentário criado",
     *         @OA\JsonContent(
     *             @OA\Property(property="uuid", type="string"),
     *             @OA\Property(property="body", type="string"),
     *             @OA\Property(property="created_at", type="string", format="date-time"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="uuid", type="string"),
     *                 @OA\Property(property="name", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validação falhou")
     * )
     */
    public function store(Posts $post, Request $request)
    {
        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $user = $request->user();

        // Verifica se o usuário está em timeout de comentários
        if (! empty($user->comment_timeout_until) && Carbon::parse($user->comment_timeout_until)->isFuture()) {
            $until = Carbon::parse($user->comment_timeout_until);
            $remaining = $until->diffForHumans();

            return response()->json([
                'message' => "Você foi temporariamente impedido de comentar. Tente novamente em {$remaining}.",
                'blocked_until' => $until->toDateTimeString(),
            ], 429);
        }

        // Rate limit for VALID comments (prevent spamming even if allowed)
        $userId = $user->id ?? null;
        if ($userId) {
            $commentsWindow = (int) config('ai.comment_validation.comments_window_minutes', 60);
            $maxComments = (int) config('ai.comment_validation.max_comments', 30);
            $countKey = "ai:comment:count:{$userId}";

            if (! Cache::has($countKey)) {
                Cache::put($countKey, 0, now()->addMinutes($commentsWindow));
            }

            $current = (int) Cache::get($countKey, 0);
            if ($current >= $maxComments) {
                return response()->json([
                    'message' => "Taxa de comentários excedida. Aguarde alguns minutos antes de postar novamente.",
                    'limit' => $maxComments,
                    'window_minutes' => $commentsWindow,
                ], 429);
            }
        }

        if (config('ai.comment_validation.enabled', true)) {
            $validator = app(CommentValidationService::class);
            $result = $validator->validate($request->input('body'));

            $threshold = (int) config('ai.comment_validation.block_threshold', 70);

            if ($result['blocked'] || ($result['score'] >= $threshold)) {
                // Incrementa contador de bloqueios em janela móvel
                $userId = $user->id ?? null;
                if ($userId) {
                    $window = (int) config('ai.comment_validation.window_minutes', 60);
                    $maxBlocked = (int) config('ai.comment_validation.max_blocked', 5);
                    $timeoutMinutes = (int) config('ai.comment_validation.timeout_minutes', 30);

                    $key = "ai:comment:blocks:{$userId}";

                    if (! Cache::has($key)) {
                        Cache::put($key, 0, now()->addMinutes($window));
                    }

                    $count = Cache::increment($key);

                    if ($count >= $maxBlocked) {
                        // Aplica timeout no usuário
                        try {
                            $user->comment_timeout_until = Carbon::now()->addMinutes($timeoutMinutes);
                            $user->save();
                        } catch (\Throwable $e) {
                            // não impedir o retorno caso falhe
                        }

                        $until = Carbon::parse($user->comment_timeout_until);

                        return response()->json([
                            'message' => 'Você foi temporariamente impedido de comentar devido a múltiplos conteúdos bloqueados. Tente novamente em ' . $until->diffForHumans() . '.',
                            'blocked_until' => $until->toDateTimeString(),
                        ], 429);
                    }
                }

                return response()->json([
                    'message' => 'Comentário não pode ser publicado por violar nossas regras.',
                    'ai' => [
                        'blocked' => $result['blocked'],
                        'score'   => $result['score'],
                        'reason'  => $result['reason'],
                    ],
                ], 422);
            }
        }

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body'    => $request->input('body'),
        ]);

        // Increment valid comment counter for rate limiting window
        if ($userId) {
            $countKey = "ai:comment:count:{$userId}";
            try {
                if (! Cache::has($countKey)) {
                    $commentsWindow = (int) config('ai.comment_validation.comments_window_minutes', 60);
                    Cache::put($countKey, 0, now()->addMinutes($commentsWindow));
                }
                Cache::increment($countKey);
            } catch (\Throwable $e) {
                // não interrompe criação por falha no cache
            }
        }

        $post->increment('comments_count');

        return response()->json(
            $comment->load('user:id,uuid,name'),
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/posts/{post}/comments/{comment}",
     *     tags={"Comments"},
     *     summary="Edita um comentário (somente o autor)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\Parameter(name="comment", in="path", required=true, description="UUID do comentário", @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"body"},
     *             @OA\Property(property="body", type="string", maxLength=1000)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Comentário atualizado"),
     *     @OA\Response(response=403, description="Não autorizado")
     * )
     */
    public function update(Posts $post, PostComment $comment, Request $request)
    {
        if ($comment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $comment->update(['body' => $request->input('body')]);

        return response()->json($comment);
    }

    /**
     * @OA\Delete(
     *     path="/posts/{post}/comments/{comment}",
     *     tags={"Comments"},
     *     summary="Remove um comentário (autor ou dono do post)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\Parameter(name="comment", in="path", required=true, description="UUID do comentário", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Comentário removido", @OA\JsonContent(@OA\Property(property="message", type="string"))),
     *     @OA\Response(response=403, description="Não autorizado")
     * )
     */
    public function destroy(Posts $post, PostComment $comment, Request $request)
    {
        $userId = $request->user()->id;

        if ($comment->user_id !== $userId && $post->user_id !== $userId) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $comment->delete();
        $post->decrement('comments_count');

        return response()->json(['message' => 'Comentário removido.']);
    }
}
