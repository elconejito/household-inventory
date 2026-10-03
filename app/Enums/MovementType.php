<?php

namespace App\Enums;

enum MovementType: string
{
    case Restock = 'restock';
    case Transfer = 'transfer';
    case Consumption = 'consumption';
    case Correction = 'correction';
    case Disposal = 'disposal';
}
