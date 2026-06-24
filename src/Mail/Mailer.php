<?php
declare(strict_types=1);
namespace PortoSender\Mail;

use PortoSender\Settings\Settings;
use PortoSender\Postage\PostageProduct;

final class Mailer
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
}
