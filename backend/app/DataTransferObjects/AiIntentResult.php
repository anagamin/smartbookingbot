<?php

namespace App\DataTransferObjects;

final class AiIntentResult
{
    public function __construct(
        public readonly string $intent,
        public readonly float $confidence,
        public readonly ?string $service,
        public readonly ?string $date,
        public readonly ?string $dateEnd,
        public readonly ?string $time,
        public readonly string $reply,
        public readonly bool $needsOwner,
    ) {}

    public static function fromJsonArray(array $data): self
    {
        $dateEnd = $data['date_end'] ?? $data['dateEnd'] ?? null;

        $service = null;
        foreach (['service', 'service_title', 'service_name'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key];
            if (is_string($v) && trim($v) !== '') {
                $service = trim($v);
                break;
            }
        }

        return new self(
            intent: (string) ($data['intent'] ?? 'other'),
            confidence: (float) ($data['confidence'] ?? 0),
            service: $service,
            date: isset($data['date']) ? (string) $data['date'] : null,
            dateEnd: is_string($dateEnd) && $dateEnd !== '' ? $dateEnd : null,
            time: isset($data['time']) && $data['time'] !== null ? (string) $data['time'] : null,
            reply: (string) ($data['reply'] ?? ''),
            needsOwner: (bool) ($data['needs_owner'] ?? false),
        );
    }
}
