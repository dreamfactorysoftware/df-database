<?php

namespace DreamFactory\Core\Database\Tests\Security;

use DreamFactory\Core\Database\Resources\BaseDbTableResource;
use DreamFactory\Core\Exceptions\BadRequestException;
use PHPUnit\Framework\TestCase;

/**
 * Security: relationship-handling code in BaseDbTableResource interpolates
 * primary-key values directly into ApiOptions::FILTER strings. Until those
 * filters are parameterized end-to-end, the package must validate PK values
 * before interpolation.
 *
 * The April 2026 audit (df-database F-02) found 6 inconsistent sites:
 *
 *     "$pkFieldAlias = $filterVal"               // line ~2365
 *     $refFieldAlias . ' = ' . $parent_id        // line ~2460
 *     $pkFieldAlias . ' = ' . $id                // line ~2512
 *     $pkFieldAlias . ' IN (' . implode(',', $checkIds) . ')'   // line ~2641
 *
 * Some wrap strings in single quotes, others don't, none escape interior
 * single quotes. After the fix, BaseDbTableResource exposes a static helper
 * `formatPkFilterValue()` that validates+quotes a PK value safely, and the
 * 6 call sites delegate to it.
 */
class RelationshipFilterPkValidationTest extends TestCase
{
    /**
     * @dataProvider validPkProvider
     */
    public function testFormatPkAcceptsLegitimateIds($input, string $expected): void
    {
        $this->assertSame(
            $expected,
            BaseDbTableResource::formatPkFilterValue($input)
        );
    }

    public static function validPkProvider(): array
    {
        return [
            'positive int'   => [42, '42'],
            'zero'           => [0, '0'],
            'negative int'   => [-7, '-7'],
            'float'          => [3.14, '3.14'],
            'numeric string' => ['1234', "'1234'"],
            'uuid-like'      => ['550e8400-e29b-41d4-a716-446655440000', "'550e8400-e29b-41d4-a716-446655440000'"],
            'slug'           => ['user_42', "'user_42'"],
            'alphanumeric'   => ['abc123', "'abc123'"],
        ];
    }

    /**
     * @dataProvider injectionPkProvider
     */
    public function testFormatPkRejectsInjectionPayloads($input): void
    {
        $this->expectException(BadRequestException::class);
        BaseDbTableResource::formatPkFilterValue($input);
    }

    public static function injectionPkProvider(): array
    {
        return [
            'single quote'           => ["1' OR '1'='1"],
            'closing paren OR'       => ["1) OR (1=1"],
            'comment terminator'     => ["1 -- "],
            'stacked statement'      => ["1; DROP TABLE users"],
            'union select'           => ["1 UNION SELECT password FROM user"],
            'whitespace AND'         => ['1 AND 1=1'],
            'newline'                => ["1\n2"],
            'null byte'              => ["1\x00"],
            'array'                  => [['evil']],
            'object'                 => [(object) ['x' => 1]],
        ];
    }
}
