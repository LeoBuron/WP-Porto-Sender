<?php
declare(strict_types=1);
namespace PortoSender\Issuance;

use PortoSender\Captcha\CaptchaVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\Mailer;
use PortoSender\Support\Hasher;
use PortoSender\Support\TokenGenerator;
use PortoSender\Support\Clock;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;

final class IssuanceService
{
    public function __construct(
        private CaptchaVerifier $captcha,
        private RequestLimiter $limiter,
        private CodeStore $codes,
        private RequestStore $requests,
        private Mailer $mailer,
        private Hasher $hasher,
        private TokenGenerator $tokens,
        private ConfirmLinkBuilder $links,
        private Settings $settings,
        private ProductCatalog $catalog,
        private Clock $clock,
    ) {}

    /** @return array{status:string} */
    public function submit(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $product = (string) ($input['product'] ?? '');

        $enabled = $this->settings->enabledProducts();
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($product, $enabled, true)) {
            return ['status' => 'invalid'];
        }
        if (!$this->captcha->verify((string) ($input['captcha'] ?? ''))) {
            return ['status' => 'captcha_failed'];
        }

        $emailHash = $this->hasher->email($email);
        $nameHash = $this->hasher->name($name);
        if (!$this->limiter->allow($this->settings->requestLimitMode(), $emailHash, $nameHash)) {
            return ['status' => 'duplicate'];
        }

        $now = $this->clock->now();
        if ($this->codes->availableCount($product, $now) <= 0) {
            return ['status' => 'out_of_stock'];
        }

        $token = $this->tokens->generate();
        $this->requests->createPending([
            'name' => $name, 'email' => $email,
            'email_hash' => $emailHash, 'name_hash' => $nameHash,
            'product' => $product, 'token_hash' => $this->hasher->token($token),
            'ip_hash' => isset($input['ip']) ? $this->hasher->ip((string) $input['ip']) : null,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->mailer->sendConfirmation($email, $name, $this->links->build($token));

        return ['status' => 'confirmation_sent'];
    }
}
