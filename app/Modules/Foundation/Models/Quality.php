<?php

namespace App\Modules\Foundation\Models;

use App\Modules\Foundation\Support\QualityParser;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Quality extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'oracle_code' => 'integer'];

    public static function fromOracle(int $code, string $description): self
    {
        $p = QualityParser::parse($description);

        return static::query()->updateOrCreate(['oracle_code' => $code], [
            'description' => trim($description),
            'ends' => $p->ends, 'picks' => $p->picks,
            'warp_count' => $p->warpCount, 'weft_count' => $p->weftCount,
            'composition' => $p->composition, 'loom' => $p->loom, 'notes' => $p->notes,
        ]);
    }
}
