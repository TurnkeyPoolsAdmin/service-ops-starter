<?php

namespace App\Models;

use App\Enums\PartsSupplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'address',
        'active',
        'supplier',
        'supplier_code',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'supplier' => PartsSupplier::class,
        ];
    }

    /**
     * Detach jobs before the warehouse row is deleted so the jobs.warehouse_id
     * foreign key can't block the delete (the error you'd otherwise hit deleting
     * a warehouse that has any jobs). We keep the history readable by copying the
     * warehouse name into each job's free-text warehouse_other, then null the FK
     * — so the job now reads as an "Other" supplier with the old name.
     */
    protected static function booted(): void
    {
        static::deleting(function (Warehouse $warehouse) {
            $warehouse->jobs()->get()->each(function (Job $job) use ($warehouse) {
                if (blank($job->warehouse_other)) {
                    $job->warehouse_other = $warehouse->name;
                }
                $job->warehouse_id = null;
                $job->saveQuietly();
            });
        });
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }
}
