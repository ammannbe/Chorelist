<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Monday: points of last week per list, and what is due this week.
 */
class WeeklySummary extends ChoreNotification
{
    /**
     * @param  list<array{list: string, ranking: list<array{name: string, points: int}>}>  $rankings
     * @param  list<array{title: string, list: string, due: string}>  $due
     */
    public function __construct(
        private array $rankings,
        private array $due,
    ) {}

    public function kind(): string
    {
        return 'weekly_summary';
    }

    protected function title(): string
    {
        return __('Your week');
    }

    protected function body(): string
    {
        return trans_choice(':count task is due this week.|:count tasks are due this week.', count($this->due), ['count' => count($this->due)]);
    }

    protected function path(): string
    {
        return '/#/';
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->line($this->body());
        foreach ($this->due as $task) {
            $mail->line("- {$task['title']} ({$task['list']}, {$task['due']})");
        }
        foreach ($this->rankings as $ranking) {
            $mail->line(__('Points last week in ":list":', ['list' => $ranking['list']]));
            foreach ($ranking['ranking'] as $index => $entry) {
                $mail->line(($index + 1).". {$entry['name']}: {$entry['points']}");
            }
        }

        return $mail->action(__('Open :app', ['app' => config('app.name')]), url($this->path()))
            ->salutation(__('Change which notifications you get in the settings of the app.'));
    }
}
