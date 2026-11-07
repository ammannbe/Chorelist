<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Notification sent by push and/or mail, as the user chose for its kind in the settings
 * (PreferenceController::NOTIFICATIONS).
 */
abstract class ChoreNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function kind(): string;

    abstract protected function title(): string;

    abstract protected function body(): string;

    /** Path in the app, e.g. "/#/task/<id>". */
    abstract protected function path(): string;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        $channels = [];
        foreach ($notifiable->channelsFor($this->kind()) as $channel) {
            if ($channel === 'mail') {
                $channels[] = 'mail';
            } elseif ($channel === 'push' && $notifiable->pushSubscriptions()->exists()) {
                $channels[] = WebPushChannel::class;
            }
        }

        return $channels;
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->action(__('Open :app', ['app' => config('app.name')]), url($this->path()))
            ->salutation(__('Change which notifications you get in the settings of the app.'));
    }

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/web-app-manifest-192x192.png')
            ->badge('/favicon-96x96.png')
            ->tag($this->tag())
            ->data(['url' => $this->path()]);
    }

    /** Push notifications with the same tag replace each other. */
    protected function tag(): string
    {
        return $this->kind();
    }
}
