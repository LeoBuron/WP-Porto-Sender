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
        [$subject, $body] = $this->compose('email_confirm_subject', 'email_confirm_body', $vars);
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
        [$subject, $body] = $this->compose('email_delivery_subject', 'email_delivery_body', $vars);
        return (bool) wp_mail($email, $subject, $body);
    }

    public function sendLowStock(string $to, string $productLabel, int $remaining): bool
    {
        $vars = [
            '%product%' => $productLabel,
            '%remaining%' => (string) $remaining,
        ];
        [$subject, $body] = $this->compose('email_lowstock_subject', 'email_lowstock_body', $vars);
        return (bool) wp_mail($to, $subject, $body);
    }

    public function sendOutOfStock(string $to, string $productLabel): bool
    {
        $vars = ['%product%' => $productLabel];
        [$subject, $body] = $this->compose('email_outofstock_subject', 'email_outofstock_body', $vars);
        return (bool) wp_mail($to, $subject, $body);
    }

    /**
     * Notify the admin that visitor(s) claimed a porto code. PII-free by default; the
     * claimants (name/email) are included only when the caller passes them — the
     * AdminNotifier passes an empty list unless the opt-in setting is on.
     *
     * A batch (several claims collapsed into one throttled mail) lists every claimant:
     * the default body appends one line per requester, and custom templates can use
     * %requests% for the full list. %name%/%email% resolve to the first claimant (so
     * count=1 templates keep working) and are '' when PII is off.
     *
     * @param array{product_label:string,count:int,remaining:int,
     *     requesters?:list<array{name:string,email:string}>,name?:?string,email?:?string} $data
     */
    public function sendAdminNotification(string $to, array $data): bool
    {
        $requesters = $this->normalizeRequesters($data);
        $first = $requesters[0] ?? ['name' => '', 'email' => ''];

        $vars = [
            '%product%' => (string) $data['product_label'],
            '%count%' => (string) (int) $data['count'],
            '%remaining%' => (string) (int) $data['remaining'],
            '%name%' => $first['name'],
            '%email%' => $first['email'],
            '%requests%' => implode("\n", array_map(
                fn (array $r): string => '- ' . $this->formatRequester($r),
                $requesters
            )),
        ];

        // The default body carries claimant lines only when PII is present, so the
        // PII-free default never renders an empty "Anfrage von:  <>". A single claimant
        // keeps the original one-line wording; a batch lists all of them. Custom
        // templates may reference %name%/%email% or %requests% directly.
        $defaultBody = EmailDefaults::get('email_admin_body');
        if (count($requesters) === 1) {
            $defaultBody .= __("\n\nAnfrage von: %name% <%email%>", 'wp-porto-sender');
        } elseif (count($requesters) > 1) {
            $defaultBody .= __("\n\nAnfragen:\n%requests%", 'wp-porto-sender');
        }

        [$subject, $body] = $this->compose('email_admin_subject', 'email_admin_body', $vars, $defaultBody);
        return (bool) wp_mail($to, $subject, $body);
    }

    /**
     * Normalise the claimant list from either the new `requesters` list or the legacy
     * single `name`/`email` pair (kept for callers/tests that predate batching). Legacy
     * entries require BOTH name and email (the original PII gate); list entries keep any
     * non-empty side. Returns a re-indexed list.
     *
     * @param array<string,mixed> $data
     * @return list<array{name:string,email:string}>
     */
    private function normalizeRequesters(array $data): array
    {
        $out = [];
        if (isset($data['requesters']) && is_array($data['requesters'])) {
            foreach ($data['requesters'] as $r) {
                if (!is_array($r)) { continue; }
                $name = trim((string) ($r['name'] ?? ''));
                $email = trim((string) ($r['email'] ?? ''));
                if ($name !== '' || $email !== '') {
                    $out[] = ['name' => $name, 'email' => $email];
                }
            }
            return $out;
        }
        $name = trim((string) ($data['name'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($name !== '' && $email !== '') {
            $out[] = ['name' => $name, 'email' => $email];
        }
        return $out;
    }

    /** "Name <email>", degrading to whichever side is present. */
    private function formatRequester(array $r): string
    {
        $name = (string) ($r['name'] ?? '');
        $email = (string) ($r['email'] ?? '');
        if ($name !== '' && $email !== '') {
            return $name . ' <' . $email . '>';
        }
        return $email !== '' ? $email : $name;
    }

    /**
     * Resolve a message's subject and body: use the admin-configured template when the
     * stored value is non-empty, otherwise the built-in default (EmailDefaults), then
     * substitute the %placeholder% tokens. $defaultBodyOverride lets the admin
     * notification swap in its dynamically extended (PII-carrying) default body.
     *
     * @param array<string,string> $vars
     * @return array{0:string,1:string} [subject, body]
     */
    private function compose(string $subjectKey, string $bodyKey, array $vars, ?string $defaultBodyOverride = null): array
    {
        $subjectTpl = $this->settings->emailTemplate($subjectKey);
        $bodyTpl = $this->settings->emailTemplate($bodyKey);
        return [
            $this->render($subjectTpl !== '' ? $subjectTpl : EmailDefaults::get($subjectKey), $vars),
            $this->render($bodyTpl !== '' ? $bodyTpl : ($defaultBodyOverride ?? EmailDefaults::get($bodyKey)), $vars),
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
