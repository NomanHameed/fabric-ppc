<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Shift extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];
}
