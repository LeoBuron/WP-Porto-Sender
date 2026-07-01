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
        $vars = [
            '%name%' => $name,
            '%confirm_url%' => $confirmUrl,
        ];
        [$subject, $body] = $this->compose(
            'email_confirm_subject',
            __('Bitte bestätige deine Porto-Anfrage', 'wp-porto-sender'),
            'email_confirm_body',
            __("Hallo %name%,\n\nbitte bestätige deine Anfrage über diesen Link:\n%confirm_url%\n\nWenn du das nicht warst, ignoriere diese E-Mail.", 'wp-porto-sender'),
            $vars
        );
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendDelivery(string $email, string $name, string $code, PostageProduct $product): bool
    {
        $vars = [
            '%name%' => $name,
            '%product%' => $product->label,
            '%limits%' => $product->limits,
            '%code%' => $code,
            '%owner_address%' => $this->settings->ownerAddress(),
        ];
        [$subject, $body] = $this->compose(
            'email_delivery_subject',
            __('Dein Porto-Code', 'wp-porto-sender'),
            'email_delivery_body',
            __("Hallo %name%,\n\nhier ist dein Porto-Code für einen %product% (%limits%):\n\n    #PORTO %code%\n\nSchreibe diesen Code oben rechts auf den Umschlag (in das Frankierfeld) und sende den Brief an:\n\n%owner_address%\n\nGültig bis Ende des dritten Jahres nach dem Kauf.", 'wp-porto-sender'),
            $vars
        );
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendLowStock(string $to, string $productLabel, int $remaining): bool
    {
        $vars = [
            '%product%' => $productLabel,
            '%remaining%' => (string) $remaining,
        ];
        [$subject, $body] = $this->compose(
            'email_lowstock_subject',
            __('WP-Porto-Sender: Vorrat wird knapp', 'wp-porto-sender'),
            'email_lowstock_body',
            __('Nur noch %remaining% Codes für "%product%" verfügbar. Bitte nachfüllen.', 'wp-porto-sender'),
            $vars
        );
        return (bool) wp_mail($to, $subject, $body);
    }

    public function sendOutOfStock(string $to, string $productLabel): bool
    {
        $vars = ['%product%' => $productLabel];
        [$subject, $body] = $this->compose(
            'email_outofstock_subject',
            __('WP-Porto-Sender: Vorrat erschöpft', 'wp-porto-sender'),
            'email_outofstock_body',
            __('Es sind keine Codes für "%product%" mehr verfügbar.', 'wp-porto-sender'),
            $vars
        );
        return (bool) wp_mail($to, $subject, $body);
    }

    /**
     * Notify the admin that visitor(s) claimed a porto code. PII-free by default;
     * name/email are resolved (and appended in the default template) only when the
     * caller supplies them — the AdminNotifier passes null unless the opt-in setting
     * is on, so a custom template's %name%/%email% resolve to '' when PII is off.
     *
     * @param array{product_label:string,count:int,remaining:int,name:?string,email:?string} $data
     */
    public function sendAdminNotification(string $to, array $data): bool
    {
        $name = $data['name'] ?? null;
        $email = $data['email'] ?? null;
        $hasPii = $name !== null && $name !== '' && $email !== null && $email !== '';

        $vars = [
            '%product%' => (string) $data['product_label'],
            '%count%' => (string) (int) $data['count'],
            '%remaining%' => (string) (int) $data['remaining'],
            '%name%' => $hasPii ? (string) $name : '',
            '%email%' => $hasPii ? (string) $email : '',
        ];

        // The default body only carries the "Anfrage von" line when PII is present, so
        // the PII-free default never renders an empty "Anfrage von:  <>". A custom
        // template may reference %name%/%email% directly (they resolve to '' when off).
        $defaultBody = __("Es wurden Porto-Codes abgerufen.\n\nProdukt: %product%\nAnzahl seit der letzten Benachrichtigung: %count%\nVerbleibender Vorrat: %remaining%", 'wp-porto-sender');
        if ($hasPii) {
            $defaultBody .= __("\n\nAnfrage von: %name% <%email%>", 'wp-porto-sender');
        }

        [$subject, $body] = $this->compose(
            'email_admin_subject',
            __('WP-Porto-Sender: Porto abgerufen', 'wp-porto-sender'),
            'email_admin_body',
            $defaultBody,
            $vars
        );
        return (bool) wp_mail($to, $subject, $body);
    }

    /**
     * Resolve a message's subject and body: use the admin-configured template when the
     * stored value is non-empty, otherwise the built-in default, then substitute the
     * %placeholder% tokens.
     *
     * @param array<string,string> $vars
     * @return array{0:string,1:string} [subject, body]
     */
    private function compose(string $subjectKey, string $defaultSubject, string $bodyKey, string $defaultBody, array $vars): array
    {
        $subjectTpl = $this->settings->emailTemplate($subjectKey);
        $bodyTpl = $this->settings->emailTemplate($bodyKey);
        return [
            $this->render($subjectTpl !== '' ? $subjectTpl : $defaultSubject, $vars),
            $this->render($bodyTpl !== '' ? $bodyTpl : $defaultBody, $vars),
        ];
    }

    /**
     * Substitute %key% tokens in a plain-text template. Uses strtr() so replacement is
     * single-pass (a value containing a token is never re-substituted) and unknown
     * placeholders are left as-is.
     *
     * @param array<string,string> $vars
     */
    private function render(string $tpl, array $vars): string
    {
        return strtr($tpl, $vars);
    }
}
