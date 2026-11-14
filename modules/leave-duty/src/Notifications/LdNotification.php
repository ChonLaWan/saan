<?php

namespace Saan\Modules\LeaveDuty\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One mail notification for every leave / duty / swap event. Sent only to accounts that have an email address. */
class LdNotification extends Notification
{
    /** @param string[] $lines */
    public function __construct(public readonly string $event, public readonly string $subject, public readonly array $lines, public readonly string $hash = 'leave')
    {
    }

    public function via(object $notifiable): array
    {
        return trim((string) ($notifiable->email ?? '')) === '' ? [] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // own Thai view instead of Laravel's markdown template (which carries English footer and button-fallback text)
        $url = \App\Support\Saan\MailUrl::to('/work').'#'.$this->hash;
        $mail = (new MailMessage)->subject($this->subject)->view(['leave-duty::mail', 'leave-duty::mail-text'],
            ['subjectLine' => $this->subject, 'name' => $notifiable->name, 'lines' => $this->lines, 'url' => $url, 'display' => (string) config('saan.display')]);
        $mail->actionUrl = $url; // kept for callers that inspect the link
        return $mail;
    }
}
