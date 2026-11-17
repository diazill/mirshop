<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Masa berlaku token aplikasi HP. Setelah itu login ulang (perlu online). */
    public const TOKEN_DAYS = 30;

    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'device_code' => ['nullable', 'string', 'max:10'],
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

        $device = null;

        if (filled($credentials['device_code'] ?? null)) {
            $device = Device::query()->where('code', strtoupper($credentials['device_code']))->first();

            if (! $device || ! $device->is_active) {
                return response()->json([
                    'code' => $device ? 'PERANGKAT_NONAKTIF' : 'PERANGKAT_TIDAK_DIKENAL',
                    'message' => $device
                        ? "Perangkat {$device->code} dinonaktifkan."
                        : 'Kode perangkat belum terdaftar. Minta owner mendaftarkannya di menu Perangkat.',
                ], 403);
            }
        }

        $newToken = $user->createToken($credentials['device_name'], ['*'], now()->addDays(self::TOKEN_DAYS));

        if ($device) {
            // Token terikat ke perangkat: endpoint sinkron membaca perangkat dari token, bukan dari header.
            $newToken->accessToken->forceFill(['device_id' => $device->id])->save();
            $device->users()->syncWithoutDetaching([$user->id => ['last_login_at' => now()]]);
        }

        return response()->json([
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'device_code' => $device?->code,
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
