<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Ilham\SqlErrorMasker\SqlErrorMasker;

final class SqlErrorMaskerTest extends TestCase
{
    private SqlErrorMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new SqlErrorMasker();
    }

    public function testIdentifyParsesSqlStateAndMapsDescription(): void
    {
        $msg = "SQLSTATE[42S01]: Base table or view already exists: 1050 Table `trx_payout_sum_vendor_issue` already exists";
        $info = $this->masker->identify($msg);

        $this->assertSame(SqlErrorMasker::TYPE_RESOURCE_EXISTS, $info['type']);
        $this->assertTrue(in_array($info['code'], ['42S01', '1050'], true));
        $this->assertSame('schema', $info['category']);
        $this->assertSame('low', $info['severity']);
        $this->assertNotEmpty($info['description']);
    }

    public function testMaskInfoReturnsGenericMessage(): void
    {
        $msg = "SQLSTATE[42000]: Syntax error or access violation: 1064 Something bad near 'SELECT'";
        $masked = $this->masker->mask($msg, SqlErrorMasker::LOG_LEVEL_INFO);

        $this->assertStringContainsString('Database operation failed:', $masked);
        $this->assertStringContainsString('Query error', $masked);
    }

    public function testMaskWarningKeepsSqlstateButRedactsSensitive(): void
    {
        $msg = "SQLSTATE[42S02]: Base table or view not found: 1146 Table `vendor_users` doesn't exist";
        $masked = $this->masker->mask($msg, SqlErrorMasker::LOG_LEVEL_WARNING);

        $this->assertStringStartsWith('SQLSTATE[42S02]:', $masked);
        $this->assertStringNotContainsString('vendor_users', $masked);
        $this->assertStringContainsString('[REDACTED]', $masked);
    }

    public function testMaskDebugRedactsValuesAndPaths(): void
    {
        $msg = "Duplicate entry 'abc-123' for key `users_email_unique` in /var/www/app/User.php:123";
        $masked = $this->masker->mask($msg, SqlErrorMasker::LOG_LEVEL_DEBUG);

        $this->assertStringNotContainsString('abc-123', $masked);
        $this->assertStringContainsString('[REDACTED]', $masked);
        $this->assertStringContainsString('[PATH]', $masked);
        $this->assertStringContainsString(':[LINE])', $masked);
    }

    public function testUserMessageReturnsFriendlyText(): void
    {
        $msg = "SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (Connection)";
        $text = $this->masker->userMessage($msg);

        $this->assertIsString($text);
        $this->assertStringContainsString('Unable to connect', $text);
    }

    public function testProcessReturnsStructuredArray(): void
    {
        $msg = "SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint";
        $result = $this->masker->process($msg, SqlErrorMasker::LOG_LEVEL_ERROR);

        $this->assertSame('error', $result['level']);
        $this->assertSame(SqlErrorMasker::TYPE_DUPLICATE_DATA, $result['error_type']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    public function testBooleanChecksWork(): void
    {
        $dup = "SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint";
        $this->assertTrue($this->masker->isDuplicateData($dup));
        $this->assertTrue($this->masker->hasCode(['23505', '1062'], $dup));

        $nf = "SQLSTATE[42S02]: Base table or view not found: 1146 Table `users` doesn't exist";
        $this->assertTrue($this->masker->isResourceNotFound($nf));
        $this->assertTrue($this->masker->hasCode(['42S02', '1146'], $nf));

        $conn = "SQLSTATE[08006]: connection failure";
        $this->assertTrue($this->masker->isConnectionError($conn));

        $syntax = "SQLSTATE[42000]: Syntax error or access violation: 1064";
        $this->assertTrue($this->masker->isQueryError($syntax));

        $exists = "SQLSTATE[42S01]: Base table or view already exists: 1050";
        $this->assertTrue($this->masker->isResourceExists($exists));

        $constraint = "SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row";
        $this->assertTrue($this->masker->isConstraintViolation($constraint));
    }
}
