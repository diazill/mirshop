<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ], [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi.',
            'device_name.required' => 'Nama perangkat wajib diisi.',
            'device_name.max' => 'Nama perangkat maksimal 100 karakter.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'code' => 'VALIDASI_GAGAL',
                'message' => 'Data yang dikirim tidak valid.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $credentials = $validator->validated();
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'code' => 'KREDENSIAL_TIDAK_VALID',
                'message' => 'Email atau kata sandi tidak valid.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'code' => 'AKUN_NONAKTIF',
                'message' => 'Akun tidak aktif.',
            ], 403);
        }

        return response()->json([
            'access_token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Berhasil keluar.',
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
        ];
    }
}
