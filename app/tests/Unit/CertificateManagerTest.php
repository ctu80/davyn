<?php
declare(strict_types=1);

namespace Davyn\Tests\Unit;

use Davyn\Tls\CertificateManager;
use PHPUnit\Framework\TestCase;

final class CertificateManagerTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/davyn-cert-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->dir);
        }
    }

    public function testMissingWhenNoCert(): void
    {
        $m = new CertificateManager($this->dir);
        $i = $m->inspect();
        $this->assertSame(CertificateManager::STATUS_MISSING, $i['status']);
        $this->assertFalse($i['has_certificate']);
    }

    public function testGenerateSelfSignedProducesValidCertWithSans(): void
    {
        $m = new CertificateManager($this->dir);
        $m->generateSelfSigned('davyn.local', ['davyn.local', 'localhost'], ['127.0.0.1'], 825, 'Davyn Local');

        $i = $m->inspect();
        $this->assertSame(CertificateManager::STATUS_VALID, $i['status']);
        $this->assertSame('davyn.local', $i['subject_cn']);
        $this->assertTrue($i['self_signed']);
        $this->assertContains('davyn.local', $i['sans']);
        $this->assertContains('localhost', $i['sans']);
        $this->assertContains('127.0.0.1', $i['sans']);
        $this->assertGreaterThan(800, $i['days_remaining']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{2}(:[0-9A-F]{2})+$/', $i['fingerprint_sha256']);

        // Key must be private (0600) and the cert world-readable (0644).
        $this->assertSame('0600', substr(sprintf('%o', fileperms($m->keyPath())), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($m->certPath())), -4));
    }

    public function testCnIsAlsoAddedAsSan(): void
    {
        $m = new CertificateManager($this->dir);
        $m->generateSelfSigned('only-cn.example', [], [], 90, null);
        $this->assertContains('only-cn.example', $m->inspect()['sans']);
    }

    public function testInstallCustomAcceptsMatchingPair(): void
    {
        [$cert, $key] = $this->makePair('custom.example');
        $m = new CertificateManager($this->dir);
        $warnings = $m->installCustom($cert, $key, null, 'custom.example');
        $this->assertSame([], $warnings);
        $this->assertSame(CertificateManager::STATUS_VALID, $m->inspect()['status']);
    }

    public function testInstallCustomWarnsOnHostMismatch(): void
    {
        [$cert, $key] = $this->makePair('real-host.example');
        $m = new CertificateManager($this->dir);
        $warnings = $m->installCustom($cert, $key, null, 'different-host.example');
        $this->assertContains('host_mismatch', $warnings);
    }

    public function testInstallCustomRejectsMismatchedKeyAndKeepsExisting(): void
    {
        [$cert, $key]   = $this->makePair('keep.example');
        [, $otherKey]   = $this->makePair('other.example');

        $m = new CertificateManager($this->dir);
        $m->installCustom($cert, $key, null, null);
        $fpBefore = $m->inspect()['fingerprint_sha256'];

        $this->expectException(\InvalidArgumentException::class);
        try {
            $m->installCustom($cert, $otherKey, null, null);
        } finally {
            // The previously working cert must remain untouched.
            $this->assertSame($fpBefore, $m->inspect()['fingerprint_sha256']);
        }
    }

    public function testInstallCustomRejectsGarbagePem(): void
    {
        $m = new CertificateManager($this->dir);
        $this->expectException(\InvalidArgumentException::class);
        $m->installCustom('not a cert', 'not a key', null, null);
    }

    public function testRemoveBacksUpThenDeletes(): void
    {
        $m = new CertificateManager($this->dir);
        $m->generateSelfSigned('gone.example', ['gone.example'], [], 90, null);
        $this->assertTrue($m->certExists());

        $m->remove();
        $this->assertFalse($m->certExists());
        $this->assertSame(CertificateManager::STATUS_MISSING, $m->inspect()['status']);
        $this->assertNotEmpty(glob($m->backupDir() . '/*.crt'));
    }

    public function testKeyMismatchStatus(): void
    {
        [$cert, $key] = $this->makePair('mm.example');
        [, $otherKey] = $this->makePair('mm2.example');

        $m = new CertificateManager($this->dir);
        $m->ensureDirs();
        // Write a matching pair, then overwrite the key out-of-band with a
        // non-matching one to exercise the key_mismatch detection in inspect().
        file_put_contents($m->certPath(), $cert);
        file_put_contents($m->keyPath(), $otherKey);
        $this->assertSame(CertificateManager::STATUS_KEY_MISMATCH, $m->inspect()['status']);
    }

    /** @return array{0:string,1:string} [certPem, keyPem] */
    private function makePair(string $cn): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $cn], $key);
        $crt = openssl_csr_sign($csr, null, $key, 365);
        $certPem = '';
        $keyPem  = '';
        openssl_x509_export($crt, $certPem);
        openssl_pkey_export($key, $keyPem);
        return [$certPem, $keyPem];
    }
}
