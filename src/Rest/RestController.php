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

        // Some sites lock the ENTIRE REST API to logged-in users (e.g. the "Disable WP
        // REST API" plugin hooks rest_authentication_errors at priority 10 and returns a
        // 401 for anonymous requests). Our two routes are PUBLIC by design — the captcha
        // challenge and the captcha + rate-limit-gated submit — so a logged-out visitor
        // MUST reach them. Re-allow ONLY our namespace, at the latest priority so we clear
        // such a filter's error for our routes without reopening the rest of the API.
        add_filter('rest_authentication_errors', [$this, 'allowPublicNamespace'], PHP_INT_MAX);
    }

    /**
     * Clear a REST authentication error imposed by a site-wide lockdown, but ONLY for this
     * plugin's public namespace. Every other route is returned untouched, so the site's
     * REST hardening is preserved everywhere except our two intentionally-public endpoints.
     *
     * @param mixed $result accumulated rest_authentication_errors value (true|WP_Error|null)
     * @return mixed
     */
    public function allowPublicNamespace(mixed $result): mixed
    {
        if (is_wp_error($result) && $this->isPublicNamespaceRequest()) {
            return true;
        }
        return $result;
    }

    private function isPublicNamespaceRequest(): bool
    {
        // Prefer the parsed rest_route var (e.g. "/porto/v1/altcha"); fall back to the URI
        // (e.g. "/wp-json/porto/v1/altcha") for setups where it isn't populated yet.
        $route = '';
        if (isset($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
        }
        if ($route === '' && isset($_SERVER['REQUEST_URI'])) {
            $route = (string) wp_unslash($_SERVER['REQUEST_URI']);
        }
        if ($route === '') {
            return false;
        }
        // Anchor on a leading slash ("/porto/v1/") so we match "/porto/v1/altcha" and
        // "/wp-json/porto/v1/request" but never a lookalike like "/x-porto/v1/…".
        return str_contains('/' . ltrim($route, '/'), '/' . self::NS . '/');
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
            'geo_blocked' => 403,
            default => 422,
        };
        return new \WP_REST_Response($result, $httpStatus);
    }

    public function handleChallenge(): \WP_REST_Response
    {
        return new \WP_REST_Response($this->captcha->challenge(), 200);
    }
}
