<?php

namespace Saan\Modules\Surveys\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SurveyVerifyMail extends Mailable
{
    public function __construct(public string $surveyTitle, public string $schoolName, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'ยืนยันการตอบแบบสอบถาม: '.$this->surveyTitle);
    }

    public function content(): Content
    {
        return new Content(view: 'surveys::mail.verify');
    }
}
