<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class HouseholdAdministrationConflict extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $errorCode, public readonly string $detail)
    {
        parent::__construct($detail);
    }
}
