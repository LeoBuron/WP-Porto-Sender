<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Rest;

use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Rest\RestController;

final class RestControllerTest extends WpUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_unslash')->returnArg(1);
        unset($GLOBALS['wp'], $_SERVER['REQUEST_URI']);
    }

    /**
     * allowPublicNamespace() doesn't touch the injected collaborators, and IssuanceService
     * is final (unmockable), so construct the controller without running the constructor.
     */
    private function controller(): RestController
    {
        return (new \ReflectionClass(RestController::class))->newInstanceWithoutConstructor();
    }

    public function test_clears_rest_lockdown_error_for_porto_namespace(): void
    {
        // A stand-in for a WP_Error returned by a site-wide REST lockdown.
        $error = new \stdClass();
        Functions\when('is_wp_error')->alias(static fn($x): bool => $x === $error);
        $_SERVER['REQUEST_URI'] = '/wp-json/porto/v1/altcha';

        $this->assertTrue($this->controller()->allowPublicNamespace($error));
    }

    public function test_leaves_error_intact_for_other_namespaces(): void
    {
        $error = new \stdClass();
        Functions\when('is_wp_error')->alias(static fn($x): bool => $x === $error);
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';

        $this->assertSame($error, $this->controller()->allowPublicNamespace($error));
    }

    public function test_prefers_rest_route_query_var_when_present(): void
    {
        $error = new \stdClass();
        Functions\when('is_wp_error')->alias(static fn($x): bool => $x === $error);
        $GLOBALS['wp'] = (object) ['query_vars' => ['rest_route' => '/porto/v1/request']];

        $this->assertTrue($this->controller()->allowPublicNamespace($error));
    }

    public function test_passes_through_when_there_is_no_error(): void
    {
        Functions\when('is_wp_error')->justReturn(false);
        $_SERVER['REQUEST_URI'] = '/wp-json/porto/v1/altcha';

        // true (auth already OK) and null (no auth attempted) must be returned unchanged,
        // so we never fabricate an error or mask another filter's success.
        $this->assertTrue($this->controller()->allowPublicNamespace(true));
        $this->assertNull($this->controller()->allowPublicNamespace(null));
    }
}
