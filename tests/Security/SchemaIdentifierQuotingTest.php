<?php

namespace DreamFactory\Core\Database\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security: Schema DDL helpers must quote identifiers and reject the unsafe
 * `options` append that lets caller-supplied SQL ride along with CREATE TABLE.
 *
 * The April 2026 audit (df-database F-03 + F-04) flagged three sites in
 * Components/Schema.php that interpolate caller input into raw DDL without
 * quoting:
 *
 *   - createTable():  $sql .= ' ' . $addOn      // user-supplied 'options' field
 *   - dropTable():    "DROP TABLE $table"        // unquoted identifier
 *   - dropColumns():  "DROP COLUMN " . $column   // unquoted identifier
 *
 * After the fix:
 *   - dropTable() and dropColumns() route identifiers through quoteTableName()
 *     and quoteColumnName().
 *   - createTable() validates the 'options' string against an allowlist
 *     (alphanumeric, whitespace, equals, comma, single quotes for value
 *     literals) — anything else throws BadRequestException. The legitimate
 *     use is appending `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`-style fragments;
 *     stacked-statement payloads now fail validation.
 */
class SchemaIdentifierQuotingTest extends TestCase
{
    private string $sourcePath;
    private string $contents;

    protected function setUp(): void
    {
        $this->sourcePath = __DIR__ . '/../../src/Components/Schema.php';
        $this->assertFileExists($this->sourcePath);
        $this->contents = file_get_contents($this->sourcePath);
    }

    public function testDropTableQuotesIdentifier(): void
    {
        // The vulnerable pattern was: "DROP TABLE $table"
        $this->assertDoesNotMatchRegularExpression(
            '/"DROP TABLE \$table"/',
            $this->contents,
            'dropTable() must not interpolate the raw table identifier'
        );
        // Fix uses quoteTableName().
        $this->assertMatchesRegularExpression(
            '/quoteTableName\s*\(\s*\$table\s*\).*DROP\s+TABLE/s',
            $this->contents,
            'dropTable() must route identifiers through quoteTableName()'
        );
    }

    public function testDropColumnsQuotesIdentifiers(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/"DROP COLUMN " \. \$column/',
            $this->contents,
            'dropColumns() must not interpolate raw column identifiers'
        );
        $this->assertMatchesRegularExpression(
            '/DROP\s+COLUMN.*quoteColumnName\s*\(/s',
            $this->contents,
            'dropColumns() must quote each column identifier'
        );
    }

    public function testCreateTableValidatesOptionsAppend(): void
    {
        // The fix must validate the 'options' string before append.
        // We accept either a regex match against an allowlist or a call
        // to a helper named like assertSafeTableOptions / validate*.
        $hasGuard = preg_match(
            '/preg_match\s*\([^)]+,\s*\$addOn\b/',
            $this->contents
        ) === 1
        || preg_match(
            '/(self|static)::assertSafeTableOptions\s*\(/',
            $this->contents
        ) === 1;

        $this->assertTrue(
            $hasGuard,
            "createTable() must validate the 'options' string before appending. "
            . 'Without this, a stacked-statement payload like "; DROP DATABASE x;" '
            . 'rides along with the legitimate ENGINE/CHARSET fragment.'
        );
    }
}
