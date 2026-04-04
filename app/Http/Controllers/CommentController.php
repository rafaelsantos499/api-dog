<?php

namespace App\Http\Controllers;

use App\Models\PostComment;
use App\Models\Posts;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * @OA\Get(
     *     path="/photos/{post}/comments",
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
     */
    public function index(Posts $post, Request $request)
    {
        $comments = $post->comments()
            ->with('user:id,name,uuid')
            ->cursorPaginate(20);

        return response()->json($comments);
    }

    /**
     * @OA\Post(
     *     path="/photos/{post}/comments",
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
     */
    public function store(Posts $post, Request $request)
    {
        $request->validate([
            'body' => 'required|string|max:1000',
        ]);

        $comment = $post->comments()->create([
            'user_id' => $request->user()->id,
            'body'    => $request->input('body'),
        ]);

        $post->increment('comments_count');

        return response()->json(
            $comment->load('user:id,name,uuid'),
            201
        );
    }

    /**
     * @OA\Put(
     *     path="/photos/{post}/comments/{comment}",
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
     *     path="/photos/{post}/comments/{comment}",
     *     tags={"Comments"},
     *     summary="Remove um comentário (autor ou dono do post)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="post", in="path", required=true, description="UUID do post", @OA\Schema(type="string")),
     *     @OA\Parameter(name="comment", in="path", required=true, description="ID do comentário", @OA\Schema(type="integer")),
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
