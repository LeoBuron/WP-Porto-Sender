<?php
declare(strict_types=1);
namespace PortoSender\Rest;

use PortoSender\Issuance\IssuanceService;
use PortoSender\Captcha\CaptchaVerifier;

final class RestController
{
    public const NS = 'porto/v1';

    public function __construct(private IssuanceService $issuance, private CaptchaVerifier $captcha) {}

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/request', [
                'methods' => 'POST',
                'permission_callback' => '__return_true', // public; CAPTCHA + rate limit are the gate
                'callback' => [$this, 'handleRequest'],
            ]);
            register_rest_route(self::NS, '/altcha', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => [$this, 'handleChallenge'],
            ]);
        });
    }

    public function handleRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $result = $this->issuance->submit([
            'name' => (string) $request->get_param('name'),
            'email' => (string) $request->get_param('email'),
            'product' => (string) $request->get_param('product'),
            'captcha' => (string) $request->get_param('captcha'),
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        ]);
        $httpStatus = match ($result['status']) {
            'confirmation_sent' => 200,
            'rate_limited' => 429,
            default => 422,
        };
        return new \WP_REST_Response($result, $httpStatus);
    }

    public function handleChallenge(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->captcha->challenge(), 200);
    }
}
