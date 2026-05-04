<?php

namespace Tests;

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
}
