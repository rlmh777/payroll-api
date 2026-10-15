<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class LoginAccessDeniedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            ...$this->payload,
        ], $this->status);
    }
}
