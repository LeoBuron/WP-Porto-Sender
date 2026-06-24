<?php
declare(strict_types=1);
namespace PortoSender\Issuance;

use PortoSender\Captcha\CaptchaVerifier;
use PortoSender\Limiting\RequestLimiter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Mail\MailerInterface;
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
        private MailerInterface $mailer,
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

    private const MAX_CLAIM_ATTEMPTS = 3;

    /** @return array{status:string} */
    public function confirm(string $token): array
    {
        $req = $this->requests->findByTokenHash($this->hasher->token($token));
        if ($req === null || in_array($req->status, ['rejected'], true)) {
            return ['status' => 'invalid_token'];
        }
        if ($req->status === 'issued') {
            return ['status' => 'already_issued'];
        }
        if (!in_array($req->status, ['pending', 'confirmed'], true)) {
            return ['status' => 'invalid_token'];
        }

        $now = $this->clock->now();
        $expiresAt = (new \DateTimeImmutable($req->created_at))->modify('+' . $this->settings->confirmTokenTtlHours() . ' hours');
        if ($now > $expiresAt) {
            return ['status' => 'expired'];
        }

        $this->requests->markConfirmed((int) $req->id, $now); // no-op if already confirmed

        $codeId = null;
        for ($i = 0; $i < self::MAX_CLAIM_ATTEMPTS; $i++) {
            $codeId = $this->codes->claimOne($req->product, $now, $this->settings->reservationTtlMinutes());
            if ($codeId !== null) { break; }
        }
        if ($codeId === null) {
            return ['status' => 'out_of_stock'];
        }

        $this->codes->markIssued($codeId, (int) $req->id, (string) $req->email_hash, $now);
        $this->requests->markIssued((int) $req->id, $codeId, $now);

        $code = $this->codes->getCode($codeId);
        $product = $this->catalog->get($req->product);
        $this->mailer->sendDelivery((string) $req->email, (string) $req->name, (string) $code->code, $product);

        return ['status' => 'issued'];
    }
}
