<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PHPUnit\Framework\TestCase;
use PortoSender\Portability\BundleSerializer;

final class BundleSerializerTest extends TestCase
{
    /** @return array<string,mixed> */
    private function sampleSettings(): array
    {
        return ['hash_salt' => 'SECRETSALT', 'pii_retention_days' => 180, 'enabled_products' => ['standardbrief']];
    }

    public function test_build_then_parse_round_trips_losslessly_including_salt(): void
    {
        $settings = $this->sampleSettings();
        $codes = [['id' => 1, 'code' => 'AB12', 'product' => 'standardbrief']];
        $requests = [['id' => 7, 'email_hash' => 'abc', 'token_hash' => 'def']];

        $serializer = new BundleSerializer();
        $json = $serializer->build($settings, $codes, $requests, '1', 'https://example.test', '2026-06-25 00:00:00');
        $parsed = $serializer->parse($json);

        $this->assertSame(BundleSerializer::FORMAT_VERSION, $parsed['format_version']);
        $this->assertSame('1', $parsed['schema_version']);
        $this->assertSame('https://example.test', $parsed['site_url']);
        $this->assertSame('2026-06-25 00:00:00', $parsed['exported_at']);
        $this->assertSame('SECRETSALT', $parsed['settings']['hash_salt']);
        $this->assertSame($settings, $parsed['settings']);
        $this->assertSame($codes, $parsed['codes']);
        $this->assertSame($requests, $parsed['requests']);
    }

    public function test_parse_rejects_unknown_format_version(): void
    {
        $json = json_encode([
            'format_version' => 999, 'schema_version' => '1',
            'settings' => [], 'codes' => [], 'requests' => [],
        ]);
        $this->expectException(\RuntimeException::class);
        (new BundleSerializer())->parse($json);
    }

    public function test_parse_rejects_malformed_json(): void
    {
        $this->expectException(\RuntimeException::class);
        (new BundleSerializer())->parse('{not valid json');
    }

    public function test_parse_rejects_missing_required_keys(): void
    {
        $json = json_encode(['format_version' => BundleSerializer::FORMAT_VERSION]);
        $this->expectException(\RuntimeException::class);
        (new BundleSerializer())->parse($json);
    }
}
