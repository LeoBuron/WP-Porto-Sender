<?php // tests/integration/Portability/ImportServiceTest.php
declare(strict_types=1);
namespace PortoSender\Tests\integration\Portability;
use PortoSender\Tests\integration\PortoTestCase;
use PortoSender\Inventory\CodeRepository;
use PortoSender\Requests\RequestRepository;
use PortoSender\Portability\ExportService;
use PortoSender\Portability\ImportService;
use PortoSender\Settings\Settings;
use PortoSender\Persistence\SchemaVersion;

final class ImportServiceTest extends PortoTestCase
{
    public function test_full_restore_round_trips_data_and_salt(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);

        // --- source install: seed data, a known salt, and a salted token ---
        $codes->addBatch('standardbrief', 95, new \DateTimeImmutable('2026-01-15'), ['ORIG1', 'ORIG2']);
        $requests->createPending([
            'name' => 'Alice', 'email' => 'alice@example.test',
            'email_hash' => 'eh', 'name_hash' => 'nh', 'product' => 'standardbrief',
            'token_hash' => 'tok-orig', 'ip_hash' => null, 'created_at' => '2026-01-15 10:00:00',
        ]);
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'SOURCESALT']));
        (new SchemaVersion())->set('1');

        $bundle = (new ExportService(
            $codes, $requests, Settings::fromOption(), '1', 'https://src.test', '2026-06-25 00:00:00'
        ))->bundle(null);

        // --- target install: data wiped and the salt changed (the bug WS2 prevents) ---
        $codes->deleteAll();
        $requests->deleteAll();
        update_option(Settings::OPTION, array_merge(Settings::defaults(), ['hash_salt' => 'DIFFERENTSALT']));
        $this->assertSame([], $codes->allRows());

        // --- restore ---
        $result = (new ImportService($codes, $requests))->importBundle($bundle, null, ImportService::MODE_FULL);

        $this->assertSame('full_restore', $result['mode']);
        $this->assertSame(2, $result['codes']);
        $this->assertSame(1, $result['requests']);

        // data restored
        $restoredCodes = array_column($codes->allRows(), 'code');
        $this->assertContains('ORIG1', $restoredCodes);
        $this->assertContains('ORIG2', $restoredCodes);

        // salt restored -> a hash computed under SOURCESALT (here the token row) resolves again
        $this->assertSame('SOURCESALT', get_option(Settings::OPTION)['hash_salt']);
        $this->assertNotNull($requests->findByTokenHash('tok-orig'));
    }

    public function test_full_restore_ignores_unknown_columns_in_bundle(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);

        // Hand-craft a bundle whose code row carries a bogus extra column.
        $json = (new \PortoSender\Portability\BundleSerializer())->build(
            array_merge(Settings::defaults(), ['hash_salt' => 'S']),
            [['id' => 1, 'product' => 'standardbrief', 'value_cents' => 95, 'purchase_date' => '2026-01-15',
              'expires_on' => '2030-01-15', 'code' => 'CLEAN1', 'status' => 'available',
              'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00',
              'evil_column' => 'DROP']],
            [],
            '1', 'https://src.test', '2026-06-25 00:00:00'
        );

        $result = (new ImportService($codes, $requests))->importBundle($json, null, ImportService::MODE_FULL);
        $this->assertSame(1, $result['codes']);
        $this->assertContains('CLEAN1', array_column($codes->allRows(), 'code'));
    }
}
