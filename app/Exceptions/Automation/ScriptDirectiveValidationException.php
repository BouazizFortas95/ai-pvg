<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class ScriptDirectiveValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Scene directive validation failed: '.implode(' ', $errors));
    }

    /**
     * @param  list<string>  $errors
     */
    public static function failed(array $errors): self
    {
        return new self($errors);
    }
}
