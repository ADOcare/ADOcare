<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Create a new API token for a user.
     */
    private function createToken(User $user, ?DateTimeInterface $expiresAt = null): string
    {
        $expiresAt ??= now()->addDay();

        return $user->createToken('AuthToken', [], $expiresAt)->plainTextToken;
    }

    /**
     * Find user by code or login for the given identifier.
     */
    private function findUserByLoginOrCode(string $identifier): ?User
    {
        return User::query()
            ->where('code', $identifier)
            ->orWhere('login', $identifier)
            ->first();
    }

    /**
     * Register a new user account.
     */
    public function register(RegisterRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'login' => $data['login'],
            'pin' => Hash::make($data['pin']),
        ]);

        return $this->success(new UserResource($user), 'Pouzivatel bol uspesne zaregistrovany.', 201);
    }

    /**
     * Authenticate a user and return an API token.
     */
    public function login(LoginRequest $request)
    {
        $data = $request->validated();

        $user = $this->findUserByLoginOrCode($data['login']);

        if (!$user || !Hash::check($data['pin'], $user->pin)) {
            return $this->error('Nesprávne prihlasovacie meno alebo heslo.', 401);
        }

        $user->loadMissing(['branches', 'company', 'role']);

        $token = $this->createToken($user);

        return $this->success([
            'token' => $token,
            'user' => new UserResource($user),
        ], 'Prihlasenie bolo uspesne.');
    }


    /**
     * Return authenticated user profile details.
     */
    public function profile(Request $request)
    {
        $user = $request->user();
        $user?->loadMissing(['branches', 'company', 'role']);

        return $this->success(new UserResource($user), 'Profil bol uspesne nacitany.');
    }

    /**
     * Update the authenticated user's personal profile details.
     */
    public function updateProfile(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update($request->validated());
        $user = $user->fresh()->load(['branches', 'company', 'role']);

        return $this->success(new UserResource($user), 'Profil bol uspesne aktualizovany.');
    }


    /**
     * Revoke all active tokens for authenticated user.
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return $this->success(null, 'Odhlasenie bolo uspesne.');
    }
}
