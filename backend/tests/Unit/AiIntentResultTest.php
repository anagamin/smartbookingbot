<?php

namespace Tests\Unit;

use App\DataTransferObjects\AiIntentResult;
use PHPUnit\Framework\TestCase;

class AiIntentResultTest extends TestCase
{
    public function test_from_json_array_maps_fields(): void
    {
        $dto = AiIntentResult::fromJsonArray([
            'intent' => 'booking_confirm',
            'confidence' => 0.9,
            'service' => 'Маникюр',
            'date' => '2026-05-10',
            'time' => '14:00',
            'reply' => 'Ок',
            'needs_owner' => false,
        ]);

        $this->assertSame('booking_confirm', $dto->intent);
        $this->assertSame(0.9, $dto->confidence);
        $this->assertSame('Маникюр', $dto->service);
        $this->assertSame('2026-05-10', $dto->date);
        $this->assertSame('14:00', $dto->time);
        $this->assertFalse($dto->needsOwner);
    }
}
