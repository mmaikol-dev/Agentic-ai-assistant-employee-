<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return (int) $user->id === (int) $task->user_id;
    }

    public function update(User $user, Task $task): bool
    {
        return (int) $user->id === (int) $task->user_id;
    }
}
