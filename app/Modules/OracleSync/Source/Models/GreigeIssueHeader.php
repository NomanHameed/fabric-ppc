<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Greige issue document header (findings.md §2.1). FINAL is the document's status, not the lot's.
 *
 * @property int $GI_MAIN_ID
 * @property int $CC_CODE
 */
class GreigeIssueHeader extends SourceModel
{
    protected $table = 'GREIGE_ISSUE_M';

    protected $primaryKey = 'GI_MAIN_ID';

    protected $casts = ['DATED' => 'datetime', 'FINAL_DATE' => 'datetime', 'ENTRY_DATE' => 'datetime'];

    /** @return HasMany<GreigeIssueLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GreigeIssueLine::class, 'GI_MAIN_ID', 'GI_MAIN_ID');
    }
}
