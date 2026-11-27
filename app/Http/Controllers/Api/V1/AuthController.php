<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use App\Models\Device;
use App\Support\AuditLogger;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Login', 'Login staf dan token aplikasi', weight: 0)]
class AuthController extends Controller
{
    /** Masa berlaku token aplikasi HP. Setelah itu login ulang (perlu online). */
    public const TOKEN_DAYS = 30;

    /**
     * Login staf.
     *
     * Isi `login` dengan username atau email (tidak membedakan huruf besar/kecil).
     * Field `email` lama masih diterima. Kirim `device_code` (kode HP dari menu Perangkat) agar token terikat ke HP tersebut;
     * tanpa itu token tidak bisa memakai endpoint `/sync/*`. Token berlaku 30 hari.
     * Dibatasi 5 percobaan per menit.
     *
     * @unauthenticated
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            /** Username atau email. */
            'login' => ['required_without:email', 'nullable', 'string', 'max:255'],
            /** Cara lama: email. Pakai `login` untuk aplikasi baru. */
            'email' => ['required_without:login', 'nullable', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'device_code' => ['nullable', 'string', 'max:10'],
        ], [
            'login.required_without' => 'Username atau email wajib diisi.',
            'email.required_without' => 'Username atau email wajib diisi.',
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
        $login = (string) ($credentials['login'] ?? $credentials['email']);
        $user = User::findForLogin($login);
        // Dicatat untuk owner: siapa mencoba login dari perangkat apa. Password tidak pernah dicatat.
        $failed = fn (string $reason) => AuditLogger::record(
            action: 'login_failed',
            subjectType: 'user',
            subjectId: $user?->id,
            subjectLabel: $login,
            context: array_filter(['reason' => $reason, 'device_code' => $credentials['device_code'] ?? null]),
        );

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $failed($user ? 'password_salah' : 'akun_tidak_ditemukan');

            return response()->json([
                'code' => 'KREDENSIAL_TIDAK_VALID',
                'message' => 'Username/email atau kata sandi salah.',
            ], 401);
        }

        if (! $user->is_active) {
            $failed('akun_nonaktif');

            return response()->json([
                'code' => 'AKUN_NONAKTIF',
                'message' => 'Akun tidak aktif.',
            ], 403);
        }

        $device = null;

        if (filled($credentials['device_code'] ?? null)) {
            $device = Device::query()->where('code', strtoupper($credentials['device_code']))->first();

            if (! $device || ! $device->is_active) {
                $failed($device ? 'perangkat_nonaktif' : 'perangkat_tidak_dikenal');

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

        AuditLogger::record(
            action: 'login_success',
            subjectType: 'user',
            subjectId: $user->id,
            subjectLabel: $user->username ?: $user->name,
            context: array_filter(['device_code' => $device?->code, 'device_name' => $credentials['device_name']]),
            actor: $user,
        );

        return response()->json([
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $newToken->accessToken->expires_at?->toIso8601String(),
            'device_code' => $device?->code,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Profil user yang sedang login.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    /**
     * Keluar: mencabut token yang sedang dipakai.
     */
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
