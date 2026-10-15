<?php

namespace App\Support;

use App\Mail\TemplatedMail;
use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class ConfiguredMail
{
    public function apply(?MailSetting $settings = null): void
    {
        $settings ??= MailSetting::current();
        $mailer = strtolower((string) ($settings->mailer ?: 'log'));
        if (! in_array($mailer, MailSetting::MAILERS, true)) {
            $mailer = 'log';
        }

        $fromAddress = filled($settings->from_address)
            ? (string) $settings->from_address
            : (string) config('mail.from.address');
        $fromName = filled($settings->from_name)
            ? (string) $settings->from_name
            : (string) config('mail.from.name');

        config([
            'mail.default' => $mailer,
            'mail.from.address' => $fromAddress,
            'mail.from.name' => $fromName,
        ]);

        if ($mailer === 'smtp') {
            config([
                'mail.mailers.smtp.host' => $settings->host,
                'mail.mailers.smtp.port' => (int) ($settings->port ?: 587),
                'mail.mailers.smtp.username' => $settings->username,
                'mail.mailers.smtp.password' => $settings->password,
                'mail.mailers.smtp.scheme' => $settings->smtpScheme(),
            ]);
        }

        Mail::purge();
    }

    public function isReady(?MailSetting $settings = null): bool
    {
        $settings ??= MailSetting::current();
        if (! $settings->enabled) {
            return false;
        }

        if ($settings->mailer === 'log') {
            return true;
        }

        return filled($settings->host) && filled($settings->from_address);
    }

    public function sendHtml(string $to, string $subject, string $html, ?MailSetting $settings = null): void
    {
        $this->apply($settings);
        Mail::to($to)->send(new TemplatedMail($subject, $html));
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(MailSetting $settings, string $to): array
    {
        try {
            $this->sendHtml(
                $to,
                'Test email from payroll',
                '<p>This is a test message from your payroll email settings. Outbound mail is working.</p>',
                $settings,
            );

            return [
                'ok' => true,
                'message' => 'Test email sent to '.$to.'.',
            ];
        } catch (TransportExceptionInterface|Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage() ?: 'Failed to send test email.',
            ];
        }
    }
}
