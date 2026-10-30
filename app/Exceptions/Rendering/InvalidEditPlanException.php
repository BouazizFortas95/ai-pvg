<?php

namespace App\Exceptions\Rendering;

class InvalidEditPlanException extends HyperFramesException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors = [])
    {
        parent::__construct('Invalid edit plan: '.implode('; ', $errors), 'plan');
    }
}
