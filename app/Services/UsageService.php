<?php

namespace App\Services;

use App\Models\User;
use App\Models\UsageDay;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class UsageService
{
    public function dailyLimit(User|int $user): int
    {
        $user = $user instanceof User ? $user : User::findOrFail($user);
        return max(0, (int) ($user->quota_override ?? $user->daily_word_limit ?? config('studio.daily_word_limit', 10000)));
    }

    public function summary(User|int $user): array
    {
        $user = $user instanceof User ? $user : User::findOrFail($user);
        return DB::transaction(function () use ($user) {
            $this->expireReservations($user->id);
            $date = now(config('studio.usage_timezone', 'UTC'))->toDateString();
            $day = UsageDay::where('user_id', $user->id)->whereDate('date', $date)->first();
            return $this->snapshot($day, $this->dailyLimit($user), $date);
        }, 3);
    }

    /** Reserve before dispatching a provider call. Failed work must be released. */
    public function reserve(User|int $user, int $words, string $action = 'detect', ?string $referenceId = null): array
    {
        if ($words < 1 || $words > 1000000) {
            throw new InvalidArgumentException('Word reservation must be between 1 and 1,000,000.');
        }
        $user = $user instanceof User ? $user : User::findOrFail($user);
        return DB::transaction(function () use ($user, $words, $action, $referenceId) {
            $this->expireReservations($user->id);
            $date = now(config('studio.usage_timezone', 'UTC'))->toDateString();
            $limit = $this->dailyLimit($user);
            DB::table('usage_days')->insertOrIgnore([
                'user_id' => $user->id, 'date' => $date, 'used_words' => 0,
                'reserved_words' => 0, 'word_limit' => $limit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $day = UsageDay::where('user_id', $user->id)->whereDate('date', $date)->lockForUpdate()->firstOrFail();
            $day->update(['word_limit' => $limit]);
            $changed = DB::table('usage_days')->where('id', $day->id)
                ->whereRaw('used_words + reserved_words + ? <= word_limit', [$words])
                ->increment('reserved_words', $words, ['updated_at' => now()]);
            if (!$changed) {
                return ['allowed' => false, 'reservation_id' => null] + $this->snapshot($day->fresh(), $limit, $date);
            }
            $reservation = UsageReservation::create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'usage_day_id' => $day->id,
                'words' => $words, 'action' => mb_substr($action, 0, 40),
                'reference_id' => $referenceId ? mb_substr($referenceId, 0, 100) : null,
                'status' => 'reserved', 'expires_at' => now()->addHours(2),
            ]);
            return ['allowed' => true, 'reservation_id' => $reservation->id] + $this->snapshot($day->fresh(), $limit, $date);
        }, 3);
    }

    /** Idempotent settlement; actualWords cannot exceed the reserved allowance. */
    public function settle(string $reservationId, ?int $actualWords = null, bool $success = true): array
    {
        return DB::transaction(function () use ($reservationId, $actualWords, $success) {
            $reservation = UsageReservation::whereKey($reservationId)->lockForUpdate()->firstOrFail();
            $day = UsageDay::whereKey($reservation->usage_day_id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== 'reserved') {
                return $this->snapshot($day, $day->word_limit, $day->date->toDateString());
            }
            if ($actualWords !== null && ($actualWords < 0 || $actualWords > $reservation->words)) {
                throw new InvalidArgumentException('Settled words cannot exceed the original reservation.');
            }
            $used = $success ? ($actualWords ?? $reservation->words) : 0;
            // The conditional update makes repeated/concurrent settlement a no-op.
            $changed = UsageReservation::whereKey($reservationId)->where('status', 'reserved')->update([
                'status' => $success ? 'settled' : 'released', 'settled_words' => $used, 'updated_at' => now(),
            ]);
            if ($changed) {
                DB::table('usage_days')->where('id', $day->id)->update([
                    'reserved_words' => DB::raw('reserved_words - '.(int) $reservation->words),
                    'used_words' => DB::raw('used_words + '.(int) $used), 'updated_at' => now(),
                ]);
            }
            return $this->snapshot($day->fresh(), $day->word_limit, $day->date->toDateString());
        }, 3);
    }

    public function release(string $reservationId): array
    {
        return $this->settle($reservationId, 0, false);
    }

    private function expireReservations(int $userId): void
    {
        $stale = UsageReservation::where('user_id', $userId)->where('status', 'reserved')
            ->where('expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($stale as $reservation) {
            if (UsageReservation::whereKey($reservation->id)->where('status', 'reserved')->update(['status' => 'released', 'updated_at' => now()])) {
                DB::table('usage_days')->where('id', $reservation->usage_day_id)
                    ->decrement('reserved_words', $reservation->words, ['updated_at' => now()]);
            }
        }
    }

    private function snapshot(?UsageDay $day, int $limit, string $date): array
    {
        $used = (int) ($day?->used_words ?? 0);
        $reserved = (int) ($day?->reserved_words ?? 0);
        return [
            'date' => $date, 'used_words' => $used, 'reserved_words' => $reserved,
            'used' => $used, 'reserved' => $reserved, 'limit' => $limit,
            'remaining' => max(0, $limit - $used - $reserved),
            'resets_at' => \Carbon\Carbon::parse($date, config('studio.usage_timezone', 'UTC'))->addDay()->toIso8601String(),
        ];
    }
}
