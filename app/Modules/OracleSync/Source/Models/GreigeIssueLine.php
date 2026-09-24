<?php

namespace App\Modules\OracleSync\Source\Models;

use App\Modules\OracleSync\Source\SourceModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Greige issue line (findings.md §2.2). PC_DTL_ID is the reliable lot key; LOT_NUM alone is not unique.
 *
 * @property int $GI_MAIN_ID
 * @property int $GI_DTL_ID
 * @property int $LOT_NUM
 * @property int $PC_DTL_ID
 * @property int $QNTY
 */
class GreigeIssueLine extends SourceModel
{
    protected $table = 'GREIGE_ISSUE_D';

    // Composite (GI_MAIN_ID, GI_DTL_ID) in the mock; Oracle declares no key at all.
    protected $primaryKey = 'GI_DTL_ID';

    protected $casts = ['QNTY' => 'integer'];

    /** @return BelongsTo<GreigeIssueHeader, $this> */
    public function header(): BelongsTo
    {
        return $this->belongsTo(GreigeIssueHeader::class, 'GI_MAIN_ID', 'GI_MAIN_ID');
    }

    /** @return BelongsTo<ProcessingContractLine, $this> */
    public function contractLine(): BelongsTo
    {
        return $this->belongsTo(ProcessingContractLine::class, 'PC_DTL_ID', 'PC_DTL_ID');
    }
}
