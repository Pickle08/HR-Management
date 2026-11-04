<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Data referensi wilayah: hanya dibaca, diisi lewat perintah regions:import. */
class Province extends Model
{
    protected $primaryKey = 'code';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
}
