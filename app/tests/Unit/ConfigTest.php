<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Config\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    /** @var list<string> env keys to reset between tests */
    private const KEYS = ['APP_ENV', 'APP_SECRET', 'COOKIE_SECURE', 'COOKIE_SAMESITE'];

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) putenv($k);
    }

    protected function tearDown(): void
    {
        foreach (self::KEYS as $k) putenv($k);
    }

    public function testDefaultsToDevWithInsecureCookies(): void
    {
        $c = new Config();
        self::assertFalse($c->isProd());
        self::assertFalse($c->cookieSecure(), 'dev must not force Secure cookies');
    }

    public function testProductionRequiresStrongSecret(): void
    {
        putenv('APP_ENV=prod');
        putenv('APP_SECRET=short');
        $this->expectException(RuntimeException::class);
        new Config();
    }

    public function testProductionWithStrongSecretIsSecure(): void
    {
        putenv('APP_ENV=prod');
        putenv('APP_SECRET=' . str_repeat('a', 32));
        $c = new Config();
        self::assertTrue($c->isProd());
        self::assertTrue($c->hasSecret());
        self::assertTrue($c->cookieSecure(), 'prod must default to Secure cookies');
    }

    public function testCookieSecureCanBeForcedOffInProd(): void
    {
        putenv('APP_ENV=prod');
        putenv('APP_SECRET=' . str_repeat('a', 32));
        putenv('COOKIE_SECURE=false');
        self::assertFalse((new Config())->cookieSecure());
    }

    public function testInvalidSameSiteFallsBackToLax(): void
    {
        putenv('COOKIE_SAMESITE=bogus');
        self::assertSame('Lax', (new Config())->cookieSameSite());
    }

    public function testSameSiteIsNormalised(): void
    {
        putenv('COOKIE_SAMESITE=strict');
        self::assertSame('Strict', (new Config())->cookieSameSite());
    }
}
