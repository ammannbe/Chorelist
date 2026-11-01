<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;

class TaskCompleted extends ChoreNotification
{
    public function __construct(
        private User $doneBy,
        private Task $task,
        private int $points,
    ) {}

    public function kind(): string
    {
        return 'completed';
    }

    protected function title(): string
    {
        return __(':task done', ['task' => $this->task->title]);
    }

    protected function body(): string
    {
        $replace = ['name' => $this->doneBy->displayName(), 'task' => $this->task->title, 'list' => $this->task->taskList?->name ?? '', 'points' => $this->points];

        return $this->task->taskList?->ranking_enabled
            ? trans_choice(':name did ":task" (:list) and got :points point.|:name did ":task" (:list) and got :points points.', $this->points, $replace)
            : __(':name did ":task" (:list).', $replace);
    }

    protected function path(): string
    {
        return "/#/list/{$this->task->task_list_id}";
    }

    protected function tag(): string
    {
        return "task-{$this->task->id}";
    }
}
