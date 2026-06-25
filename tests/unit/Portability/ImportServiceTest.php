<?php declare(strict_types=1);
namespace PortoSender\Tests\unit\Portability;

use PortoSender\Tests\unit\WpUnitTestCase;
use Brain\Monkey\Functions;
use Mockery;
use PortoSender\Portability\ImportService;
use PortoSender\Portability\BundleSerializer;
use PortoSender\Portability\BundleCrypto;
use PortoSender\Inventory\CodeStore;
use PortoSender\Requests\RequestStore;

final class ImportServiceTest extends WpUnitTestCase
{
    /** @var array<int,string> */
    private array $log = [];

    /** @param array<string,mixed> $overrides */
    private function bundleJson(array $overrides = []): string
    {
        return (new BundleSerializer())->build(
            $overrides['settings'] ?? ['hash_salt' => 'SRC'],
            $overrides['codes'] ?? [['id' => 1, 'code' => 'AB12']],
            $overrides['requests'] ?? [['id' => 7, 'token_hash' => 't']],
            '1',
            'https://src.test',
            '2026-06-25 00:00:00'
        );
    }

    private function service(): ImportService
    {
        $this->log = [];
        $codeStore = Mockery::mock(CodeStore::class);
        $reqStore = Mockery::mock(RequestStore::class);
        $codeStore->shouldReceive('deleteAll')->andReturnUsing(function (): int { $this->log[] = 'codes.deleteAll'; return 0; })->byDefault();
        $reqStore->shouldReceive('deleteAll')->andReturnUsing(function (): int { $this->log[] = 'requests.deleteAll'; return 0; })->byDefault();
        $codeStore->shouldReceive('insertRows')->andReturnUsing(function (array $r): int { $this->log[] = 'codes.insertRows'; return count($r); })->byDefault();
        $reqStore->shouldReceive('insertRows')->andReturnUsing(function (array $r): int { $this->log[] = 'requests.insertRows'; return count($r); })->byDefault();
        $this->codeStore = $codeStore;
        $this->reqStore = $reqStore;
        return new ImportService($codeStore, $reqStore);
    }

    private $codeStore;
    private $reqStore;

    public function test_malformed_bundle_aborts_with_no_side_effects(): void
    {
        Functions\expect('update_option')->never();
        $svc = $this->service();
        $this->codeStore->shouldNotReceive('deleteAll');
        $this->codeStore->shouldNotReceive('insertRows');
        $this->reqStore->shouldNotReceive('deleteAll');
        $this->reqStore->shouldNotReceive('insertRows');

        $this->expectException(\RuntimeException::class);
        $svc->importBundle('{bad json', null, ImportService::MODE_FULL);
    }

    public function test_full_restore_runs_reset_then_insert_then_settings_in_order(): void
    {
        Functions\when('update_option')->alias(function ($key, $val): bool { $this->log[] = "update_option:$key"; return true; });
        $svc = $this->service();

        $result = $svc->importBundle($this->bundleJson(), null, ImportService::MODE_FULL);

        $this->assertSame('full_restore', $result['mode']);
        $this->assertSame(1, $result['codes']);
        $this->assertSame(1, $result['requests']);
        $this->assertSame([], $result['warnings']);
        $this->assertSame([
            'codes.deleteAll', 'requests.deleteAll',
            'codes.insertRows', 'requests.insertRows',
            'update_option:porto_sender_settings', 'update_option:porto_sender_schema_version',
        ], $this->log);
    }

    public function test_full_restore_drops_unknown_settings_keys_but_keeps_known(): void
    {
        $captured = null;
        Functions\when('update_option')->alias(function ($key, $val) use (&$captured): bool {
            if ($key === \PortoSender\Settings\Settings::OPTION) { $captured = $val; }
            return true;
        });
        $svc = $this->service();

        $svc->importBundle(
            $this->bundleJson(['settings' => ['hash_salt' => 'SRC', 'evil_key' => 'x', 'alert_email' => 'a@b.test']]),
            null,
            ImportService::MODE_FULL
        );

        $this->assertIsArray($captured);
        $this->assertArrayNotHasKey('evil_key', $captured);     // unknown key dropped
        $this->assertSame('SRC', $captured['hash_salt']);        // restored secret preserved
        $this->assertSame('a@b.test', $captured['alert_email']); // known key preserved
        $this->assertArrayHasKey('owner_address', $captured);    // defaults filled in
    }

    public function test_data_merge_inserts_without_clearing_or_overwriting_settings(): void
    {
        Functions\expect('update_option')->never();
        $svc = $this->service();
        $this->codeStore->shouldNotReceive('deleteAll');
        $this->reqStore->shouldNotReceive('deleteAll');

        $result = $svc->importBundle($this->bundleJson(), null, ImportService::MODE_MERGE);

        $this->assertSame('data_merge', $result['mode']);
        $this->assertSame(1, $result['codes']);
        $this->assertSame(1, $result['requests']); // merge inserts requests too (regression guard)
        // Warnings are translation-free CODES (the WP layer renders the text).
        $this->assertContains(ImportService::WARN_SALT_MISMATCH, array_column($result['warnings'], 'code'));
    }

    public function test_data_merge_warns_when_rows_are_skipped_on_collision(): void
    {
        $svc = $this->service();
        // 0 of 1 codes inserted (id/code collision with existing rows).
        $this->codeStore->shouldReceive('insertRows')->andReturn(0);

        $result = $svc->importBundle($this->bundleJson(), null, ImportService::MODE_MERGE);

        $this->assertSame(0, $result['codes']);
        $skipped = array_values(array_filter($result['warnings'], fn ($w) => $w['code'] === ImportService::WARN_ROWS_SKIPPED));
        $this->assertCount(1, $skipped);
        $this->assertSame(1, $skipped[0]['count']); // 1 code dropped on collision
    }

    public function test_full_restore_aborts_on_non_array_codes_without_touching_db(): void
    {
        Functions\expect('update_option')->never();
        $svc = $this->service();
        $this->codeStore->shouldNotReceive('deleteAll');
        $this->reqStore->shouldNotReceive('deleteAll');

        // Structurally valid bundle (keys present, format_version ok) but codes is a scalar.
        $bad = json_encode([
            'format_version' => 1, 'schema_version' => '1',
            'settings' => ['hash_salt' => 'x'], 'codes' => 'oops', 'requests' => [],
        ]);
        $this->expectException(\RuntimeException::class);
        $svc->importBundle((string) $bad, null, ImportService::MODE_FULL);
    }

    public function test_full_restore_aborts_on_too_new_schema_version_without_touching_db(): void
    {
        Functions\expect('update_option')->never();
        $svc = $this->service();
        $this->codeStore->shouldNotReceive('deleteAll');
        $this->reqStore->shouldNotReceive('deleteAll');

        $tooNew = (new BundleSerializer())->build(['hash_salt' => 'x'], [], [], '999', 'https://src.test', '2026-06-25 00:00:00');
        $this->expectException(\RuntimeException::class);
        $svc->importBundle($tooNew, null, ImportService::MODE_FULL);
    }

    public function test_encrypted_bundle_without_passphrase_throws_before_side_effects(): void
    {
        if (!BundleCrypto::available()) {
            $this->markTestSkipped('ext-sodium not available');
        }
        Functions\expect('update_option')->never();
        $enc = (new BundleCrypto())->encrypt($this->bundleJson(), 'pw');
        $svc = $this->service();
        $this->codeStore->shouldNotReceive('deleteAll');

        $this->expectException(\RuntimeException::class);
        $svc->importBundle($enc, null, ImportService::MODE_FULL);
    }
}
