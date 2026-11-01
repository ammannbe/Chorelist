<?php

namespace App\Notifications;

use App\Models\Task;
use App\Models\User;

class TaskChanged extends ChoreNotification
{
    /**
     * @param  'created'|'updated'|'deleted'  $action
     */
    public function __construct(
        private User $actor,
        private Task $task,
        private string $action,
    ) {}

    public function kind(): string
    {
        return 'task_changed';
    }

    protected function title(): string
    {
        return [
            'created' => __('New task'),
            'updated' => __('Task changed'),
            'deleted' => __('Task deleted'),
        ][$this->action];
    }

    protected function body(): string
    {
        $replace = ['name' => $this->actor->displayName(), 'task' => $this->task->title, 'list' => $this->task->taskList?->name ?? ''];

        return [
            'created' => __(':name added ":task" to ":list".', $replace),
            'updated' => __(':name changed ":task" in ":list".', $replace),
            'deleted' => __(':name deleted ":task" from ":list".', $replace),
        ][$this->action];
    }

    protected function path(): string
    {
        return $this->action === 'deleted' ? "/#/list/{$this->task->task_list_id}" : "/#/task/{$this->task->id}";
    }

    protected function tag(): string
    {
        return "task-{$this->task->id}";
    }
}
