<?php
declare(strict_types=1);
namespace PortoSender\Mail;

use PortoSender\Settings\Settings;
use PortoSender\Postage\PostageProduct;

final class Mailer implements MailerInterface
{
    public function __construct(private Settings $settings) {}

    public function sendConfirmation(string $email, string $name, string $confirmUrl): bool
    {
        $subject = __('Bitte bestätige deine Porto-Anfrage', 'wp-porto-sender');
        $body = sprintf(
            __("Hallo %s,\n\nbitte bestätige deine Anfrage über diesen Link:\n%s\n\nWenn du das nicht warst, ignoriere diese E-Mail.", 'wp-porto-sender'),
            $name, $confirmUrl
        );
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendDelivery(string $email, string $name, string $code, PostageProduct $product): bool
    {
        $subject = __('Dein Porto-Code', 'wp-porto-sender');
        $body = sprintf(
            __("Hallo %s,\n\nhier ist dein Porto-Code für einen %s (%s):\n\n    #PORTO %s\n\nSchreibe diesen Code oben rechts auf den Umschlag (in das Frankierfeld) und sende den Brief an:\n\n%s\n\nGültig bis Ende des dritten Jahres nach dem Kauf.", 'wp-porto-sender'),
            $name, $product->label, $product->limits, $code, $this->settings->ownerAddress()
        );
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendLowStock(string $to, string $productLabel, int $remaining): bool
    {
        $subject = __('WP-Porto-Sender: Vorrat wird knapp', 'wp-porto-sender');
        $body = sprintf(__('Nur noch %d Codes für "%s" verfügbar. Bitte nachfüllen.', 'wp-porto-sender'), $remaining, $productLabel);
        return (bool) wp_mail($to, $subject, $body);
    }

    public function sendOutOfStock(string $to, string $productLabel): bool
    {
        $subject = __('WP-Porto-Sender: Vorrat erschöpft', 'wp-porto-sender');
        $body = sprintf(__('Es sind keine Codes für "%s" mehr verfügbar.', 'wp-porto-sender'), $productLabel);
        return (bool) wp_mail($to, $subject, $body);
    }

    /**
     * Notify the admin that visitor(s) claimed a porto code. PII-free by default;
     * name/email are appended only when the caller supplies them (opt-in setting).
     *
     * @param array{product_label:string,count:int,remaining:int,name:?string,email:?string} $data
     */
    public function sendAdminNotification(string $to, array $data): bool
    {
        $subject = __('WP-Porto-Sender: Porto abgerufen', 'wp-porto-sender');
        $body = sprintf(
            __("Es wurden Porto-Codes abgerufen.\n\nProdukt: %1\$s\nAnzahl seit der letzten Benachrichtigung: %2\$d\nVerbleibender Vorrat: %3\$d", 'wp-porto-sender'),
            (string) $data['product_label'],
            (int) $data['count'],
            (int) $data['remaining']
        );

        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        if ($name !== null && $name !== '' && $email !== null && $email !== '') {
            $body .= sprintf(__("\n\nAnfrage von: %s <%s>", 'wp-porto-sender'), $name, $email);
        }

        return (bool) wp_mail($to, $subject, $body);
    }
}
