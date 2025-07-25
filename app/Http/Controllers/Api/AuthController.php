<?php

namespace Azuriom\Http\Controllers\Api;

use Azuriom\Http\Controllers\Controller;
use Azuriom\Http\Resources\AuthenticatedUser as AuthenticatedUserResource;
use Azuriom\Models\ActionLog;
use Azuriom\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(function (Request $request, callable $next) {
            if (! setting('auth_api', false)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Auth API is not enabled',
                ], 400);
            }

            return $next($request);
        });
    }

    /**
     * Authenticate the user and get the access token.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(Request $request)
    {
        $credentials = $this->validate($request, [
            'email' => 'required|string',
            'password' => 'required|string',
        ]);

        if (! Auth::validate($this->credentials($credentials))) {
            return response()->json([
                'status' => 'error',
                'reason' => 'invalid_credentials',
                'message' => 'Invalid credentials',
            ], 422);
        }

        /** @var \Azuriom\Models\User $user */
        $user = Auth::getLastAttempted();

        if ($user->isBanned()) {
            return response()->json([
                'status' => 'error',
                'reason' => 'user_banned',
                'message' => 'User banned',
                'ban_reason' => $user->ban->reason,
            ], 403);
        }

        if ($user->hasTwoFactorAuth()) {
            $code = $request->input('code');

            if (empty($code)) {
                return response()->json([
                    'status' => 'pending',
                    'reason' => '2fa',
                    'message' => 'Missing 2FA code',
                ], 422);
            }

            if (! $user->isValidTwoFactorCode($code)) {
                return response()->json([
                    'status' => 'error',
                    'reason' => 'invalid_2fa',
                    'message' => 'Invalid 2FA code',
                ], 422);
            }

            $user->replaceRecoveryCode($code);
        }

        if ($user->game_id === null) {
            $user->game_id = Str::uuid();
        }

        $user->update(['access_token' => Str::random(128)]);

        $user->forceFill([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ])->save();

        ActionLog::create([
            'user_id' => $user->id,
            'action' => 'users.auth.api.login',
            'target_id' => null,
            'data' => [
                'ip' => $request->ip(),
                '2fa' => $user->hasTwoFactorAuth() ? 'on' : 'off',
            ],
        ]);

        return new AuthenticatedUserResource($user);
    }

    /**
     * Get the profile of the user by his access token.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function verify(Request $request)
    {
        $this->validate($request, ['access_token' => 'required|string']);

        $user = User::firstWhere('access_token', $request->input('access_token'));

        if ($user === null) {
            return response()->json([
                'status' => 'error',
                'reason' => 'invalid_token',
                'message' => 'Invalid token',
            ], 401);
        }

        if ($user->isBanned()) {
            return response()->json([
                'status' => 'error',
                'reason' => 'user_banned',
                'message' => 'User banned',
                'ban_reason' => $user->ban->reason,

            ], 403);
        }

        $user->forceFill([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ])->save();

        ActionLog::create([
            'user_id' => $user->id,
            'action' => 'users.auth.api.verified',
            'target_id' => null,
            'data' => [
                'ip' => $request->ip(),
            ],
        ]);

        return new AuthenticatedUserResource($user);
    }

    /**
     * Invalidate the access token if it exists.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function logout(Request $request)
    {
        $this->validate($request, ['access_token' => 'required|string']);

        User::where('access_token', $request->input('access_token'))->update([
            'access_token' => null,
        ]);

        return response()->json(['status' => 'success']);
    }

    protected function credentials(array $credentials): array
    {
        $email = $credentials['email'];
        $field = Str::contains($email, '@') ? 'email' : 'name';

        return [$field => $email, ...Arr::only($credentials, 'password')];
    }
}
