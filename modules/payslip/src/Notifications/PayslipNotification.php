<?php

namespace Saan\Modules\Payslip\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "A period was published" notice: the period title and a link to the work page. No amounts. */
class PayslipNotification extends Notification
{
    public function __construct(public readonly string $subject, public readonly string $periodTitle)
    {
    }

    public function via(object $notifiable): array
    {
        return trim((string) ($notifiable->email ?? '')) === '' ? [] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = \App\Support\Saan\MailUrl::to('/work').'#payslip';
        $mail = (new MailMessage)->subject($this->subject)->view(['payslip::mail', 'payslip::mail-text'],
            ['subjectLine' => $this->subject, 'name' => $notifiable->name, 'periodTitle' => $this->periodTitle, 'url' => $url, 'display' => (string) config('saan.display')]);
        $mail->actionUrl = $url;
        return $mail;
    }
}
