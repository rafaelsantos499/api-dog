<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;

class FirebaseAuthController extends Controller
{
    public function __construct(private FirebaseAuth $auth) {}

    /**
     * @OA\Post(
     *     path="/auth/firebase",
     *     tags={"Auth Firebase"},
     *     summary="Login com Firebase (Google, Apple, etc.)",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"id_token"},
     *             @OA\Property(property="id_token", type="string", description="Token gerado pelo Firebase SDK no frontend")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login realizado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Token inválido")
     * )
     */
    public function login(Request $request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        try {
            $verifiedToken = $this->auth->verifyIdToken($request->id_token);
        } catch (FailedToVerifyToken) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        $firebaseUser = $verifiedToken->claims();

        $user = User::updateOrCreate(
            ['email' => $firebaseUser->get('email')],
            [
                'name'      => $firebaseUser->get('name') ?? 'Usuário',
                'google_id' => $firebaseUser->get('sub'),
            ]
        );

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }
}
