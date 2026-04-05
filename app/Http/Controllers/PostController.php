<?php

namespace App\Http\Controllers;

use App\Models\Posts;
use Illuminate\Http\Request;
use App\Services\ImageService;
use App\Services\PetValidationService;
use App\Services\StorageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Redis;

class PostController extends Controller
{   
    /**
     * Upload a user photo.
     *
     * @OA\Post(
     *     path="/posts/upload",
     *     tags={"Post"},
     *     summary="Upload de post",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"photo", "title"},
     *                 @OA\Property(
     *                     property="photo",
     *                     type="string",
     *                     format="binary",
     *                     description="Photo file (jpeg, png, jpg, gif, webp, max 5MB)"
     *                 ),
     *                 @OA\Property(
     *                     property="weight",
     *                     type="number",
     *                     format="float",
     *                     description="Weight (optional)"
     *                 ),
     *                 @OA\Property(
     *                     property="age",
     *                     type="integer",
     *                     description="Age (optional)"
     *                 ),
     *                 @OA\Property(
     *                     property="title",
     *                     type="string",
     *                     maxLength=255,
     *                     description="Title"
     *                 ),
     *                 @OA\Property(
     *                     property="description",
     *                     type="string",
     *                     maxLength=2000,
     *                     description="Description (optional)"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Post criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Post uploaded successfully."),
     *             @OA\Property(
     *                 property="post",
     *                 type="object",     *                
     *                 @OA\Property(property="original_path", type="string"),
     *                 @OA\Property(property="feed_path", type="string"),
     *                 @OA\Property(property="thumb_path", type="string"),
     *                 @OA\Property(property="original_url", type="string"),
     *                 @OA\Property(property="feed_url", type="string"),
     *                 @OA\Property(property="thumb_url", type="string"),
     *                 @OA\Property(property="weight", type="number"),
     *                 @OA\Property(property="age", type="integer"),
     *                 @OA\Property(property="title", type="string"),
     *                 @OA\Property(property="description", type="string"),
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="errors", type="object")
     *         )
     *     )
     * )
     */
    public function uploadPhoto(Request $request, ImageService $imageService, StorageService $storageService, PetValidationService $petValidator)
    {
        $request->validate([
            'image'       => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'weight'      => 'nullable|numeric|min:0',
            'age'         => 'nullable|integer|min:0',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $uploaded = $request->file('image');

        // Validação por IA: moderação de conteúdo + verificação de pet
        if (config('ai.pet_validation.enabled')) {
            $validation = $petValidator->validate($uploaded);

            if (! $validation['safe']) {
                return response()->json([
                    'message' => 'A imagem enviada contém conteúdo inapropriado e não pode ser publicada.',
                ], 422);
            }

            if (! $validation['valid']) {
                return response()->json([
                    'message' => 'A imagem enviada não parece conter um animal de estimação. Por favor, envie uma foto do seu pet.',
                    'reason'  => $validation['reason'] ?: null,
                ], 422);
            }
        }

        // Obtém o usuário autenticado (rota está protegida por `auth:sanctum`)
        $user     = $request->user();
        $userId   = $user->id;
        $userUuid = $user->uuid;

        $ext      = 'webp';
        $basename = uniqid();
        $filename = $basename . '.' . $ext;

        // Criar a linha no banco primeiro para obter um id consistente para o path
        $photo = Posts::create([
            'user_id'       => $userId,
            'original_path' => null,
            'feed_path'     => null,
            'thumb_path'    => null,
            'weight'        => $request->input('weight'),
            'age'           => $request->input('age'),
            'title'         => $request->input('title'),
            'description'   => $request->input('description'),
        ]);

        
        if (empty($photo->uuid)) {
            try {
                $photo->refresh();
            } catch (\Throwable $_) {
              
            }
        }

        $photoId = $photo->uuid ?? $photo->id;

        // paths baseadas em user + photo id
        $baseDir = "user_photos/{$userUuid}/{$photoId}";
        $originalPath = "{$baseDir}/original/{$filename}";
        $feedPath = "{$baseDir}/feed/{$filename}";
        $thumbPath = "{$baseDir}/thumb/{$filename}";

        try {
            // Processa variantes e salva
            $variants = $imageService->makeVariants($uploaded, $filename);
            $storageService->put($originalPath, $variants['original']);
            $storageService->put($feedPath, $variants['feed']);
            $storageService->put($thumbPath, $variants['thumb']);

            // Atualiza o registro com os paths
            $photo->update([
                'original_path' => $originalPath,
                'feed_path'     => $feedPath,
                'thumb_path'    => $thumbPath,
            ]);
        } catch (\Throwable $e) {
            try {
                $photo->delete();
            } catch (\Throwable $_) {
                // ignore
            }

            throw $e;
        }

            // Invalida cache do topo do feed para qualquer per_page (apaga keys matching)
            try {
                $redis = Redis::connection();
                $keys = $redis->keys('feed:perpage:*:cursor:start');
                if (!empty($keys)) {
                    foreach ($keys as $k) {
                        $redis->del($k);
                    }
                }
            } catch (\Throwable $_) {
            }

            $postResponse = $photo->fresh()->makeHidden('id');
        return response()->json([
            'message' => 'Post uploaded successfully.' . config('ai.pet_validation.enabled'),
            'post'    => $postResponse,
        ], 201);
    }

    /**
     * Buscar um post.
     *
     * @OA\Get(
     *     path="/posts/{post}",
     *     tags={"Post"},
     *     summary="Buscar um post por ID ou UUID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="post",
     *         in="path",
     *         required=true,
     *         description="ID ou UUID do post",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Post encontrado",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="post",
     *                 type="object",
     *                 @OA\Property(property="uuid", type="string"),
     *                 @OA\Property(property="user_id", type="integer"),
     *                 @OA\Property(property="original_path", type="string"),
     *                 @OA\Property(property="feed_path", type="string"),
     *                 @OA\Property(property="thumb_path", type="string"),
     *                 @OA\Property(property="original_url", type="string"),
     *                 @OA\Property(property="feed_url", type="string"),
     *                 @OA\Property(property="thumb_url", type="string"),
     *                 @OA\Property(property="weight", type="number"),
     *                 @OA\Property(property="age", type="integer"),
     *                 @OA\Property(property="title", type="string"),
     *                 @OA\Property(property="description", type="string"),
     *                 @OA\Property(property="is_published", type="boolean"),
     *                 @OA\Property(property="published_at", type="string", format="date-time"),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Post not found"
     *     )
     * )
     */
    public function show(Posts $post)
    {
        return response()->json([
            'post' => $post->makeHidden('id'),
        ]);
    }
    
    /**
     * Atualizar metadados de um post.
     *
     * @OA\Put(
     *     path="/posts/{post}",
     *     tags={"Post"},
     *     summary="Atualizar metadados de um post",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="post",
     *         in="path",
     *         required=true,
     *         description="ID or UUID do post",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(property="weight", type="number", format="float"),
     *                 @OA\Property(property="age", type="integer"),
     *                 @OA\Property(property="title", type="string", maxLength=255),
     *                 @OA\Property(property="description", type="string", maxLength=2000),
     *                 @OA\Property(property="is_published", type="boolean")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Photo updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="photo", type="object")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, Posts $post)
    {
        $request->validate([
            'weight'      => 'nullable|numeric|min:0',
            'age'         => 'nullable|integer|min:0',
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_published'=> 'nullable|boolean',
        ]);

        $user = $request->user();
        if ($user->id !== $post->user_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $post->update($request->only(['weight', 'age', 'title', 'description', 'is_published']));

        return response()->json([
            'message' => 'Post updated successfully.',
            'post'    => $post->fresh()->makeHidden('id'),
        ]);
    }
   
    /**
     * Delete a photo and its stored files.
     *
     * @OA\Delete(
     *     path="/posts/{post}",
     *     tags={"Post"},
     *     summary="Deletar um post",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="post",
     *         in="path",
     *         required=true,
     *         description="ID or UUID do post",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Photo deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Photo deleted successfully.")
     *         )
     *     ),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Photo not found")
     * )
     */
    public function destroy(Request $request, Posts $post)
    {
        $user = $request->user();
        if ($user->id !== $post->user_id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $paths = array_filter([
            $post->original_path,
            $post->feed_path,
            $post->thumb_path,
        ]);

        foreach ($paths as $p) {
            try {
                Storage::disk(config('filesystems.default'))->delete($p);
            } catch (\Throwable $_) {
                
            }
        }

        $post->delete();

        return response()->json(['message' => 'Post deleted successfully.']);
    }
              
}
