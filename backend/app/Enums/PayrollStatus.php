<?php

namespace App\Enums;

enum PayrollStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
}
