<?php

namespace App\Http\Controllers;

use App\Models\Posts;
use Illuminate\Http\Request;
use App\Services\ImageService;
use App\Services\StorageService;
use Illuminate\Http\UploadedFile;
class UserPhotoController extends Controller
{   
    /**
     * Upload a user photo.
     *
     * @OA\Post(
     *     path="/photos/upload",
     *     tags={"UserPhoto"},
     *     summary="Upload user photo",
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
     *         description="Photo uploaded successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Photo uploaded successfully."),
     *             @OA\Property(
     *                 property="photo",
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
    public function uploadPhoto(Request $request, ImageService $imageService, StorageService $storageService)
    {
        $request->validate([
            'photo'       => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'weight'      => 'nullable|numeric|min:0',
            'age'         => 'nullable|integer|min:0',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        // Obtém o usuário autenticado (rota está protegida por `auth:sanctum`)
        $user = $request->user();
        $userId = $user->id;
        $userUuid = $user->uuid; 

        // Processa e salva as imagens em três resoluções usando Intervention Image
        
        $uploaded = $request->file('photo');
        $ext = 'webp';
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

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo'   => $photo->fresh(),
        ], 201);
    }
              
}
