<?php // tests/unit/Mail/EmailDefaultsTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Mail;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Mail\EmailDefaults;
use PortoSender\Settings\Settings;

final class EmailDefaultsTest extends WpUnitTestCase
{
    public function test_every_email_key_has_a_nonempty_default(): void
    {
        foreach (Settings::EMAIL_KEYS as $key) {
            $this->assertNotSame('', EmailDefaults::get($key), "missing default for $key");
        }
    }

    public function test_no_stray_defaults_outside_email_keys(): void
    {
        $this->assertSame([], array_diff(array_keys(EmailDefaults::all()), Settings::EMAIL_KEYS));
    }

    public function test_subjects_are_single_line(): void
    {
        foreach (Settings::EMAIL_KEYS as $key) {
            if (str_ends_with($key, '_subject')) {
                $this->assertStringNotContainsString("\n", EmailDefaults::get($key), "$key must be single-line");
            }
        }
    }

    public function test_admin_body_default_is_pii_free(): void
    {
        // The "Anfrage von: %name% <%email%>" line is appended dynamically by the
        // Mailer; the shared default must stay PII-free so the settings screen never
        // prefills a template that renders an empty "Anfrage von:  <>".
        $body = EmailDefaults::get('email_admin_body');
        $this->assertStringNotContainsString('%name%', $body);
        $this->assertStringNotContainsString('%email%', $body);
    }

    public function test_unknown_key_yields_empty_string(): void
    {
        $this->assertSame('', EmailDefaults::get('email_bogus_body'));
    }
}
