<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Maintenance\MaintenanceMode;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/davyn-mm-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/maintenance.lock');
        @rmdir($this->dir);
    }

    public function testDisabledByDefault(): void
    {
        $mm = new MaintenanceMode($this->dir);
        $this->assertFalse($mm->isEnabled());
        $this->assertSame(
            ['enabled' => false, 'reason' => null, 'enabled_at' => null],
            $mm->status(),
        );
    }

    public function testEnableThenDisableRoundTrip(): void
    {
        $mm = new MaintenanceMode($this->dir);

        $mm->enable('Restoring backup');
        $this->assertTrue($mm->isEnabled());

        $status = $mm->status();
        $this->assertTrue($status['enabled']);
        $this->assertSame('Restoring backup', $status['reason']);
        $this->assertNotNull($status['enabled_at']);

        $mm->disable();
        $this->assertFalse($mm->isEnabled());
    }

    public function testDisableIsIdempotent(): void
    {
        $mm = new MaintenanceMode($this->dir);
        $mm->disable(); // no lock file present — must not throw
        $this->assertFalse($mm->isEnabled());
    }

    public function testEmptyReasonIsAllowed(): void
    {
        $mm = new MaintenanceMode($this->dir);
        $mm->enable('');
        $this->assertTrue($mm->isEnabled());
        $this->assertSame('', $mm->status()['reason']);
    }
}
