<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PermanentDeletionBlocked extends ConflictHttpException
{
    /** @param array<int, string> $blockers */
    public function __construct(string $resource, public readonly array $blockers)
    {
        parent::__construct("The {$resource} cannot be permanently deleted while it has ".implode(' and ', $blockers).'.');
    }
}
