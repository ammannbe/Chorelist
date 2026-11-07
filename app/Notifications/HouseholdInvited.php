<?php

namespace App\Notifications;

use App\Models\Household;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Laravel\Fortify\Features;

/**
 * Sent by mail to the invited address, also when there is no account yet (registering with it
 * shows the invitation after verifying the address).
 */
class HouseholdInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private User $inviter,
        private Household $household,
        private string $acceptPath,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Invitation to a household'))
            ->line(__(':name invites you to the household ":household".', [
                'name' => $this->inviter->displayName(),
                'household' => $this->household->name,
            ]))
            ->line(__('The members see the task lists shared with the household.'))
            ->action(__('Show invitation'), url($this->acceptPath));

        return Features::enabled(Features::registration()) || (config('login.oidc.enabled') && config('login.oidc.registration'))
            ? $mail->line(__('No account yet? Register with this email address.'))
            : $mail;
    }
}
