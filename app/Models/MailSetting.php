<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class MailSetting extends Model
{
    use HasUuids;

    public const MAILERS = ['smtp', 'log'];

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    protected $table = 'mail_settings';

    protected $fillable = [
        'enabled',
        'mailer',
        'host',
        'port',
        'username',
        'password',
        'encryption',
        'from_address',
        'from_name',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'port' => 'integer',
        'password' => 'encrypted',
    ];

    protected $hidden = [
        'password',
    ];

    public static function current(): self
    {
        if (! Schema::hasTable('mail_settings')) {
            return new self(self::defaultsFromEnv());
        }

        $settings = static::query()->first();
        if ($settings) {
            return $settings;
        }

        return new self(self::defaultsFromEnv());
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFromEnv(): array
    {
        $mailer = strtolower((string) config('mail.default', 'log'));
        if (! in_array($mailer, self::MAILERS, true)) {
            $mailer = $mailer === 'array' ? 'log' : 'smtp';
        }

        $scheme = strtolower((string) config('mail.mailers.smtp.scheme', ''));
        $encryption = match ($scheme) {
            'smtps', 'ssl' => 'ssl',
            'tls' => 'tls',
            default => 'none',
        };

        return [
            'enabled' => true,
            'mailer' => $mailer,
            'host' => config('mail.mailers.smtp.host'),
            'port' => (int) config('mail.mailers.smtp.port', 587) ?: 587,
            'username' => config('mail.mailers.smtp.username'),
            'password' => config('mail.mailers.smtp.password'),
            'encryption' => $encryption,
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
        ];
    }

    public function hasSecret(): bool
    {
        if (filled($this->password)) {
            return true;
        }

        return ! $this->exists && filled(config('mail.mailers.smtp.password'));
    }

    public function smtpScheme(): ?string
    {
        return match (strtolower((string) $this->encryption)) {
            'ssl', 'smtps' => 'smtps',
            'tls' => 'tls',
            default => null,
        };
    }
}
