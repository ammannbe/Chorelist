<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Reminder before the due date, on the due date, when overdue, or for an on demand task that was
 * not done for a while.
 *
 * @phpstan-type State array{start: ?string, due: ?string, status: string, last_done: ?string}
 */
class TaskReminder extends ChoreNotification
{
    /**
     * @param  'before'|'overdue'|'on_demand'  $reason
     * @param  State  $state
     */
    public function __construct(
        private Task $task,
        private string $reason,
        private array $state,
        private int $days = 0,
    ) {}

    public function kind(): string
    {
        return $this->reason === 'overdue' ? 'overdue' : 'reminder';
    }

    protected function title(): string
    {
        return $this->reason === 'overdue'
            ? __('Overdue: :task', ['task' => $this->task->title])
            : $this->task->title;
    }

    protected function body(): string
    {
        // The list may be deleted while the notification is queued.
        $list = $this->task->taskList?->name ?? '';

        return match ($this->reason) {
            'overdue' => __('Was due on :date (:list).', ['date' => $this->date($this->state['due']), 'list' => $list]),
            'on_demand' => $this->state['last_done']
                ? trans_choice('Last done :days day ago (:list).|Last done :days days ago (:list).', $this->days, ['days' => $this->days, 'list' => $list])
                : __('Not done yet (:list).', ['list' => $list]),
            default => $this->days === 0
                ? __('Due today (:list).', ['list' => $list])
                : trans_choice('Due tomorrow (:list).|Due in :days days, on :date (:list).', $this->days, ['days' => $this->days, 'date' => $this->date($this->state['due']), 'list' => $list]),
        };
    }

    protected function path(): string
    {
        return "/#/task/{$this->task->id}";
    }

    protected function tag(): string
    {
        return "task-{$this->task->id}";
    }

    private function date(?string $date): string
    {
        return $date ? Carbon::parse($date)->locale(app()->getLocale())->isoFormat('dd, D. MMMM') : '';
    }
}
