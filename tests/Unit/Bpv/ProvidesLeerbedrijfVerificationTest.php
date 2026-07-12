<?php

/**
 * Scholiq ProvidesLeerbedrijfVerification interface contract tests.
 *
 * Pins the pluggable-provider seam's shape (mirroring ProvidesProctoring /
 * ProvidesPlagiarismCheck): a single `verify(string): array` method, no
 * concrete adapter shipped in the app, and the interface's declared return
 * shape (`status`/`erkenningNumber`/`expiresAt`/`raw`) as read by
 * BpvLeerbedrijfVerificationHandler.
 *
 * @category Tests
 * @package  OCA\Scholiq\Tests\Unit\Bpv
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bpv-praktijkovereenkomst/specs/bpv/spec.md#requirement-leerbedrijf-verification-is-a-pluggable-provider
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit\Bpv;

use OCA\Scholiq\Bpv\ProvidesLeerbedrijfVerification;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ProvidesLeerbedrijfVerification interface contract.
 */
class ProvidesLeerbedrijfVerificationTest extends TestCase
{

    /**
     * It is a plain interface (no parent, no default methods) — Scholiq ships the seam only.
     *
     * @return void
     */
    public function testIsAPlainInterface(): void
    {
        $reflection = new \ReflectionClass(ProvidesLeerbedrijfVerification::class);

        $this->assertTrue($reflection->isInterface());
        $this->assertSame([], $reflection->getInterfaceNames());

    }//end testIsAPlainInterface()

    /**
     * Exactly one method, `verify`, taking a single string and returning array.
     *
     * @return void
     */
    public function testDeclaresExactlyOneVerifyMethod(): void
    {
        $reflection = new \ReflectionClass(ProvidesLeerbedrijfVerification::class);
        $methods    = $reflection->getMethods();

        $this->assertCount(1, $methods);
        $this->assertSame('verify', $methods[0]->getName());

        $params = $methods[0]->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('kvkOrErkenningNumber', $params[0]->getName());
        $this->assertSame('string', (string) $params[0]->getType());

        $this->assertSame('array', (string) $methods[0]->getReturnType());

    }//end testDeclaresExactlyOneVerifyMethod()

    /**
     * A minimal conforming implementation satisfies the interface and its declared return shape
     * (status/erkenningNumber/expiresAt/raw) — proving the contract is implementable exactly as
     * documented, without needing any concrete SBB adapter shipped in this app.
     *
     * @return void
     */
    public function testConformingImplementationSatisfiesContract(): void
    {
        $fake = new class implements ProvidesLeerbedrijfVerification {
            public function verify(string $kvkOrErkenningNumber): array
            {
                return [
                    'status'          => 'verified',
                    'erkenningNumber' => 'SBB-' . $kvkOrErkenningNumber,
                    'expiresAt'       => null,
                    'raw'             => [],
                ];
            }
        };

        $this->assertInstanceOf(ProvidesLeerbedrijfVerification::class, $fake);

        $result = $fake->verify('12345678');
        $this->assertSame('verified', $result['status']);
        $this->assertSame('SBB-12345678', $result['erkenningNumber']);
        $this->assertArrayHasKey('expiresAt', $result);
        $this->assertArrayHasKey('raw', $result);

    }//end testConformingImplementationSatisfiesContract()

    /**
     * No concrete provider ships with the app — grepping lib/Bpv for `implements
     * ProvidesLeerbedrijfVerification` outside the interface file itself finds nothing. Scholiq
     * ships the seam, not an adapter (proposal.md / design.md).
     *
     * @return void
     */
    public function testNoConcreteProviderShipsInTheApp(): void
    {
        $libDir       = __DIR__ . '/../../../lib';
        $implementors = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($libDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, 'interface ProvidesLeerbedrijfVerification')) {
                // Skip the interface's own declaration file.
                continue;
            }

            if (str_contains($contents, 'implements ProvidesLeerbedrijfVerification')
                || preg_match('/implements[^{]*\bProvidesLeerbedrijfVerification\b/', $contents) === 1
            ) {
                $implementors[] = $file->getPathname();
            }
        }

        $this->assertSame([], $implementors, 'No concrete ProvidesLeerbedrijfVerification adapter should ship in lib/.');

    }//end testNoConcreteProviderShipsInTheApp()
}//end class
