<?php

use App\Models\User;
use App\Support\LoginSessionToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $expiresAt = now()->addHours(LoginSessionToken::LIFETIME_HOURS);
        $sessionAbilities = json_encode([LoginSessionToken::ABILITY]);

        $tokens = DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->get(['id', 'name', 'tokenable_id', 'tokenable_type']);

        foreach ($tokens as $token) {
            $isLogin = false;
            if ($token->tokenable_type === User::class) {
                $email = DB::table('users')->where('id', $token->tokenable_id)->value('email');
                $isLogin = is_string($email) && strcasecmp($email, (string) $token->name) === 0;
            }

            $update = ['expires_at' => $expiresAt];
            if ($isLogin) {
                $update['abilities'] = $sessionAbilities;
            }

            DB::table('personal_access_tokens')->where('id', $token->id)->update($update);
        }
    }

    public function down(): void
    {
        DB::table('personal_access_tokens')
            ->where('abilities', json_encode([LoginSessionToken::ABILITY]))
            ->update([
                'expires_at' => null,
                'abilities' => json_encode(['*']),
            ]);
    }
};
