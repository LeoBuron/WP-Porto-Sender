<?php
declare(strict_types=1);
namespace PortoSender\Mail;

/**
 * Single source of the built-in e-mail copy, keyed by Settings::EMAIL_KEYS.
 * Consumed by the Mailer (fallback for empty stored templates), the settings
 * screen (prefills the template boxes so admins edit the real text instead of
 * an empty field), and Settings::sanitize() (a submitted value identical to
 * its default is stored as '' again = "follow the plugin default").
 *
 * The admin-notification body is the PII-free base; the Mailer appends the
 * "Anfrage von" line dynamically when the caller supplies name + e-mail.
 */
final class EmailDefaults
{
    /** @return array<string,string> template key → built-in default (subjects single-line, bodies multi-line) */
    public static function all(): array
    {
        return [
            'email_confirm_subject' => __('Bitte bestätige deine Porto-Anfrage', 'wp-porto-sender'),
            'email_confirm_body' => __("Hallo %name%,\n\nbitte bestätige deine Anfrage über diesen Link:\n%confirm_url%\n\nWenn du das nicht warst, ignoriere diese E-Mail.", 'wp-porto-sender'),
            'email_delivery_subject' => __('Dein Porto-Code', 'wp-porto-sender'),
            'email_delivery_body' => __("Hallo %name%,\n\nhier ist dein Porto-Code für einen %product% (%limits%):\n\n    #PORTO %code%\n\nSchreibe diesen Code oben rechts auf den Umschlag (in das Frankierfeld) und sende den Brief an:\n\n%owner_address%\n\nGültig bis Ende des dritten Jahres nach dem Kauf.", 'wp-porto-sender'),
            'email_admin_subject' => __('WP-Porto-Sender: Porto abgerufen', 'wp-porto-sender'),
            'email_admin_body' => __("Es wurden Porto-Codes abgerufen.\n\nProdukt: %product%\nAnzahl seit der letzten Benachrichtigung: %count%\nVerbleibender Vorrat: %remaining%", 'wp-porto-sender'),
            'email_lowstock_subject' => __('WP-Porto-Sender: Vorrat wird knapp', 'wp-porto-sender'),
            'email_lowstock_body' => __('Nur noch %remaining% Codes für "%product%" verfügbar. Bitte nachfüllen.', 'wp-porto-sender'),
            'email_outofstock_subject' => __('WP-Porto-Sender: Vorrat erschöpft', 'wp-porto-sender'),
            'email_outofstock_body' => __('Es sind keine Codes für "%product%" mehr verfügbar.', 'wp-porto-sender'),
        ];
    }

    /** The built-in default for one template key ('' for unknown keys). */
    public static function get(string $key): string
    {
        return self::all()[$key] ?? '';
    }
}
