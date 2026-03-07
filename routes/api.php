<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SocialAuthController;
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
    Route::post('/login',    [AuthController::class, 'login']);

    // Google OAuth
    Route::get('/google',          [SocialAuthController::class, 'redirectToGoogle']);
    Route::get('/google/callback', [SocialAuthController::class, 'handleGoogleCallback']);

    // Rotas protegidas
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me',      [AuthController::class, 'me']);
    });
});
