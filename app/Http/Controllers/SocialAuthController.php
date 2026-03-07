<?php

namespace App\Http\Controllers;

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * @OA\Get(
     *     path="/auth/google",
     *     tags={"Auth Google"},
     *     summary="Retorna a URL de redirecionamento para login com Google",
     *     @OA\Response(
     *         response=200,
     *         description="URL do Google OAuth",
     *         @OA\JsonContent(
     *             @OA\Property(property="url", type="string", example="https://accounts.google.com/o/oauth2/auth?...")
     *         )
     *     )
     * )
     */
    public function redirectToGoogle()
    {
        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver('google');

        return response()->json([
            'url' => $driver->stateless()->redirect()->getTargetUrl(),
        ]);
    }

    /**
     * @OA\Get(
     *     path="/auth/google/callback",
     *     tags={"Auth Google"},
     *     summary="Callback do Google OAuth — retorna token de acesso",
     *     @OA\Parameter(
     *         name="code",
     *         in="query",
     *         required=true,
     *         description="Código de autorização retornado pelo Google",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login com Google realizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     )
     * )
     */
    public function handleGoogleCallback()
    {
        /** @var \Laravel\Socialite\Two\AbstractProvider $driver */
        $driver = Socialite::driver('google');

        $googleUser = $driver->stateless()->user();

        $user = User::updateOrCreate(
            ['email' => $googleUser->getEmail()],
            [
                'name'      => $googleUser->getName(),
                'google_id' => $googleUser->getId(),
            ]
        );

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }
}
