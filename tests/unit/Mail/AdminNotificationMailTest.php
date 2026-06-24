<?php // tests/unit/Mail/AdminNotificationMailTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Mail;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Mail\Mailer;
use PortoSender\Settings\Settings;

final class AdminNotificationMailTest extends WpUnitTestCase
{
    /** @return array{to:string,subject:string,body:string} */
    private function capture(callable $run): array
    {
        $captured = [];
        Functions\expect('wp_mail')->once()->andReturnUsing(function ($to, $subject, $body) use (&$captured) {
            $captured = compact('to', 'subject', 'body');
            return true;
        });
        $run();
        return $captured;
    }

    public function test_pii_free_by_default(): void
    {
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $this->assertTrue($mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 2, 'remaining' => 7, 'name' => null, 'email' => null,
        ])));

        $this->assertSame('admin@example.de', $c['to']);
        $this->assertStringContainsString('Standardbrief', $c['body']);
        $this->assertStringContainsString('2', $c['body']);            // count
        $this->assertStringContainsString('7', $c['body']);            // remaining
        $this->assertStringNotContainsString('Anfrage von', $c['body']);
    }

    public function test_includes_pii_when_present(): void
    {
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Großbrief', 'count' => 1, 'remaining' => 3, 'name' => 'Vera', 'email' => 'vera@example.de',
        ]));

        $this->assertStringContainsString('Anfrage von: Vera <vera@example.de>', $c['body']);
    }

    public function test_returns_wp_mail_result(): void
    {
        Functions\expect('wp_mail')->once()->andReturn(false);
        $mailer = new Mailer(new Settings());
        $this->assertFalse($mailer->sendAdminNotification('a@b.de', [
            'product_label' => 'X', 'count' => 1, 'remaining' => 0, 'name' => null, 'email' => null,
        ]));
    }
}
