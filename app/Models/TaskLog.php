<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLog extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'task_id',
        'run_id',
        'step',
        'status',
        'thought',
        'action',
        'observation',
        'tool_used',
        'tool_input',
        'tool_output',
        'logged_at',
    ];

    protected $casts = [
        'tool_input' => 'array',
        'tool_output' => 'array',
        'logged_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class, 'run_id');
    }
}
