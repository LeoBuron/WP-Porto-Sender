<?php // tests/integration/Admin/ToolsPageExportImportTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Admin;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Admin\ToolsPage;
use PortoSender\Admin\CodeIntakePage;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Portability\ImportService;
use PortoSender\Settings\Settings;

final class ToolsPageExportImportTest extends PortoTestCase
{
    private function seed(CodeRepository $codes, RequestRepository $requests): void
    {
        $codes->addBatch('standardbrief', 95, new \DateTimeImmutable('2026-01-15'), ['TOOLS1', 'TOOLS2']);
        $requests->createPending([
            'name' => 'Bob', 'email' => 'bob@example.test',
            'email_hash' => 'eh', 'name_hash' => 'nh', 'product' => 'standardbrief',
            'token_hash' => 'tok-tools', 'ip_hash' => null, 'created_at' => '2026-01-15 10:00:00',
        ]);
    }

    public function test_export_payload_codes_csv(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $this->seed($codes, $requests);

        $payload = (new ToolsPage($codes, $requests))->exportPayload('codes_csv', null);
        $this->assertStringContainsString('text/csv', $payload['contentType']);
        $this->assertStringStartsWith('porto-codes-', $payload['filename']);
        $this->assertStringContainsString('TOOLS1', $payload['body']);
    }

    public function test_export_payload_requests_csv_includes_pii(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $this->seed($codes, $requests);

        $payload = (new ToolsPage($codes, $requests))->exportPayload('requests_csv', null);
        $this->assertStringContainsString('bob@example.test', $payload['body']);
        $this->assertStringStartsWith('porto-requests-', $payload['filename']);
    }

    public function test_export_payload_bundle_then_import_full_restore_round_trips(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $this->seed($codes, $requests);
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'TOOLSALT']));

        $tools = new ToolsPage($codes, $requests);
        $bundle = $tools->exportPayload('bundle', null);
        $this->assertStringContainsString('application/json', $bundle['contentType']);

        // wipe + change salt, then restore from the exported bundle body
        $codes->deleteAll();
        $requests->deleteAll();
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'OTHER']));

        $result = $tools->importResult($bundle['body'], null, ImportService::MODE_FULL);
        $this->assertSame(2, $result['codes']);
        $this->assertSame('TOOLSALT', get_option(Settings::OPTION)['hash_salt']);
        $this->assertNotNull($requests->findByTokenHash('tok-tools'));
    }

    public function test_code_intake_csv_file_import(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $page = new CodeIntakePage($codes, ProductCatalog::default());

        $tmp = tempnam(sys_get_temp_dir(), 'portocsv');
        file_put_contents($tmp, "product,code,value_cents\nstandardbrief,CSVA,95\nnope,CSVB,95\nstandardbrief,CSVA,95\n");
        try {
            $result = $page->importCsvFile($tmp);
        } finally {
            @unlink($tmp);
        }

        $this->assertSame(1, $result['inserted']); // CSVA once
        // CSVB (unknown product) + the duplicate CSVA -> 2 skipped
        $this->assertCount(2, $result['skipped']);
        $this->assertSame(1, $codes->availableCount('standardbrief', new \DateTimeImmutable('2026-06-25')));
    }
}
