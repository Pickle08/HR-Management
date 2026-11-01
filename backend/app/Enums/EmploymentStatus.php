<?php

namespace App\Enums;

enum EmploymentStatus: string
{
    case Tetap = 'tetap';
    case Kontrak = 'kontrak';
    case Magang = 'magang';
}
