<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Atasan = 'atasan';
    case Hr = 'hr';
    case Finance = 'finance';
    case Karyawan = 'karyawan';
}
