<?php

namespace App\Http\Controllers\Api;

use App\Domain\Identity\AccountService;
use App\Domain\Shared\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AccountService $accounts, private readonly AuditLogger $audit) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->accounts->register($request->validated());

        return $this->authenticated($request, $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', strtolower($credentials['email']))->first();
        if (! $user || ! Auth::guard('web')->getProvider()->validateCredentials($user, ['password' => $credentials['password']])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => 'This account is suspended.']);
        }

        $this->audit->log('user.login', $user, actorId: $user->id);

        return $this->authenticated($request, $user);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        if ($token && method_exists($token, 'delete')) {
            $token->delete();
        }
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        $this->audit->log('user.logout', $user, actorId: $user->id);

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load(['profile.nativeLanguage', 'profile.interfaceLanguage', 'preferences', 'roles', 'learnerProfiles']));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($request->input('email'))]);

        // Same response whether or not the account exists (prevents account enumeration).
        return response()->json(['message' => 'If an account exists for that email, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
                $this->audit->log('user.password_reset', $user, actorId: $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }

    /** SPA requests get a session cookie; clients that pass device_name (mobile) get a bearer token. */
    private function authenticated(Request $request, User $user, int $status = 200): JsonResponse
    {
        $payload = ['user' => new UserResource($user->load(['profile.nativeLanguage', 'profile.interfaceLanguage', 'preferences', 'roles', 'learnerProfiles']))];

        if ($request->filled('device_name')) {
            $payload['token'] = $user->createToken($request->input('device_name'))->plainTextToken;
        } elseif ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        } else {
            $payload['token'] = $user->createToken('api')->plainTextToken;
        }

        return response()->json($payload, $status);
    }
}
