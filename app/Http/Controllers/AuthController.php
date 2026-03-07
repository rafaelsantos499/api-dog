<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/auth/register",
     *     tags={"Auth"},
     *     summary="Registrar novo usuário",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password","password_confirmation"},
     *             @OA\Property(property="name", type="string", example="Rafael Santos"),
     *             @OA\Property(property="email", type="string", format="email", example="rafael@email.com"),
     *             @OA\Property(property="password", type="string", format="password", example="senha123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="senha123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Usuário criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Dados inválidos")
     * )
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user  = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user], 201);
    }

    /**
     * @OA\Post(
     *     path="/auth/login",
     *     tags={"Auth"},
     *     summary="Login com email e senha",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="rafael@email.com"),
     *             @OA\Property(property="password", type="string", format="password", example="senha123")
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
     *     @OA\Response(response=422, description="Credenciais inválidas")
     * )
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciais inválidas.'],
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json(['token' => $token, 'user' => $user]);
    }

    /**
     * @OA\Post(
     *     path="/auth/logout",
     *     tags={"Auth"},
     *     summary="Logout (revoga o token atual)",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Logout realizado com sucesso"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout realizado com sucesso.']);
    }

    /**
     * @OA\Get(
     *     path="/auth/me",
     *     tags={"Auth"},
     *     summary="Retorna o usuário autenticado",
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Dados do usuário autenticado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}
