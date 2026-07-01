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
        $codes->addBatch('standardbrief', new \DateTimeImmutable('2026-01-15'), ['ORIG1', 'ORIG2']);
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

    public function test_corrupt_bundle_does_not_wipe_existing_data(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        $codes->addBatch('standardbrief', new \DateTimeImmutable('2026-01-15'), ['KEEPME']);

        // Structurally-valid bundle but "codes" is a scalar -> must abort BEFORE any deleteAll.
        $corrupt = json_encode([
            'format_version' => 1, 'schema_version' => '1',
            'settings' => ['hash_salt' => 'x'], 'codes' => 'not-an-array', 'requests' => [],
        ]);

        $threw = false;
        try {
            (new ImportService($codes, $requests))->importBundle((string) $corrupt, null, ImportService::MODE_FULL);
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'corrupt bundle must abort');
        $this->assertContains('KEEPME', array_column($codes->allRows(), 'code'), 'existing data must survive a corrupt restore');
    }

    public function test_full_restore_round_trips_anonymized_and_pii_rows(): void
    {
        global $wpdb;
        $codes = new CodeRepository($wpdb);
        $requests = new RequestRepository($wpdb);
        // Anonymized row (as produced by retention cron): NULL name/email, hashes retained.
        $requests->createPending([
            'name' => null, 'email' => null, 'email_hash' => 'aeh', 'name_hash' => 'anh',
            'product' => 'standardbrief', 'token_hash' => 'anon-tok', 'ip_hash' => null, 'created_at' => '2026-01-01 00:00:00',
        ]);
        // PII row.
        $requests->createPending([
            'name' => 'Bob', 'email' => 'bob@example.test', 'email_hash' => 'beh', 'name_hash' => 'bnh',
            'product' => 'standardbrief', 'token_hash' => 'pii-tok', 'ip_hash' => null, 'created_at' => '2026-01-02 00:00:00',
        ]);

        $bundle = (new ExportService($codes, $requests, Settings::fromOption(), '1', 'https://src.test', '2026-06-25 00:00:00'))->bundle(null);
        $requests->deleteAll();
        $this->assertSame([], $requests->allRows());

        (new ImportService($codes, $requests))->importBundle($bundle, null, ImportService::MODE_FULL);

        $anon = $requests->findByTokenHash('anon-tok');
        $this->assertNotNull($anon);
        $this->assertNull($anon->name, 'anonymized row must stay anonymized (NULL name)');
        $this->assertNull($anon->email, 'anonymized row must stay anonymized (NULL email)');
        $this->assertSame('anh', $anon->name_hash, 'retained hash must round-trip');

        $pii = $requests->findByTokenHash('pii-tok');
        $this->assertSame('Bob', $pii->name, 'PII content restored losslessly');
        $this->assertSame('bob@example.test', $pii->email);
    }
}
