<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use Auditable;

    protected $fillable = ['name', 'annual_quota', 'is_active'];

    protected function casts(): array
    {
        return [
            'annual_quota' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
