<?php

/**
 * Schema slug regression test — asserts no PascalCase schema-name strings survive in lib/.
 *
 * C3 fix regression guard: every cross-schema OR lookup must use the real kebab/lowercase
 * slug from scholiq_register.json. This test greps lib/ source files and fails if any of
 * the forbidden PascalCase variants are found in a string literal context.
 *
 * The only intentional carve-out is 'AiFeature' (slug is genuinely PascalCase per schema
 * line 502 of scholiq_register.json) — that variant is explicitly excluded from the
 * forbidden list.
 *
 * @category Test
 * @package  OCA\Scholiq\Tests\Unit
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
 */

declare(strict_types=1);

namespace OCA\Scholiq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard: no PascalCase OR schema slug strings may survive in lib/.
 */
class SchemaSlugRegressionTest extends TestCase
{

    /**
     * PascalCase schema names that must NOT appear as string literals in lib/ PHP files.
     * Each entry maps the forbidden PascalCase form to the correct kebab/lowercase slug.
     *
     * 'AiFeature' is intentionally excluded — its schema slug is genuinely PascalCase
     * (scholiq_register.json line 502: "slug": "AiFeature").
     *
     * @var array<string,string>
     */
    private const FORBIDDEN_PASCAL_CASE = [
        'Credential'       => 'credential',
        'Course'           => 'course',
        'Lesson'           => 'lesson',
        'XapiStatement'    => 'xapi-statement',
        'Enrolment'        => 'enrolment',
        'Assessment'       => 'assessment',
        'AssessmentResult' => 'assessment-result',
        'Item'             => 'item',
        'Assignment'       => 'assignment',
        'CurriculumPlan'   => 'curriculum-plan',
    ];

    /**
     * Recursively collect all .php files under a directory.
     *
     * @param string $dir Directory path to scan.
     *
     * @return string[] Absolute file paths.
     */
    private function collectPhpFiles(string $dir): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() === true && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;

    }//end collectPhpFiles()

    /**
     * Assert that none of the forbidden PascalCase schema slug strings appear in lib/ PHP files.
     *
     * The pattern matches the schema name inside single quotes (the pattern used in OR
     * findAll/find calls: 'schema' => 'PascalCase'). Specifically we search for:
     *   'PascalName'  (surrounded by single quotes) to avoid false positives on
     *   class names, comments, or PHP type annotations.
     *
     * @param string $forbidden The forbidden PascalCase schema slug.
     * @param string $correct   The correct kebab/lowercase slug to use instead.
     *
     * @return void
     *
     * @dataProvider forbiddenSchemaSlugProvider
     */
    public function testNoPascalCaseSchemaSlugInLib(string $forbidden, string $correct): void
    {
        $libDir = __DIR__.'/../../lib';
        $files  = $this->collectPhpFiles(dir: $libDir);

        $matches = [];

        foreach ($files as $filePath) {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                continue;
            }

            // Match the PascalCase name as a single-quoted PHP string literal.
            // Pattern: (?<![A-Za-z\\]) to avoid matching inside namespaces or classnames.
            $pattern = "/'".preg_quote($forbidden, '/')."'/";
            if (preg_match($pattern, $contents) === 1) {
                $matches[] = str_replace($libDir.'/', '', $filePath);
            }
        }//end foreach

        $this->assertEmpty(
            $matches,
            sprintf(
                "PascalCase schema slug '%s' found in lib/ (correct slug: '%s'). "
                ."Found in file(s): %s",
                $forbidden,
                $correct,
                implode(', ', $matches)
            )
        );

    }//end testNoPascalCaseSchemaSlugInLib()

    /**
     * Data provider: yields [forbiddenPascalCase, correctSlug] pairs.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function forbiddenSchemaSlugProvider(): array
    {
        $cases = [];
        foreach (self::FORBIDDEN_PASCAL_CASE as $pascal => $slug) {
            $cases[$pascal] = [$pascal, $slug];
        }

        return $cases;

    }//end forbiddenSchemaSlugProvider()
}//end class
