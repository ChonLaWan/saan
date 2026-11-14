<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordLinkNotification extends Notification
{
    public function __construct(private string $token, private string $purpose) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $invite = $this->purpose === 'invite';
        $url = \App\Support\Saan\MailUrl::to('/reset-password/'.$this->token).'?'.http_build_query(['email' => $notifiable->email]);
        $school = app(\App\Domain\CmsPublishing::class)->theme((int) config('school.school_id'))['published']['school_name'] ?? config('saan.display');
        return (new MailMessage)
            ->subject(($invite ? 'เชิญใช้งานระบบ' : 'ตั้งรหัสผ่านใหม่').' — '.$school)
            ->greeting('สวัสดี '.$notifiable->name)
            ->line($invite ? 'ผู้ดูแลระบบของ '.$school.' สร้างบัญชีให้คุณแล้ว กดปุ่มด้านล่างเพื่อตั้งรหัสผ่านของคุณเอง' : 'มีคำขอตั้งรหัสผ่านใหม่สำหรับบัญชีของคุณ')
            ->action($invite ? 'ตั้งรหัสผ่านและเริ่มใช้งาน' : 'ตั้งรหัสผ่านใหม่', $url)
            ->line($invite ? 'ลิงก์นี้ใช้ได้ 72 ชั่วโมงและใช้ได้ครั้งเดียว' : 'ลิงก์นี้ใช้ได้ 60 นาทีและใช้ได้ครั้งเดียว ถ้าคุณไม่ได้ขอ ไม่ต้องทำอะไร รหัสผ่านเดิมยังใช้ได้')
            ->salutation('ส่งโดยระบบ '.config('saan.display'));
    }
}
