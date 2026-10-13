<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MotdService
{
    public const REASON_LOGIN = 'login';

    public const REASON_DAILY = 'daily';

    /**
     * @return array{id: string, text: string}|null
     */
    public function claim(User $user, string $reason, ?string $localDate = null): ?array
    {
        $reason = strtolower(trim($reason));
        if (! in_array($reason, [self::REASON_LOGIN, self::REASON_DAILY], true)) {
            $reason = self::REASON_DAILY;
        }

        $today = $this->resolveLocalDate($localDate);

        return DB::transaction(function () use ($user, $reason, $today) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $preferences = is_array($locked->preferences) ? $locked->preferences : [];
            $state = is_array($preferences['motd'] ?? null) ? $preferences['motd'] : [];
            $shownIds = $this->normalizeShownIds($state['shown_ids'] ?? []);
            $lastShownOn = is_string($state['last_shown_on'] ?? null) ? $state['last_shown_on'] : null;

            if ($reason === self::REASON_DAILY && $lastShownOn === $today) {
                return null;
            }

            $picked = $this->pickMessage($shownIds);
            if ($picked === null) {
                return null;
            }

            $shownIds[] = $picked['id'];
            $limit = max(1, (int) config('motd.history_limit', 120));
            if (count($shownIds) > $limit) {
                $shownIds = array_slice($shownIds, -$limit);
            }

            $preferences['motd'] = [
                'shown_ids' => array_values($shownIds),
                'last_shown_on' => $today,
            ];
            $locked->preferences = $preferences;
            $locked->save();

            return $picked;
        });
    }

    /**
     * @param  list<string>  $shownIds
     * @return array{id: string, text: string}|null
     */
    private function pickMessage(array $shownIds): ?array
    {
        $messages = $this->messages();
        if ($messages === []) {
            return null;
        }

        $recent = array_slice($shownIds, -max(1, (int) config('motd.history_limit', 120)));
        $available = array_diff_key($messages, array_flip($recent));
        if ($available === []) {
            $keepRecent = array_slice($shownIds, -30);
            $available = array_diff_key($messages, array_flip($keepRecent));
        }
        if ($available === []) {
            $available = $messages;
        }

        $id = array_rand($available);

        return [
            'id' => (string) $id,
            'text' => (string) $available[$id],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        $raw = config('motd.messages', []);
        if (! is_array($raw)) {
            return [];
        }

        $messages = [];
        foreach ($raw as $id => $text) {
            if (! is_string($id) || ! is_string($text) || trim($text) === '') {
                continue;
            }
            $messages[$id] = trim($text);
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function normalizeShownIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function resolveLocalDate(?string $localDate): string
    {
        $serverToday = Carbon::now()->toDateString();
        if (! is_string($localDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
            return $serverToday;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $localDate);
        } catch (\Throwable) {
            return $serverToday;
        }

        if (abs($parsed->diffInDays(Carbon::now()->startOfDay())) > 1) {
            return $serverToday;
        }

        return $parsed->toDateString();
    }
}
