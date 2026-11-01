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
        private ?string $acceptPath = null,
    ) {}

    public function kind(): string
    {
        return 'list_shared';
    }

    public function via(User $notifiable): array
    {
        // Invited users have no app yet, and invitations must not get lost: mail in any case.
        return $this->invited || $this->acceptPath ? array_values(array_unique(['mail', ...parent::via($notifiable)])) : parent::via($notifiable);
    }

    protected function title(): string
    {
        return $this->acceptPath ? __('Invitation to a task list') : __('Task list shared');
    }

    protected function body(): string
    {
        if ($this->acceptPath) {
            return __(':name invites you to the task list ":list".', [
                'name' => $this->sharedBy->displayName(),
                'list' => $this->list->name,
            ]);
        }

        return __(':name shared the task list ":list" with you.', [
            'name' => $this->sharedBy->displayName(),
            'list' => $this->list->name,
        ]);
    }

    protected function path(): string
    {
        return $this->acceptPath ?? "/#/list/{$this->list->id}";
    }

    public function toMail(User $notifiable): MailMessage
    {
        if ($this->acceptPath) {
            return (new MailMessage)
                ->subject($this->title())
                ->line($this->body())
                ->action(__('Show invitation'), url($this->acceptPath));
        }
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
