<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Jenssegers\Agent\Agent;
use App\Services\LocationService;
use App\Models\UserSession;

class AuthController extends Controller
{
    private function issueTokens(User $user): array
    {
        $user->tokens()->delete();

        $accessTokenObj = $user->createToken('access_token', ['access'], now()->addMinutes((int)config('sanctum.access_token_expiration')));
        $accessToken  = $accessTokenObj->plainTextToken;
        $refreshToken = $user->createToken('refresh_token', ['refresh'], now()->addMinutes((int)config('sanctum.refresh_expiration')))->plainTextToken;

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => (int)config('sanctum.access_token_expiration') * 60,
            'user'          => $user,
            'access_token_id' => $accessTokenObj->accessToken->id ?? null,
        ];
    }

    /**
     * @OA\Post(
     *     path="/auth/register",
     *     tags={"Auth"},
     *     summary="Registrar novo usuário",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","email","password"},
     *             @OA\Property(property="name", type="string", example="Rafael Santos"),
     *             @OA\Property(property="email", type="string", format="email", example="rafael499@gmail.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password"),
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

        return response()->json($this->issueTokens($user), 201);
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
     *             @OA\Property(property="email", type="string", format="email", example="rafael499@gmail.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password")
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
    public function login(Request $request, LocationService $locationService)
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

        $agent = new Agent();
        $device = $agent->device() ?: 'Desktop';
        $platform = $agent->platform();
        $browser = $agent->browser();
        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $location = $locationService->getLocationFromIp($ip);

        // Emite o token e obtém o ID do token de acesso
        $tokens = $this->issueTokens($user);
        $accessTokenId = $tokens['access_token_id'] ?? null;


        // Busca sessão existente considerando também location (se disponível)
        $sessionQuery = UserSession::where('user_id', $user->id)
            ->where('ip', $ip)
            ->where('user_agent', $userAgent);
        if ($location !== null) {
            $sessionQuery->where('location', $location);
        }
        $existingSession = $sessionQuery->orderByDesc('created_at')->first();

        if ($existingSession) {
            // Atualiza a sessão existente com o novo token e dados
            $existingSession->update([
                'personal_access_token_id' => $accessTokenId,
                'device'     => $device,
                'platform'   => $platform,
                'browser'    => $browser,
                'location'   => $location,
                'updated_at' => now(),
            ]);
        } else {
            // Cria uma nova sessão
            UserSession::create([
                'user_id'    => $user->id,
                'personal_access_token_id' => $accessTokenId,
                'ip'         => $ip,
                'device'     => $device,
                'platform'   => $platform,
                'browser'    => $browser,
                'location'   => $location,
                'user_agent' => $userAgent,
            ]);
        }

        return response()->json($tokens);
    }

    /**
     * @OA\Post(
     *     path="/auth/refresh",
     *     tags={"Auth"},
     *     summary="Renova o access token usando o refresh token",
     *     security={{"bearerAuth":{}}},
     *     description="Gera um novo access token e refresh token. O token de acesso antigo e a sessão correspondente são removidos. Se o refresh token estiver expirado, todos os tokens e sessões relacionados são removidos.",
     *     @OA\Response(
     *         response=200,
     *         description="Novos tokens gerados",
     *         @OA\JsonContent(
     *             @OA\Property(property="access_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOi..."),
     *             @OA\Property(property="refresh_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOi..."),
     *             @OA\Property(property="token_type", type="string", example="Bearer"),
     *             @OA\Property(property="expires_in", type="integer", example=3600),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Rafael Santos"),
     *                 @OA\Property(property="email", type="string", example="rafael@email.com")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Refresh token inválido ou expirado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Refresh token expirado. Faça login novamente.")
     *         )
     *     )
     * )
     */
    public function refresh(Request $request)
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        // Verifica se o token atual é um refresh token
        if (!in_array('refresh', $currentToken->abilities)) {
            return response()->json(['message' => 'Use o refresh token para renovar o acesso.'], 401);
        }


        // Verifica se o refresh token está expirado
        if ($currentToken->expires_at && $currentToken->expires_at->isPast()) {
            // Remove apenas o token e a sessão correspondente ao refresh token atual
            $user->tokens()->where('id', $currentToken->id)->delete();
            \App\Models\UserSession::where('personal_access_token_id', $currentToken->id)->delete();
            return response()->json(['message' => 'Refresh token expirado. Faça login novamente.'], 401);
        }

        // Remove o access token e a sessão antigos relacionados ao mesmo contexto do refresh
        // Busca a sessão pelo personal_access_token_id do access token anterior (se possível)
        $userAgent = $request->userAgent();
        $ip = $request->ip();
        $oldSession = \App\Models\UserSession::where('user_id', $user->id)
            ->where('user_agent', $userAgent)
            ->where('ip', $ip)
            ->orderByDesc('created_at')
            ->first();
        if ($oldSession && $oldSession->personal_access_token_id) {
            // Remove o access token antigo
            $user->tokens()->where('id', $oldSession->personal_access_token_id)->delete();
        }

        // Emite novos tokens
        $tokens = $this->issueTokens($user);
        $accessTokenId = $tokens['access_token_id'] ?? null;

        // Recupera device info e location igual ao login
        $agent = new Agent();
        $device = $agent->device() ?: 'Desktop';
        $platform = $agent->platform();
        $browser = $agent->browser();
        $locationService = app(LocationService::class);
        $location = $locationService->getLocationFromIp($ip);

        if ($oldSession) {
            // Atualiza a sessão existente com o novo token e dados
            $oldSession->update([
                'personal_access_token_id' => $accessTokenId,
                'device'     => $device,
                'platform'   => $platform,
                'browser'    => $browser,
                'location'   => $location,
                'updated_at' => now(),
            ]);
        } else {
            // Cria nova sessão se não existir
            UserSession::create([
                'user_id'    => $user->id,
                'personal_access_token_id' => $accessTokenId,
                'ip'         => $ip,
                'device'     => $device,
                'platform'   => $platform,
                'browser'    => $browser,
                'location'   => $location,
                'user_agent' => $userAgent,
            ]);
        }

        return response()->json($tokens);
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
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {

            $currentToken->delete();
            UserSession::where('personal_access_token_id', $currentToken->id)->delete();
        }

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
        return response()->json([
            'user' => $request->user(),
        ]);
    }
}
