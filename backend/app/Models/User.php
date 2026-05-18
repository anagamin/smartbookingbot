<?php

namespace App\Models;

use App\Support\BookingSlug;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'password',
    'sex',
    'balance_kopecks',
    'trial_ends_at',
    'next_billing_at',
    'subscription_ends_at',
    'bot_paused',
    'services_description',
    'booking_slug',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (empty($user->booking_slug)) {
                $user->booking_slug = BookingSlug::generateUnique($user->name);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
            'next_billing_at' => 'datetime',
            'subscription_ends_at' => 'datetime',
            'bot_paused' => 'boolean',
        ];
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function workingHours(): HasMany
    {
        return $this->hasMany(WorkingHour::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(Dialog::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function billingTransactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class);
    }

    public function contactMessages(): HasMany
    {
        return $this->hasMany(ContactMessage::class);
    }

    public function isInTrialPeriod(): bool
    {
        if ($this->trial_ends_at === null) {
            return false;
        }

        return now()->lt($this->trial_ends_at);
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscription_ends_at !== null
            && $this->subscription_ends_at->isFuture();
    }

    public function daysUntilSubscriptionEnds(): ?int
    {
        if ($this->subscription_ends_at === null) {
            return null;
        }

        if (! $this->hasActiveSubscription()) {
            return 0;
        }

        return max(0, (int) now()->diffInDays($this->subscription_ends_at, false));
    }

    public function extendSubscriptionByMonths(int $months): void
    {
        $base = now();
        if ($this->subscription_ends_at !== null && $this->subscription_ends_at->gt($base)) {
            $base = $this->subscription_ends_at->copy();
        }

        $newEnd = $base->copy()->addMonths($months);
        $this->subscription_ends_at = $newEnd;
        $this->next_billing_at = $newEnd;
        $this->save();
    }

    public function canRunBot(): bool
    {
        if ($this->bot_paused) {
            return false;
        }

        return $this->hasActiveSubscription();
    }

    /** @return array<string, mixed> */
    public function cabinetPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'sex' => $this->sex,
            'balance_kopecks' => $this->balance_kopecks,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'next_billing_at' => $this->next_billing_at?->toIso8601String(),
            'subscription_ends_at' => $this->subscription_ends_at?->toIso8601String(),
            'bot_paused' => $this->bot_paused,
            'subscription_active' => $this->hasActiveSubscription(),
            'trial_active' => $this->isInTrialPeriod(),
            'days_until_subscription_ends' => $this->daysUntilSubscriptionEnds(),
            'bot_responds_to_clients' => $this->canRunBot(),
            'services_description' => $this->services_description,
            'booking_slug' => $this->booking_slug,
            'booking_url_base' => config('smartbooking.public_booking_base_url'),
        ];
    }
}
