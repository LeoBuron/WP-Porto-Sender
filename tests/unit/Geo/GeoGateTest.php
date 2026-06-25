<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Geo;

use PHPUnit\Framework\TestCase;
use PortoSender\Geo\GeoGate;
use PortoSender\Geo\GeoProvider;
use PortoSender\Settings\Settings;

final class GeoGateTest extends TestCase
{
    private function provider(?string $country, bool $throws = false): GeoProvider
    {
        return new class($country, $throws) implements GeoProvider {
            public function __construct(private ?string $c, private bool $throws) {}
            public function country(string $ip): ?string
            {
                if ($this->throws) { throw new \RuntimeException('boom'); }
                return $this->c;
            }
        };
    }

    public function test_disabled_allows_all(): void
    {
        $gate = new GeoGate($this->provider('FR'), new Settings(['geo_enabled' => false]));
        $this->assertTrue($gate->allows('1.2.3.4'));
    }

    public function test_allowed_country_passes(): void
    {
        $gate = new GeoGate($this->provider('DE'), new Settings(['geo_enabled' => true, 'geo_allowed_countries' => ['DE']]));
        $this->assertTrue($gate->allows('1.2.3.4'));
    }

    public function test_disallowed_country_denied(): void
    {
        $gate = new GeoGate($this->provider('FR'), new Settings(['geo_enabled' => true, 'geo_allowed_countries' => ['DE']]));
        $this->assertFalse($gate->allows('1.2.3.4'));
    }

    public function test_unknown_fails_open_by_default(): void
    {
        $gate = new GeoGate($this->provider(null), new Settings(['geo_enabled' => true, 'geo_fail_mode' => 'open']));
        $this->assertTrue($gate->allows('1.2.3.4'));
    }

    public function test_unknown_fails_closed_when_configured(): void
    {
        $gate = new GeoGate($this->provider(null), new Settings(['geo_enabled' => true, 'geo_fail_mode' => 'closed']));
        $this->assertFalse($gate->allows('1.2.3.4'));
    }

    public function test_provider_exception_is_caught_and_uses_fail_mode(): void
    {
        $open = new GeoGate($this->provider(null, true), new Settings(['geo_enabled' => true, 'geo_fail_mode' => 'open']));
        $this->assertTrue($open->allows('1.2.3.4'));
        $closed = new GeoGate($this->provider(null, true), new Settings(['geo_enabled' => true, 'geo_fail_mode' => 'closed']));
        $this->assertFalse($closed->allows('1.2.3.4'));
    }

    public function test_multi_country_allowlist(): void
    {
        $gate = new GeoGate($this->provider('AT'), new Settings(['geo_enabled' => true, 'geo_allowed_countries' => ['DE', 'AT']]));
        $this->assertTrue($gate->allows('1.2.3.4'));
    }
}
