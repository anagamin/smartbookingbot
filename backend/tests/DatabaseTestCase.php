<?php

namespace Tests;

use App\Models\Master;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Расширение pdo_sqlite нужно для тестов с БД (или настройте MySQL в phpunit.xml).');
        }

        parent::setUp();
    }

    protected function primaryMaster(User $user): Master
    {
        return $user->masters()->orderBy('sort_order')->orderBy('id')->firstOrFail();
    }
}
