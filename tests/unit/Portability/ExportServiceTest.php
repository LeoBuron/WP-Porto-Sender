<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PHPUnit\Framework\TestCase;
use PortoSender\Portability\ExportService;
use PortoSender\Portability\BundleSerializer;
use PortoSender\Portability\BundleCrypto;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;
use PortoSender\Settings\Settings;

final class ExportServiceTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $codes
     * @param array<int,array<string,mixed>> $requests
     * @param array<string,mixed> $settings
     */
    private function service(array $codes, array $requests, array $settings): ExportService
    {
        $codeStore = $this->createMock(CodeStore::class);
        $codeStore->method('allRows')->willReturn($codes);
        $reqStore = $this->createMock(RequestStore::class);
        $reqStore->method('allRows')->willReturn($requests);

        return new ExportService(
            $codeStore,
            $reqStore,
            new Settings($settings),
            '1',
            'https://example.test',
            '2026-06-25 00:00:00'
        );
    }

    public function test_codes_csv_has_header_and_escapes_formula_cells(): void
    {
        $svc = $this->service([['id' => 1, 'code' => '=EVIL', 'product' => 'standardbrief']], [], []);
        $csv = $svc->codesCsv();
        $this->assertStringContainsString("id,code,product\r\n", $csv);
        $this->assertStringContainsString("1,'=EVIL,standardbrief", $csv);
    }

    public function test_requests_csv_includes_pii_columns(): void
    {
        $svc = $this->service([], [['id' => 7, 'name' => 'Alice', 'email' => 'a@example.test', 'email_hash' => 'h']], []);
        $csv = $svc->requestsCsv();
        $this->assertStringContainsString('name,email,email_hash', $csv);
        $this->assertStringContainsString('Alice,a@example.test,h', $csv);
    }

    public function test_empty_table_yields_empty_csv(): void
    {
        $this->assertSame('', $this->service([], [], [])->codesCsv());
    }

    public function test_bundle_is_parseable_json_including_salt_when_no_passphrase(): void
    {
        $svc = $this->service([['id' => 1, 'code' => 'AB12']], [['id' => 7, 'token_hash' => 't']], ['hash_salt' => 'SECRETSALT']);
        $parsed = (new BundleSerializer())->parse($svc->bundle(null));
        $this->assertSame('SECRETSALT', $parsed['settings']['hash_salt']);
        $this->assertSame('AB12', $parsed['codes'][0]['code']);
        $this->assertSame('t', $parsed['requests'][0]['token_hash']);
        $this->assertSame('1', $parsed['schema_version']);
        $this->assertSame('https://example.test', $parsed['site_url']);
    }

    public function test_bundle_is_encrypted_when_passphrase_given(): void
    {
        if (!BundleCrypto::available()) {
            $this->markTestSkipped('ext-sodium not available');
        }
        $svc = $this->service([], [], ['hash_salt' => 'S']);
        $enc = $svc->bundle('pw');
        $this->assertStringStartsWith('PORTOENC1', $enc);
        $json = (new BundleCrypto())->decrypt($enc, 'pw');
        $this->assertSame('S', (new BundleSerializer())->parse($json)['settings']['hash_salt']);
    }
}
