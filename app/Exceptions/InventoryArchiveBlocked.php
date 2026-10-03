<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InventoryArchiveBlocked extends ConflictHttpException
{
    /** @param array<int, string> $blockers */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('The item cannot be archived while it has '.implode(' and ', $blockers).'.');
    }
}
