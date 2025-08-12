<?php

namespace Ilham\SqlErrorMasker\Contracts;

/**
 * Contract for SQL error masking and classification implementations.
 *
 * @api
 */
interface ErrorMasker
{
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
     */
    public function identify(string $message): array;

    /**
     * Produce a masked string according to the desired log level.
     *
     * @param string $message Raw error message.
     * @param string $level   One of: debug|info|warning|error.
     * @return string
     */
    public function mask(string $message, string $level = 'info'): string;

    /**
     * Process an error into a structured payload (for logs or emitters).
     *
     * @param string               $message Raw error message.
     * @param string               $level   One of: debug|info|warning|error.
     * @param array<string,mixed>  $context Additional context (optional).
     * @return array<string,mixed>
     */
    public function process(string $message, string $level = 'info', array $context = []): array;

    /**
     * Get a user-friendly, safe message suitable for UI/API responses.
     *
     * @param string $message Raw error message.
     * @return string
     */
    public function userMessage(string $message): string;
}
