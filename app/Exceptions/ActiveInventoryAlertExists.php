<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ActiveInventoryAlertExists extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('An active alert already exists for this item.');
    }
}
