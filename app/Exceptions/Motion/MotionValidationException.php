<?php

namespace App\Exceptions\Motion;

use RuntimeException;

class MotionValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(protected array $errors, string $message = '')
    {
        $joined = implode('; ', $errors);
        parent::__construct($message ?: "Motion component validation failed: {$joined}");
    }

    /**
     * @return list<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}

