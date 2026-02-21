<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Task extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'created_from',
        'chat_message_id',
        'status',
        'priority',
        'schedule_type',
        'run_at',
        'cron_expression',
        'cron_human',
        'event_condition',
        'timezone',
        'execution_plan',
        'expected_output',
        'original_user_request',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'execution_plan' => 'array',
        'run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(TaskLog::class);
    }

    public function latestRun(): HasOne
    {
        return $this->hasOne(TaskRun::class)->latestOfMany('started_at');
    }

    public function isScheduled(): bool
    {
        return in_array($this->schedule_type, ['one_time', 'recurring', 'event_triggered'], true);
    }
}
