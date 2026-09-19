<?php

namespace App\Modules\OracleSync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OracleImport extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['unresolved_parties' => 'array', 'committed_at' => 'datetime'];

    public function rows(): HasMany
    {
        return $this->hasMany(OracleImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function recount(): void
    {
        $counts = $this->rows()->selectRaw('action, count(*) as c')->groupBy('action')->pluck('c', 'action');
        foreach (['new', 'changed', 'unchanged', 'missing', 'error'] as $a) {
            $this->{"{$a}_count"} = (int) ($counts[$a] ?? 0);
        }
        $this->unresolved_parties = $this->rows()->where('action', 'error')->where('message', 'like', 'unknown_party%')
            ->selectRaw('party_code, count(*) as c')->groupBy('party_code')->get()
            ->map(fn ($r) => [
                'code' => (int) $r->party_code,
                'name' => $this->rows()->where('party_code', $r->party_code)->value('payload')['party_name'] ?? '',
                'rows' => (int) $r->c,
            ])->values()->all();
        $this->save();
    }
}
