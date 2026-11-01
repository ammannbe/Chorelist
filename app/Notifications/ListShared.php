<?php

namespace App\Notifications;

use App\Models\TaskList;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Password;

class ListShared extends ChoreNotification
{
    public function __construct(
        private User $sharedBy,
        private TaskList $list,
        private bool $invited,
    ) {}

    public function kind(): string
    {
        return 'list_shared';
    }

    public function via(User $notifiable): array
    {
        // Invited users have no app yet: they get the mail in any case.
        return $this->invited ? ['mail'] : parent::via($notifiable);
    }

    protected function title(): string
    {
        return __('Task list shared');
    }

    protected function body(): string
    {
        return __(':name shared the task list ":list" with you.', [
            'name' => $this->sharedBy->displayName(),
            'list' => $this->list->name,
        ]);
    }

    protected function path(): string
    {
        return "/#/list/{$this->list->id}";
    }

    public function toMail(User $notifiable): MailMessage
    {
        if (! $this->invited) {
            return parent::toMail($notifiable);
        }

        // Invited users choose a password, which also verifies their email address.
        $url = url(route('password.reset', [
            'token' => Password::broker()->createToken($notifiable),
            'email' => $notifiable->email,
        ], false));

        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->line(__('An account has been created for you. Choose a password to get started.'))
            ->action(__('Choose password'), $url);
    }
}
