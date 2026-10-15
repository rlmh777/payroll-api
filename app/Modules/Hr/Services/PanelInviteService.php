<?php

namespace App\Modules\Hr\Services;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Hr\Models\HrTemplate;
use App\Modules\Hr\Models\Vacancy;
use App\Modules\Hr\Models\VacancyPanelMember;
use App\Support\ConfiguredMail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

class PanelInviteService
{
    public const TEMPLATE_KEY = 'email.panel_invite';

    public const ROLE = 'interview-panel';

    public const PERMISSION = 'score-vacancies';

    public function __construct(
        private readonly ConfiguredMail $mail,
    ) {}

    /**
     * @return array{member: VacancyPanelMember, code: string}
     */
    public function invite(Vacancy $vacancy, string $name, string $email, bool $sendEmail = true): array
    {
        $email = mb_strtolower(trim($email));
        $name = trim($name) !== '' ? trim($name) : Str::before($email, '@');

        $member = VacancyPanelMember::query()->firstOrNew([
            'vacancy_id' => $vacancy->id,
            'email' => $email,
        ]);
        $member->name = $name;
        $member->status = VacancyPanelMember::STATUS_INVITED;
        $member->user_id = $member->user_id ?: User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->value('id');

        $code = $this->issueCode($member);
        $member->save();

        if ($sendEmail) {
            $this->sendInviteEmail($vacancy, $member, $code);
        }

        return ['member' => $member->fresh() ?? $member, 'code' => $code];
    }

    public function resend(Vacancy $vacancy, VacancyPanelMember $member): string
    {
        if ($member->vacancy_id !== $vacancy->id) {
            abort(404);
        }

        $member->status = VacancyPanelMember::STATUS_INVITED;
        $code = $this->issueCode($member);
        $member->save();
        $this->sendInviteEmail($vacancy, $member, $code);

        return $code;
    }

    public function revoke(VacancyPanelMember $member): void
    {
        $member->update([
            'status' => VacancyPanelMember::STATUS_REVOKED,
            'invite_code_hash' => null,
            'invite_code_expires_at' => null,
        ]);
    }

    public function authenticate(string $email, string $code): ?User
    {
        $email = mb_strtolower(trim($email));
        $code = strtoupper(preg_replace('/\s+/', '', $code) ?? '');
        if ($email === '' || $code === '') {
            return null;
        }

        $members = VacancyPanelMember::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->whereNotNull('invite_code_hash')
            ->get();

        $matched = $members->first(function (VacancyPanelMember $member) use ($code) {
            if ($member->codeIsExpired() || ! $member->invite_code_hash) {
                return false;
            }

            return Hash::check($code, $member->invite_code_hash);
        });

        if (! $matched) {
            return null;
        }

        $user = $this->ensureUser($matched);
        $now = now();
        VacancyPanelMember::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', '!=', VacancyPanelMember::STATUS_REVOKED)
            ->update([
                'user_id' => $user->id,
                'status' => VacancyPanelMember::STATUS_ACTIVE,
                'last_login_at' => $now,
            ]);

        return $user->fresh() ?? $user;
    }

    public function ensureUser(VacancyPanelMember $member): User
    {
        $email = mb_strtolower(trim($member->email));
        $user = $member->user_id
            ? User::query()->find($member->user_id)
            : User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if (! $user) {
            $user = User::query()->create([
                'name' => $member->name ?: Str::before($email, '@'),
                'username' => $this->uniqueUsername($email),
                'email' => $email,
                'password' => Str::password(32),
                'preferences' => ['default_module' => 'hr'],
            ]);
        }

        $this->grantPanelAccess($user);
        $member->user_id = $user->id;

        return $user;
    }

    private function grantPanelAccess(User $user): void
    {
        $permission = Permission::query()->firstOrCreate([
            'name' => self::PERMISSION,
            'guard_name' => 'web',
        ]);
        if (! $user->hasPermissionTo($permission)) {
            $user->givePermissionTo($permission);
        }

        $role = Role::query()->firstOrCreate([
            'name' => self::ROLE,
            'guard_name' => 'web',
        ]);
        if (! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        if (! $user->hasRole($role)) {
            $user->assignRole($role);
        }
    }

    private function issueCode(VacancyPanelMember $member): string
    {
        $code = strtoupper(Str::password(8, letters: true, numbers: true, symbols: false, spaces: false));
        $member->invite_code_hash = Hash::make($code);
        $member->invite_code_expires_at = now()->addDays(30);
        $member->invited_at = now();

        return $code;
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::slug(Str::before($email, '@'), '_') ?: 'panel';
        $username = $base;
        $i = 1;
        while (User::query()->whereRaw('LOWER(username) = ?', [mb_strtolower($username)])->exists()) {
            $username = $base.$i;
            $i++;
        }

        return $username;
    }

    private function sendInviteEmail(Vacancy $vacancy, VacancyPanelMember $member, string $code): void
    {
        $template = HrTemplate::query()
            ->where('system_key', self::TEMPLATE_KEY)
            ->where('is_active', true)
            ->first();
        if (! $template || ! $this->mail->isReady()) {
            return;
        }

        $tokens = $this->tokens($vacancy, $member, $code);
        try {
            $this->mail->sendHtml(
                $member->email,
                $this->replaceTokens((string) $template->subject, $tokens, escape: false),
                $this->replaceTokens((string) $template->body, $tokens, escape: true),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, string>
     */
    public function tokens(Vacancy $vacancy, VacancyPanelMember $member, string $code): array
    {
        $company = Company::query()->first();
        $frontend = (string) config('app.frontend_url', 'http://localhost:9000');
        $loginUrl = $frontend.'/login?invite=1&email='.rawurlencode($member->email);

        return [
            '{{recipient_name}}' => $member->name,
            '{{vacancy_title}}' => (string) $vacancy->title,
            '{{invite_code}}' => $code,
            '{{login_url}}' => $loginUrl,
            '{{company_name}}' => (string) ($company?->legalName ?: $company?->alias ?: config('app.name')),
            '{{panel_instructions}}' => 'After signing in, open Interview panel to review candidates and record scores.',
            '{{current_date}}' => now()->toFormattedDateString(),
        ];
    }

    /**
     * @param  array<string, string>  $tokens
     */
    public function replaceTokens(string $text, array $tokens, bool $escape): string
    {
        $replacements = [];
        foreach ($tokens as $token => $value) {
            $replacements[$token] = $escape
                ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                : $value;
        }

        return strtr($text, $replacements);
    }
}
