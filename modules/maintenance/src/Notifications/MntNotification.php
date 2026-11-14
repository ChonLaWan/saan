<?php

namespace Saan\Modules\Maintenance\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Mail to the reporter when a repair request is finished or declined. Sent only to accounts that have an email address. */
class MntNotification extends Notification
{
    /** @param string[] $lines */
    public function __construct(public readonly string $event, public readonly string $subject, public readonly array $lines, public readonly string $hash = 'repairs')
    {
    }

    public function via(object $notifiable): array
    {
        return trim((string) ($notifiable->email ?? '')) === '' ? [] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = \App\Support\Saan\MailUrl::to('/work').'#'.$this->hash;
        $mail = (new MailMessage)->subject($this->subject)->view(['maintenance::mail', 'maintenance::mail-text'],
            ['subjectLine' => $this->subject, 'name' => $notifiable->name, 'lines' => $this->lines, 'url' => $url, 'display' => (string) config('saan.display')]);
        $mail->actionUrl = $url;
        return $mail;
    }
}
