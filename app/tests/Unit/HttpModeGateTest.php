<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Tls\CertificateManager;
use PHPUnit\Framework\TestCase;

/**
 * The precondition behind /api/admin/tls/http-mode: plain HTTP may only be
 * disabled once HTTPS is genuinely configured. This guard sits close to a
 * lock-out, so it gets explicit coverage.
 */
final class HttpModeGateTest extends TestCase
{
    public function testRedirectAllowedOnlyWithNonHttpModeAndValidCert(): void
    {
        $this->assertTrue(
            CertificateManager::canForceHttps('self_signed', CertificateManager::STATUS_VALID)
        );
        $this->assertTrue(
            CertificateManager::canForceHttps('custom', CertificateManager::STATUS_VALID)
        );
    }

    public function testRedirectRejectedWhenTlsModeIsHttp(): void
    {
        $this->assertFalse(
            CertificateManager::canForceHttps('http', CertificateManager::STATUS_VALID)
        );
    }

    public function testRedirectRejectedWhenCertNotValid(): void
    {
        foreach ([
            CertificateManager::STATUS_MISSING,
            CertificateManager::STATUS_INVALID,
            CertificateManager::STATUS_EXPIRED,
            CertificateManager::STATUS_NOT_YET,
            CertificateManager::STATUS_KEY_MISMATCH,
            '',
        ] as $status) {
            $this->assertFalse(
                CertificateManager::canForceHttps('self_signed', $status),
                "status '$status' must not allow forcing HTTPS",
            );
        }
    }
}
