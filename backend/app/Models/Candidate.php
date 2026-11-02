<?php

namespace App\Models;

use App\Enums\CandidateStage;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidate extends Model
{
    use Auditable;

    // job_opening_id, stage, converted_employee_id diisi server.
    protected $fillable = ['full_name', 'email', 'phone'];

    protected function casts(): array
    {
        return [
            'stage' => CandidateStage::class,
            'phone' => 'encrypted',
        ];
    }

    // Email dinormalisasi supaya "A@x.com" dan "a@x.com" dianggap sama oleh unique index.
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function jobOpening(): BelongsTo
    {
        return $this->belongsTo(JobOpening::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(CandidateStageHistory::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'converted_employee_id');
    }
}
