<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

use PortoSender\Issuance\IssuanceService;

final class ConfirmHandler
{
    private const MESSAGES = [
        'issued' => 'Dein Porto-Code wurde dir per E-Mail zugeschickt.',
        'already_issued' => 'Du hast deinen Porto-Code bereits erhalten.',
        'expired' => 'Dieser Bestätigungslink ist abgelaufen. Bitte stelle eine neue Anfrage.',
        'out_of_stock' => 'Aktuell sind keine Codes verfügbar. Bitte versuche es später erneut.',
        'invalid_token' => 'Dieser Bestätigungslink ist ungültig.',
    ];

    public function __construct(private IssuanceService $issuance) {}

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybeHandle']);
    }

    public function maybeHandle(): void
    {
        if (!isset($_GET['porto_confirm'])) { return; }
        $status = $this->process(sanitize_text_field(wp_unslash($_GET['porto_confirm'])));
        $message = self::MESSAGES[$status] ?? self::MESSAGES['invalid_token'];
        wp_die(esc_html__($message, 'wp-porto-sender'), esc_html__('Porto-Anfrage', 'wp-porto-sender'), ['response' => 200]);
    }

    public function process(string $token): string
    {
        return $this->issuance->confirm($token)['status'];
    }
}
