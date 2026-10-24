<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class ApprovalException extends RuntimeException
{
    public static function notPending(): self
    {
        return new self('This approval gate is no longer pending.');
    }

    public static function expired(): self
    {
        return new self('This approval gate has expired.');
    }

    public static function sameApprover(): self
    {
        return new self('Dual approval requires two distinct administrators.');
    }

    public static function systemApprover(): self
    {
        return new self('The automated system context cannot approve a human-initiated request.');
    }

    public static function rejectionReasonRequired(): self
    {
        return new self('A rejection reason is required.');
    }
}
