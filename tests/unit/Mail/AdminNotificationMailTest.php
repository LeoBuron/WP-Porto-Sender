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

    public function test_batch_lists_every_requester_in_default_body(): void
    {
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 3, 'remaining' => 4,
            'requesters' => [
                ['name' => 'Vera', 'email' => 'v@e.de'],
                ['name' => 'Bob', 'email' => 'b@e.de'],
                ['name' => 'Cara', 'email' => 'c@e.de'],
            ],
        ]));

        $this->assertStringContainsString('Anfragen:', $c['body']);
        $this->assertStringContainsString('- Vera <v@e.de>', $c['body']);
        $this->assertStringContainsString('- Bob <b@e.de>', $c['body']);
        $this->assertStringContainsString('- Cara <c@e.de>', $c['body']);
        // The single-claimant wording is not used for a batch.
        $this->assertStringNotContainsString('Anfrage von:', $c['body']);
    }

    public function test_single_requester_via_list_keeps_one_line_wording(): void
    {
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Großbrief', 'count' => 1, 'remaining' => 3,
            'requesters' => [['name' => 'Vera', 'email' => 'vera@example.de']],
        ]));
        $this->assertStringContainsString('Anfrage von: Vera <vera@example.de>', $c['body']);
    }

    public function test_custom_template_requests_placeholder_lists_batch(): void
    {
        $mailer = new Mailer(new Settings([
            'email_admin_body' => "Abrufe (%count%):\n%requests%",
        ]));
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 2, 'remaining' => 7,
            'requesters' => [
                ['name' => 'Vera', 'email' => 'v@e.de'],
                ['name' => 'Bob', 'email' => 'b@e.de'],
            ],
        ]));
        $this->assertSame("Abrufe (2):\n- Vera <v@e.de>\n- Bob <b@e.de>", $c['body']);
    }

    public function test_empty_requesters_list_stays_pii_free(): void
    {
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 2, 'remaining' => 7, 'requesters' => [],
        ]));
        $this->assertStringNotContainsString('Anfrage von', $c['body']);
        $this->assertStringNotContainsString('Anfragen:', $c['body']);
    }

    public function test_custom_template_resolves_pii_placeholders_when_present(): void
    {
        $mailer = new Mailer(new Settings([
            'email_admin_subject' => 'Abruf: %product%',
            'email_admin_body' => 'P:%product% C:%count% R:%remaining% N:%name% E:%email%',
        ]));
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 2, 'remaining' => 7, 'name' => 'Vera', 'email' => 'vera@example.de',
        ]));
        $this->assertSame('Abruf: Standardbrief', $c['subject']);
        $this->assertSame('P:Standardbrief C:2 R:7 N:Vera E:vera@example.de', $c['body']);
    }

    public function test_custom_template_pii_placeholders_empty_when_pii_off(): void
    {
        $mailer = new Mailer(new Settings([
            'email_admin_body' => 'P:%product% C:%count% R:%remaining% N:%name% E:%email%',
        ]));
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 2, 'remaining' => 7, 'name' => null, 'email' => null,
        ]));
        $this->assertSame('P:Standardbrief C:2 R:7 N: E:', $c['body']);
    }

    public function test_includes_retrieval_time_for_single_claimant(): void
    {
        Functions\when('wp_date')->alias(fn($fmt, $ts) => date($fmt, $ts));
        $ts = 1751463000;
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Großbrief', 'count' => 1, 'remaining' => 3,
            'requesters' => [['name' => 'Vera', 'email' => 'vera@example.de', 'time' => $ts]],
        ]));
        $this->assertStringContainsString('Anfrage von: Vera <vera@example.de> (' . date('d.m.Y H:i', $ts) . ')', $c['body']);
    }

    public function test_batch_lists_time_per_claimant(): void
    {
        Functions\when('wp_date')->alias(fn($fmt, $ts) => date($fmt, $ts));
        $t1 = 1751463000; $t2 = 1751466600;
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 2, 'remaining' => 7,
            'requesters' => [
                ['name' => 'Vera', 'email' => 'v@e.de', 'time' => $t1],
                ['name' => 'Bob', 'email' => 'b@e.de', 'time' => $t2],
            ],
        ]));
        $this->assertStringContainsString('- Vera <v@e.de> (' . date('d.m.Y H:i', $t1) . ')', $c['body']);
        $this->assertStringContainsString('- Bob <b@e.de> (' . date('d.m.Y H:i', $t2) . ')', $c['body']);
    }

    public function test_time_placeholder_resolves_first_claimant_time(): void
    {
        Functions\when('wp_date')->alias(fn($fmt, $ts) => date($fmt, $ts));
        $ts = 1751463000;
        $mailer = new Mailer(new Settings(['email_admin_body' => 'Zeit: %time%']));
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'X', 'count' => 1, 'remaining' => 0,
            'requesters' => [['name' => 'Vera', 'email' => 'v@e.de', 'time' => $ts]],
        ]));
        $this->assertSame('Zeit: ' . date('d.m.Y H:i', $ts), $c['body']);
    }

    public function test_claimant_name_cannot_inject_a_placeholder_token(): void
    {
        $mailer = new Mailer(new Settings());
        $c = $this->capture(fn() => $mailer->sendAdminNotification('admin@example.de', [
            'product_label' => 'Standardbrief', 'count' => 1, 'remaining' => 7,
            'requesters' => [['name' => '%count%', 'email' => 'x@e.de']],
        ]));
        // The literal name "%count%" must survive verbatim, not be re-substituted to the count.
        $this->assertStringContainsString('Anfrage von: %count% <x@e.de>', $c['body']);
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
