<?php

namespace App\Enums;

enum CandidateStage: string
{
    case Applied = 'applied';
    case Screening = 'screening';
    case Interview = 'interview';
    case Offer = 'offer';
    case Accepted = 'accepted';
    case Rejected = 'rejected';

    // Urutan pipeline; dipakai untuk memastikan tahap hanya maju satu langkah.
    private const ORDER = [
        'applied' => 1,
        'screening' => 2,
        'interview' => 3,
        'offer' => 4,
        'accepted' => 5,
    ];

    /** Tahap akhir tidak bisa dipindah lagi. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected], true);
    }

    public function canMoveTo(self $to): bool
    {
        if ($this->isFinal() || $to === $this || $to === self::Applied) {
            return false;
        }

        // Penolakan boleh dari tahap mana pun yang masih berjalan.
        if ($to === self::Rejected) {
            return true;
        }

        return self::ORDER[$to->value] === self::ORDER[$this->value] + 1;
    }
}
