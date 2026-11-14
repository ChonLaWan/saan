<?php

namespace Saan\Modules\GuardianPortal\Mail;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One link mail of the student/guardian portal (e-mail verification or password reset). The link is built by the caller with MailUrl. */
class PortalLink extends Notification
{
    public function __construct(private string $subject, private string $headline, private array $lines, private string $action, private string $url) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject($this->subject)->greeting($this->headline);
        foreach ($this->lines as $l) $m->line($l);
        return $m->action($this->action, $this->url)->salutation('ส่งโดยระบบ '.config('saan.display'));
    }
}
