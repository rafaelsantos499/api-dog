<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FirebaseAuthController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\SocialAuthController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response()->json([
        'status'  => 'ok',
        'message' => 'API is running teste',
    ]);
});

// Autenticação email/senha
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);

    // @OA\Post(path="/auth/login", tags={"Auth"}, summary="Login com email e senha")
    Route::middleware([StartSession::class])->post('/login',    [AuthController::class, 'login']);

    // @OA\Get(path="/auth/google", tags={"Auth"}, summary="Redireciona para login Google OAuth")
    Route::get('/google',          [SocialAuthController::class, 'redirectToGoogle']);
    // @OA\Get(path="/auth/google/callback", tags={"Auth"}, summary="Callback do Google OAuth")
    Route::get('/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

    // @OA\Post(path="/auth/firebase", tags={"Auth"}, summary="Login com Firebase (Google)")
    Route::post('/firebase', [FirebaseAuthController::class, 'login']);


    // Rotas protegidas
    Route::middleware('auth:sanctum')->group(function () {
        // @OA\Post(path="/auth/refresh", tags={"Auth"}, summary="Renova o access token usando o refresh token")
        Route::post('/refresh', [AuthController::class, 'refresh']);
        // @OA\Post(path="/auth/logout", tags={"Auth"}, summary="Logout (revoga o token atual)")
        Route::post('/logout',  [AuthController::class, 'logout']);
        // @OA\Get(path="/auth/me", tags={"Auth"}, summary="Retorna o usurio autenticado")
        Route::get('/me',       [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->prefix('posts')->group(function () {
    /**
     * @OA\Post(
     *     path="/posts/upload",
     *     tags={"Post"},
     *     summary="Upload user photos (original, feed, thumb)",
     *     security={{"bearerAuth":{}}}
     * )
     *
     * @OA\Get(
     *     path="/posts/{post}",
     *     tags={"Post"},
     *     summary="Get a single post by ID or UUID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, @OA\Schema(type="string"))
     * )
     *
     * @OA\Put(
     *     path="/posts/{post}",
     *     tags={"Post"},
     *     summary="Update post metadata",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, @OA\Schema(type="string"))
     * )
     *
     * @OA\Delete(
     *     path="/posts/{post}",
     *     tags={"Post"},
     *     summary="Delete a post",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, @OA\Schema(type="string"))
     * )
     */
    Route::post('upload', [PostController::class, 'uploadPhoto']);
    Route::get('{post}', [PostController::class, 'show']);
    Route::put('{post}', [PostController::class, 'update']);
    Route::delete('{post}', [PostController::class, 'destroy']);
    // Like/unlike via Redis + queued job
    /**
     * @OA\Post(
     *     path="/posts/{post}/like",
     *     tags={"Post"},
     *     summary="Curtir um post (via Redis, persistido assíncrono)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="ID ou UUID do post", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Contagem atual de likes", @OA\JsonContent(@OA\Property(property="likes", type="integer"))),
     * )
     */
    Route::post('{post}/like', [\App\Http\Controllers\LikeController::class, 'like']);

    /**
     * @OA\Post(
     *     path="/posts/{post}/unlike",
     *     tags={"Post"},
     *     summary="Remover like de um post (via Redis, persistido assíncrono)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="ID ou UUID do post", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Contagem atual de likes", @OA\JsonContent(@OA\Property(property="likes", type="integer"))),
     * )
     */
    Route::post('{post}/unlike', [\App\Http\Controllers\LikeController::class, 'unlike']);

    // Comentários
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
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="body", type="string"),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="uuid", type="string")
     *                 )
     *             )),
     *             @OA\Property(property="next_cursor", type="string", nullable=true)
     *         )
     *     )
     * )
     *
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
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="body", type="string"),
     *             @OA\Property(property="created_at", type="string", format="date-time"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="uuid", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validação falhou")
     * )
     *
     * @OA\Put(
     *     path="/posts/{post}/comments/{comment}",
     *     tags={"Comments"},
     *     summary="Edita um comentário (somente o autor)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\Parameter(name="comment", in="path", required=true, description="ID do comentário", @OA\Schema(type="integer")),
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
     *
     * @OA\Delete(
     *     path="/posts/{post}/comments/{comment}",
     *     tags={"Comments"},
     *     summary="Remove um comentário (autor ou dono do post)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\Parameter(name="comment", in="path", required=true, description="ID do comentário", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Comentário removido", @OA\JsonContent(@OA\Property(property="message", type="string"))),
     *     @OA\Response(response=403, description="Não autorizado")
     * )
     */
    Route::get('{post}/comments',               [\App\Http\Controllers\CommentController::class, 'index']);
    Route::post('{post}/comments',              [\App\Http\Controllers\CommentController::class, 'store']);
    Route::put('{post}/comments/{comment}',     [\App\Http\Controllers\CommentController::class, 'update']);
    Route::delete('{post}/comments/{comment}',  [\App\Http\Controllers\CommentController::class, 'destroy']);
});

// Public feed: paginated, most recent first
Route::get('/feed', [\App\Http\Controllers\FeedController::class, 'index']);

// Personal feed: authenticated, ranked by wager (weight)
Route::middleware('auth:sanctum')->get('/feed/personal', [\App\Http\Controllers\FeedController::class, 'personal']);
