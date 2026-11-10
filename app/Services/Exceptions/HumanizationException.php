<?php

namespace App\Services\Exceptions;

use RuntimeException;

/** Messages are safe for display: never include source text, credentials or upstream bodies. */
class HumanizationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $stage = 'generation',
        public readonly array $violations = [],
        public readonly array $completedOperations = [],
        public readonly ?array $originalDetection = null,
    ) {
        parent::__construct($message);
    }
}
