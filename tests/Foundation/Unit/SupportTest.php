<?php

namespace Tests\Foundation\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\Clock;
use Tests\Support\Fixtures;

final class SupportTest extends TestCase
{
    public function testSyntheticBuildersAreDeterministicAndDoNotPersist(): void
    {
        $one = Fixtures::user();
        $two = Fixtures::user();
        self::assertNotSame($one, $two);
        self::assertSame('member@example.invalid', $one->getEmail());
        self::assertSame($one->getUsername(), $two->getUsername());
        self::assertNull($one->getId());
        self::assertTrue($one->hasVerifiedEmail());
        self::assertTrue(password_verify('password', $one->getPassword()), 'Public fixture credential must be usable by the real password store');
        self::assertSame('TEST-CARD-0001', Fixtures::card($one)->getRfidSerial());
        self::assertSame('test_fixture', Fixtures::tool()->getName());
    }

    public function testFixtureKeysRejectArbitraryInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Fixtures::user('real-address@example.com');
    }

    public function testClockRestoresMutableImmutableAndTimezoneAfterFailure(): void
    {
        $mutable = Carbon::getTestNow();
        $immutable = CarbonImmutable::getTestNow();
        $timezone = date_default_timezone_get();
        try {
            Clock::during('2025-03-30T00:59:59Z', static function () {
                self::assertSame('2025-03-30T00:59:59+00:00', Carbon::now()->toIso8601String());
                self::assertSame(Carbon::now()->timestamp, CarbonImmutable::now()->timestamp);
                Clock::during('2025-10-26T01:00:00Z', static function () {
                    self::assertSame('2025-10-26T01:00:00+00:00', Carbon::now()->toIso8601String());
                });
                self::assertSame('2025-03-30T00:59:59+00:00', Carbon::now()->toIso8601String());
                throw new \RuntimeException('deliberate clock failure');
            });
            self::fail('Expected clock callback failure');
        } catch (\RuntimeException $error) {
            self::assertSame('deliberate clock failure', $error->getMessage());
        }
        self::assertSame($mutable, Carbon::getTestNow());
        self::assertSame($immutable, CarbonImmutable::getTestNow());
        self::assertSame($timezone, date_default_timezone_get());
    }
}
