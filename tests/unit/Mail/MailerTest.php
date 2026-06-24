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
        $product = new PostageProduct('grossbrief', 180, 'Großbrief', 'A4 flach, bis 500 g');

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
}
