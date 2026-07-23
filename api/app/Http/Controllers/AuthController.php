<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Audit\AuthEventType;
use App\Services\Identity\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuditService $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $result = $this->auth->login(
            $validated['email'],
            $validated['password'],
            (bool) ($validated['remember'] ?? false),
            $request->ip(),
        );

        return response()->json([
            'token' => $result['token'],
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'role' => $result['user']->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request->user());

        return response()->json(['status' => 'ok']);
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        $this->auth->unlock($request->user(), User::query()->findOrFail($id));

        return response()->json(['status' => 'ok']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink(['email' => $validated['email']]);
        $user = User::query()->where('email', $validated['email'])->first();
        if ($user !== null) {
            $this->audit->record(
                'user', (string) $user->id, AuthEventType::ResetRequested->value, actor: $user,
            );
        }

        // Identical response whether or not the account exists
        return response()->json(['status' => 'ok', 'detail' => $status === Password::RESET_LINK_SENT ? 'sent' : 'processed']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12'],
        ]);

        $status = Password::reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill(['password' => Hash::make($password)])->save();
                // Reset invalidates all active sessions (2.11)
                $user->tokens()->delete();
                $this->audit->record(
                    'user', (string) $user->id, AuthEventType::ResetCompleted->value,
                    reason: 'password reset — all sessions invalidated', actor: $user,
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => [__($status)]]);
        }

        return response()->json(['status' => 'ok']);
    }
}
