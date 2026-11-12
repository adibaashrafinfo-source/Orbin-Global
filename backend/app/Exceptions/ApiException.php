<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain error rendered with the uniform API shape {code, message, fields, request_id}.
 * `message` is an i18n key resolved by the client (errors.<CODE>).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $fields
     * @param  array<string, mixed>  $context  extra data for the client, e.g. the existing record behind a duplicate
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 422,
        string $message = '',
        public readonly array $fields = [],
        public readonly array $context = [],
    ) {
        parent::__construct($message !== '' ? $message : 'errors.'.$errorCode);
    }

    public static function forbidden(string $code = 'FORBIDDEN'): self
    {
        return new self($code, 403);
    }

    public static function notFound(string $code = 'NOT_FOUND'): self
    {
        return new self($code, 404);
    }

    public static function conflict(string $code = 'CONFLICT'): self
    {
        return new self($code, 409);
    }
}
