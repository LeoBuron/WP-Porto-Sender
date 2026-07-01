<?php // tests/unit/Mail/MailerTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Mail;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Mail\Mailer;
use PortoSender\Settings\Settings;
use PortoSender\Postage\PostageProduct;

final class MailerTest extends WpUnitTestCase
{
    public function test_delivery_email_contains_code_address_and_porto_prefix(): void
    {
        $captured = [];
        Functions\expect('wp_mail')->once()->andReturnUsing(function ($to, $subject, $body) use (&$captured) {
            $captured = compact('to', 'subject', 'body');
            return true;
        });
        $mailer = new Mailer(new Settings(['owner_address' => "Leo Buron\n12345 Musterstadt"]));
        $product = new PostageProduct('grossbrief', 'Großbrief', 'A4 flach, bis 500 g');

        $this->assertTrue($mailer->sendDelivery('v@example.de', 'Vera', 'AB12CD34', $product));
        $this->assertSame('v@example.de', $captured['to']);
        $this->assertStringContainsString('#PORTO AB12CD34', $captured['body']);
        $this->assertStringContainsString('12345 Musterstadt', $captured['body']);
        $this->assertStringContainsString('Großbrief', $captured['body']);
    }

    public function test_confirmation_email_contains_link(): void
    {
        Functions\expect('wp_mail')->once()->with('v@example.de', \Mockery::type('string'),
            \Mockery::on(fn($body) => str_contains($body, 'https://x.test/confirm?token=abc')))->andReturn(true);
        $mailer = new Mailer(new Settings());
        $this->assertTrue($mailer->sendConfirmation('v@example.de', 'Vera', 'https://x.test/confirm?token=abc'));
    }

    public function test_confirmation_uses_custom_template(): void
    {
        $mailer = new Mailer(new Settings([
            'email_confirm_subject' => 'Hallo %name%',
            'email_confirm_body' => 'Hi %name%: %confirm_url%',
        ]));
        Functions\expect('wp_mail')->once()->with(
            'v@e.de',
            'Hallo Vera',
            \Mockery::on(fn($b) => str_contains($b, 'Hi Vera: https://x/confirm'))
        )->andReturn(true);
        $this->assertTrue($mailer->sendConfirmation('v@e.de', 'Vera', 'https://x/confirm'));
    }

    public function test_blank_template_falls_back_to_default_copy(): void
    {
        // Explicitly-blank stored templates must not blank the mail — the built-in
        // default copy is used (backward compatibility for existing installs).
        $captured = [];
        Functions\expect('wp_mail')->once()->andReturnUsing(function ($to, $subject, $body) use (&$captured) {
            $captured = compact('to', 'subject', 'body');
            return true;
        });
        $mailer = new Mailer(new Settings(['email_confirm_subject' => '', 'email_confirm_body' => '']));
        $this->assertTrue($mailer->sendConfirmation('v@example.de', 'Vera', 'https://x.test/confirm'));
        $this->assertSame('Bitte bestätige deine Porto-Anfrage', $captured['subject']);
        $this->assertStringContainsString('bitte bestätige deine Anfrage', $captured['body']);
        $this->assertStringContainsString('https://x.test/confirm', $captured['body']);
    }

    public function test_delivery_custom_template_substitutes_all_placeholders(): void
    {
        $mailer = new Mailer(new Settings([
            'owner_address' => "Leo Buron\n12345 Musterstadt",
            'email_delivery_body' => '%name% | %product% | %limits% | %code% | %owner_address%',
        ]));
        $captured = [];
        Functions\expect('wp_mail')->once()->andReturnUsing(function ($to, $subject, $body) use (&$captured) {
            $captured = compact('to', 'subject', 'body');
            return true;
        });
        $product = new PostageProduct('grossbrief', 'Großbrief', 'A4 flach, bis 500 g');
        $this->assertTrue($mailer->sendDelivery('v@example.de', 'Vera', 'AB12CD34', $product));
        $this->assertStringContainsString('Vera | Großbrief | A4 flach, bis 500 g | AB12CD34 | Leo Buron', $captured['body']);
    }

    public function test_unknown_placeholder_is_left_intact(): void
    {
        $mailer = new Mailer(new Settings(['email_confirm_body' => 'Hi %name% %unknown_token%']));
        $captured = [];
        Functions\expect('wp_mail')->once()->andReturnUsing(function ($to, $subject, $body) use (&$captured) {
            $captured = compact('to', 'subject', 'body');
            return true;
        });
        $this->assertTrue($mailer->sendConfirmation('v@e.de', 'Vera', 'https://x/c'));
        $this->assertStringContainsString('Hi Vera %unknown_token%', $captured['body']);
    }
}
