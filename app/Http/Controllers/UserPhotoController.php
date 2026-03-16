<?php

namespace App\Http\Controllers;

use App\Models\UserPhoto;
use Illuminate\Http\Request;

class UserPhotoController extends Controller
{
    /**
     * @OA\Post(
     *     path="/user/photos/upload",
     *     tags={"UserPhoto"},
     *     summary="Upload user photos (original, feed, thumb)",
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"original","feed","thumb","access"},
     *                 @OA\Property(property="original", type="string", format="binary", description="Original photo"),
     *                 @OA\Property(property="feed", type="string", format="binary", description="Feed photo"),
     *                 @OA\Property(property="thumb", type="string", format="binary", description="Thumbnail photo"),
     *                 @OA\Property(property="access", type="string", enum={"public","private"}),
     *                 @OA\Property(property="weight", type="number", format="float"),
     *                 @OA\Property(property="age", type="integer"),
     *                 @OA\Property(property="title", type="string"),
     *                 @OA\Property(property="description", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Photo uploaded successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="photo", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function uploadPhoto(Request $request)
    {
        $validated = $request->validate([
            'original'    => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'feed'        => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'thumb'       => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:1024',
            'access'      => 'required|string|in:public,private',
            'weight'      => 'nullable|numeric|min:0',
            'age'         => 'nullable|integer|min:0',
            'title'       => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
        ]);

        $user = $request->user();

        $originalPath = $request->file('original')->store("user_photos/{$user->id}", 'public');
        $feedPath     = $request->file('feed')->store("user_photos/{$user->id}", 'public');
        $thumbPath    = $request->file('thumb')->store("user_photos/{$user->id}", 'public');

        $photo = UserPhoto::create([
            'user_id'     => $user->id,
            'original_path' => $originalPath,
            'feed_path'     => $feedPath,
            'thumb_path'    => $thumbPath,
            'access'        => $validated['access'],
            'weight'        => $validated['weight'] ?? null,
            'age'           => $validated['age'] ?? null,
            'title'         => $validated['title'] ?? null,
            'description'   => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Photo uploaded successfully.',
            'photo'   => $photo,
        ], 201);
    }
}
