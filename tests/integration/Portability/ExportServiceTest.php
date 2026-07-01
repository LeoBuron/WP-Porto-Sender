<?php // tests/integration/Portability/ExportServiceTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Portability;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Portability\ExportService;
use PortoSender\Portability\BundleSerializer;
use PortoSender\Settings\Settings;

final class ExportServiceTest extends PortoTestCase
{
    public function test_export_includes_seeded_code_and_request_in_csv_and_bundle(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);

        $codes->addBatch('standardbrief', new \DateTimeImmutable('2026-01-15'), ['EXPORTME1']);
        $requests->createPending([
            'name' => 'Alice', 'email' => 'alice@example.test',
            'email_hash' => 'eh', 'name_hash' => 'nh', 'product' => 'standardbrief',
            'token_hash' => 'th', 'ip_hash' => null, 'created_at' => '2026-01-15 10:00:00',
        ]);

        $svc = new ExportService(
            $codes,
            $requests,
            new Settings(['hash_salt' => 'REALSALT']),
            '1',
            'https://example.test',
            '2026-06-25 00:00:00'
        );

        $codesCsv = $svc->codesCsv();
        $this->assertStringContainsString('code', $codesCsv); // header row present
        $this->assertStringContainsString('EXPORTME1', $codesCsv);

        $reqCsv = $svc->requestsCsv();
        $this->assertStringContainsString('Alice', $reqCsv);
        $this->assertStringContainsString('alice@example.test', $reqCsv);

        $bundle = (new BundleSerializer())->parse($svc->bundle(null));
        $this->assertSame('REALSALT', $bundle['settings']['hash_salt']);
        $this->assertCount(1, $bundle['codes']);
        $this->assertSame('EXPORTME1', $bundle['codes'][0]['code']);
        $this->assertCount(1, $bundle['requests']);
        $this->assertSame('alice@example.test', $bundle['requests'][0]['email']);
    }
}
