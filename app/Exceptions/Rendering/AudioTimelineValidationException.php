<?php

namespace App\Exceptions\Rendering;

use RuntimeException;

class AudioTimelineValidationException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly array $errors = [],
    ) {
        parent::__construct('Invalid audio timeline: '.implode('; ', $errors));
    }
}
