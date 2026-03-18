<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FirebaseAuthController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\UserPhotoController;
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

Route::middleware('auth:sanctum')->prefix('photos')->group(function () {
    // @OA\Post(path="/user/photos/upload", tags={"UserPhoto"}, summary="Upload user photos (original, feed, thumb)")
    Route::post('upload', [UserPhotoController::class, 'uploadPhoto']);
});
