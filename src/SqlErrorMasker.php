<?php

namespace Ilham\SqlErrorMasker;

use Ilham\SqlErrorMasker\Contracts\ErrorMasker;

/**
 * SQL Error Masker
 *
 * A framework-agnostic utility to:
 * - Identify SQLSTATE/vendor codes from raw messages.
 * - Classify errors into normalized types and categories.
 * - Mask sensitive details (tables, values, UUIDs, paths).
 * - Provide boolean helpers to quickly match common error types.
 *
 * @package Ilham\SqlErrorMasker
 * @api
 * @since 1.0.0
 */
final class SqlErrorMasker implements ErrorMasker
{
    /** Log level: debug (detailed but masked). */
    public const LOG_LEVEL_DEBUG   = 'debug';
    /** Log level: info (short, safe). */
    public const LOG_LEVEL_INFO    = 'info';
    /** Log level: warning (a bit more detail). */
    public const LOG_LEVEL_WARNING = 'warning';
    /** Log level: error (minimal information). */
    public const LOG_LEVEL_ERROR   = 'error';

    /** Normalized types for IDE autocompletion. */
    public const TYPE_RESOURCE_EXISTS    = 'resource_exists';
    public const TYPE_RESOURCE_NOT_FOUND = 'resource_not_found';
    public const TYPE_DUPLICATE_DATA     = 'duplicate_data';
    public const TYPE_QUERY_ERROR        = 'query_error';
    public const TYPE_CONNECTION_ERROR   = 'connection_error';
    public const TYPE_DATABASE_ERROR     = 'database_error';

    /**
     * Map SQLSTATE/vendor codes to short descriptions.
     * @var array<string,string>
     */
    private const ERROR_CODE_MAPPINGS = [
        '42S01' => 'Resource already exists',
        '42S02' => 'Resource not found',
        '23000' => 'Data constraint violation',
        '42000' => 'Query error',
        '08S01' => 'Database connection error',
        '45000' => 'Database operation error',
        '22001' => 'Data validation error',
        '22007' => 'Invalid data format',
        '23505' => 'Duplicate data detected',
        '42703' => 'Database structure error',
        '42P01' => 'Resource not found',
        '42601' => 'Query syntax error',
        '28000' => 'Database access denied',
        '08006' => 'Connection failed',
        'HY000' => 'Database error',
        '1050'  => 'Resource already exists',
        '1051'  => 'Resource not found',
        '1054'  => 'Database structure error',
        '1062'  => 'Duplicate data detected',
        '1064'  => 'Query syntax error',
        '1146'  => 'Resource not found',
        '1452'  => 'Data relationship error'
    ];

    /**
     * Regular expressions to mask sensitive data.
     * @var array<string,string>
     */
    private const SENSITIVE_PATTERNS = [
        '/`([^`]+)`/' => '`[REDACTED]`',
        '/\'([^\']+)\'/u' => '\'[REDACTED]\'',
        '/"([^"]+)"/u' => '"[REDACTED]"',
        '/\b\d{4}-\d{2}-\d{2}\b/' => '[DATE]',
        '/\b\d{2}:\d{2}:\d{2}\b/' => '[TIME]',
        '/\b[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}\b/' => '[UUID]',
        '/\b\d{10,}\b/' => '[NUMBER]',
        '/\btrx_[a-zA-Z0-9_]+\b/' => '[TABLE]',
        '/\b[a-zA-Z0-9_]*payout[a-zA-Z0-9_]*\b/i' => '[TABLE]',
        '/\b[a-zA-Z0-9_]*vendor[a-zA-Z0-9_]*\b/i' => '[TABLE]',
        '/\b20\d{6}\b/' => '[IDENTIFIER]',
        '/Database: \w+/' => 'Database: [REDACTED]',
        '/Connection: \w+/' => 'Connection: [REDACTED]',
        '/[A-Za-z]:\\\\[^\\s]+/' => '[PATH]',
        '/\/[^\s]+\.php/' => '[PATH]',
        '/:\d+(\))?/' => ':[LINE])',
    ];

    /** Max length of processed messages to avoid log bloat. */
    private int $maxLen;

    /**
     * @param int $maxMessageLen Maximum message length to process.
     */
    public function __construct(int $maxMessageLen = 8000)
    {
        $this->maxLen = $maxMessageLen;
    }

    /**
     * Identify an error message into a normalized structure.
     *
     * @param string $message Raw error message.
     * @return array{
     *   type:string,
     *   code:?string,
     *   description:string,
     *   category:string,
     *   severity:string
     * }
     * @api
     * @since 1.0.0
     */
    public function identify(string $message): array
    {
        $m = mb_strtolower(mb_substr($message, 0, $this->maxLen));
        $info = new ErrorInfo();

        // SQLSTATE (e.g., 42S02)
        if (preg_match('/sqlstate\[([^\]]+)\]/', $m, $mm)) {
            $info->code = strtoupper($mm[1]);
        }
        // Vendor code (4 digits, e.g., 1146)
        if (preg_match('/\b(\d{4})\b/', $m, $mm2)) {
            $info->code = $mm2[1];
        }

        if ($info->code && isset(self::ERROR_CODE_MAPPINGS[$info->code])) {
            $info->description = self::ERROR_CODE_MAPPINGS[$info->code];
        }

        if (str_contains($m, 'already exists')) {
            $info->type = self::TYPE_RESOURCE_EXISTS;
            $info->category = 'schema';
            $info->severity = 'low';
        } elseif (str_contains($m, 'not found') || str_contains($m, 'unknown table')) {
            $info->type = self::TYPE_RESOURCE_NOT_FOUND;
            $info->category = 'schema';
            $info->severity = 'high';
        } elseif (str_contains($m, 'duplicate')) {
            $info->type = self::TYPE_DUPLICATE_DATA;
            $info->category = 'data';
            $info->severity = 'medium';
        } elseif (str_contains($m, 'syntax error')) {
            $info->type = self::TYPE_QUERY_ERROR;
            $info->category = 'query';
            $info->severity = 'high';
        } elseif (str_contains($m, 'connection')) {
            $info->type = self::TYPE_CONNECTION_ERROR;
            $info->category = 'connection';
            $info->severity = 'critical';
        }

        return $info->toArray();
    }

    /**
     * Produce a masked string according to the desired log level.
     *
     * @param string $message Raw error message.
     * @param string $level   One of: debug|info|warning|error.
     * @return string
     * @api
     * @since 1.0.0
     */
    public function mask(string $message, string $level = self::LOG_LEVEL_INFO): string
    {
        $msg = mb_substr($message, 0, $this->maxLen);

        if ($level === self::LOG_LEVEL_INFO) {
            $info = $this->identify($msg);
            return "Database operation failed: {$info['description']}";
        }

        if ($level === self::LOG_LEVEL_WARNING) {
            $masked = $this->maskSensitive($msg);
            if (preg_match('/SQLSTATE\[[^\]]+\]:.+/i', $masked, $mm)) {
                return $mm[0];
            }
            $info = $this->identify($msg);
            return "Database warning: {$info['description']}";
        }

        if ($level === self::LOG_LEVEL_ERROR) {
            $info = $this->identify($msg);
            $code = $info['code'] ?? 'UNKNOWN';
            return "Database error occurred - {$info['type']} ({$code})";
        }

        // debug (default)
        return $this->maskSensitive($msg);
    }

    /**
     * Process into a structured payload for logs/emitters.
     *
     * @param string               $message Raw error message.
     * @param string               $level   One of: debug|info|warning|error.
     * @param array<string,mixed>  $context Additional context.
     * @return array<string,mixed>
     * @api
     * @since 1.0.0
     */
    public function process(string $message, string $level = self::LOG_LEVEL_INFO, array $context = []): array
    {
        $info = $this->identify($message);
        $masked = $this->mask($message, $level);

        $result = [
            'level'       => $level,
            'message'     => $masked,
            'error_type'  => $info['type'],
            'error_code'  => $info['code'],
            'timestamp'   => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if ($level === self::LOG_LEVEL_DEBUG) {
            $result['debug_info'] = [
                'category' => $info['category'],
                'severity' => $info['severity'],
                'full_masked_error' => $this->maskSensitive($message),
                'context' => $context
            ];
        } elseif ($level === self::LOG_LEVEL_WARNING) {
            $result['details'] = [
                'category' => $info['category'],
                'severity' => $info['severity']
            ];
        } elseif ($level === self::LOG_LEVEL_ERROR) {
            $result['error_category'] = $info['category'];
        }

        return $result;
    }

    /**
     * Return a safe, user-facing message (for UI/API).
     *
     * @param string $message Raw error message.
     * @return string
     * @api
     * @since 1.0.0
     */
    public function userMessage(string $message): string
    {
        $info = $this->identify($message);
        $map = [
            self::TYPE_RESOURCE_EXISTS    => 'The operation could not be completed because the resource already exists.',
            self::TYPE_RESOURCE_NOT_FOUND => 'The requested resource could not be found.',
            self::TYPE_DUPLICATE_DATA     => 'This record already exists in the system.',
            self::TYPE_QUERY_ERROR        => 'There was an error processing your request. Please try again.',
            self::TYPE_CONNECTION_ERROR   => 'Unable to connect to the database. Please try again later.',
            self::TYPE_DATABASE_ERROR     => 'A database error occurred. Please contact support if the problem persists.'
        ];
        return $map[$info['type']] ?? $map[self::TYPE_DATABASE_ERROR];
    }

    /**
     * Extract the SQLSTATE/vendor code from a raw message.
     *
     * @param string|null $message
     * @return string|null Detected code (e.g., 42S02/1146) or null if not found.
     * @api
     * @since 1.1.0
     */
    public function extractCode(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }
        $m = mb_strtolower(mb_substr($message, 0, $this->maxLen));

        if (preg_match('/sqlstate\[([^\]]+)\]/', $m, $mm)) {
            return strtoupper($mm[1]);
        }
        if (preg_match('/\b(\d{4})\b/', $m, $mm2)) {
            return $mm2[1];
        }
        return null;
    }

    /**
     * Check if the message contains one of the given codes.
     *
     * @param string|array<int,string> $codes One code or a list of codes (SQLSTATE/vendor).
     * @param string $message Raw error message.
     * @return bool True if any code matches; false otherwise.
     * @api
     * @since 1.1.0
     */
    public function hasCode(string|array $codes, string $message): bool
    {
        $code = $this->extractCode($message);
        if ($code === null) {
            return false;
        }
        $list = is_array($codes) ? $codes : [$codes];
        foreach ($list as $c) {
            if (strcasecmp((string)$code, (string)$c) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the message is classified as a given type.
     * Valid types: resource_exists, resource_not_found, duplicate_data, query_error,
     * connection_error, database_error.
     *
     * @param string $type
     * @param string $message
     * @return bool
     * @api
     * @since 1.1.0
     */
    public function isType(string $type, string $message): bool
    {
        $info = $this->identify($message);
        return strcasecmp($info['type'], $type) === 0;
    }

    /** True if "resource already exists". */
    public function isResourceExists(string $message): bool
    {
        return $this->isType(self::TYPE_RESOURCE_EXISTS, $message)
            || $this->hasCode(['42S01', '1050'], $message);
    }

    /** True if "resource not found" / unknown table. */
    public function isResourceNotFound(string $message): bool
    {
        return $this->isType(self::TYPE_RESOURCE_NOT_FOUND, $message)
            || $this->hasCode(['42S02', '42P01', '1146', '1051'], $message);
    }

    /** True if duplicate/unique violation. */
    public function isDuplicateData(string $message): bool
    {
        return $this->isType(self::TYPE_DUPLICATE_DATA, $message)
            || $this->hasCode(['23505', '1062'], $message);
    }

    /** True if syntax/query error. */
    public function isQueryError(string $message): bool
    {
        return $this->isType(self::TYPE_QUERY_ERROR, $message)
            || $this->hasCode(['42000', '42601', '1064'], $message);
    }

    /** True if connection-related error. */
    public function isConnectionError(string $message): bool
    {
        return $this->isType(self::TYPE_CONNECTION_ERROR, $message)
            || $this->hasCode(['08S01', '08006'], $message);
    }

    /** True if integrity/relationship constraint violation. */
    public function isConstraintViolation(string $message): bool
    {
        // 23000 is Integrity Constraint Violation class
        return $this->hasCode(['23000', '23505', '1452'], $message);
    }

    /**
     * Mask sensitive data while keeping structure for debugging.
     *
     * @param string $message
     * @return string
     */
    private function maskSensitive(string $message): string
    {
        $masked = $message;
        foreach (self::SENSITIVE_PATTERNS as $pattern => $replacement) {
            $masked = preg_replace($pattern, $replacement, $masked);
        }
        return $masked;
    }
}
