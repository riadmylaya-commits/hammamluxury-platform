<?php

namespace Tests\Engine;

use Tests\TestCase;

class SendAlertTest extends TestCase
{
    public function test_alert_is_mailed_to_configured_address(): void
    {
        config(['mail.default' => 'array', 'hl.alert_email' => 'ops@example.test']);

        $this->artisan('hl:alert', ['subject' => 'Sauvegarde manquante', 'body' => 'détail'])->assertSuccessful();

        $messages = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages->first()->getOriginalMessage();
        $this->assertSame('ops@example.test', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Sauvegarde manquante', $mail->getSubject());
        $this->assertStringContainsString('détail', $mail->getTextBody());
    }
}
