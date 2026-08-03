<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail: one row per uploaded order-history CSV / credit-memo PDF. */
class VendorImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines_parsed' => 'integer',
            'lines_created' => 'integer',
            'lines_updated' => 'integer',
            'documents_touched' => 'integer',
            'lines_needing_confirmation_date' => 'integer',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by');
    }
}
