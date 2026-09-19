<?php

namespace App\Modules\OracleSync\Models;

use Illuminate\Database\Eloquent\Model;

class OracleImportRow extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'diff' => 'array'];
}
