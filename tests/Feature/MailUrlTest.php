<?php

namespace Tests\Feature;

use App\Support\Saan\MailUrl;
use Tests\TestCase;

class MailUrlTest extends TestCase
{
    public function test_mail_links_use_app_url_not_the_request_host(): void
    {
        config(['app.url' => 'https://school.example.test/saan']);
        $this->app['request']->headers->set('HOST', 'attacker.invalid');
        $this->assertSame('https://school.example.test/saan/reset-password/abc', MailUrl::to('/reset-password/abc'));
        $this->assertSame('https://school.example.test/saan/work', MailUrl::to('work'));
    }

    public function test_falls_back_to_url_helper_when_app_url_is_not_absolute(): void
    {
        config(['app.url' => '']);
        $this->assertStringEndsWith('/work', MailUrl::to('/work'));
    }
}
