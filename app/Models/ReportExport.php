<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'requested_by', 'branch_ids', 'from_date', 'to_date',
        'status', 'file_path', 'failure_message', 'attempts', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'branch_ids' => 'array',
            'from_date' => 'date:Y-m-d',
            'to_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
