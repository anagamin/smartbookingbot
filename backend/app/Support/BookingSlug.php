<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class BookingSlug
{
    /** @var list<string> */
    public const RESERVED = [
        'api', 'app', 'admin', 'auth', 'login', 'register', 'logout', 'oauth',
        'book', 'sanctum', 'webhooks', 'static', 'assets', 'www', 'contact',
    ];

    public static function normalize(string $input): string
    {
        $slug = Str::lower(Str::transliterate(trim($input)));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        $slug = preg_replace('/-+/', '-', $slug) ?? '';

        return $slug;
    }

    public static function isValid(string $slug): bool
    {
        if ($slug === '' || mb_strlen($slug) < 3 || mb_strlen($slug) > 50) {
            return false;
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug)) {
            return false;
        }

        return ! self::isReserved($slug);
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }

    public static function generateUnique(?string $seed = null, ?int $ignoreUserId = null): string
    {
        $base = self::normalize($seed ?? '');
        if ($base === '' || ! self::isValid($base)) {
            $base = 'user';
        }

        $candidate = $base;
        $suffix = 0;
        while (self::slugTaken($candidate, $ignoreUserId)) {
            $suffix++;
            $candidate = $base.'-'.$suffix;
            if (mb_strlen($candidate) > 50) {
                $candidate = mb_substr($base, 0, 42).'-'.Str::lower(Str::random(6));
            }
        }

        return $candidate;
    }

    public static function slugTaken(string $slug, ?int $ignoreUserId = null): bool
    {
        $q = User::query()->where('booking_slug', $slug);
        if ($ignoreUserId !== null) {
            $q->where('id', '!=', $ignoreUserId);
        }

        return $q->exists();
    }
}
