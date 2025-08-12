<?php

namespace Ilham\SqlErrorMasker;

/**
 * Lightweight value object to carry normalized SQL error information.
 *
 * @internal
 */
final class ErrorInfo
{
    /**
     * @param string      $type        Normalized type (e.g. resource_not_found).
     * @param string|null $code        SQLSTATE or vendor code.
     * @param string      $description Human-readable description.
     * @param string      $category    Category (schema|data|query|connection|general).
     * @param string      $severity    Severity (low|medium|high|critical).
     */
    public function __construct(
        public string $type = 'database_error',
        public ?string $code = null,
        public string $description = 'Database operation failed',
        public string $category = 'general',
        public string $severity = 'medium'
    ) {}

    /** @return array{type:string,code:?string,description:string,category:string,severity:string} */
    public function toArray(): array
    {
        return [
            'type'        => $this->type,
            'code'        => $this->code,
            'description' => $this->description,
            'category'    => $this->category,
            'severity'    => $this->severity,
        ];
    }
}
