<?php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Inventory;
use Mockery;
use Brain\Monkey\Functions;
use PortoSender\Tests\unit\WpUnitTestCase;
use PortoSender\Inventory\StockAlerter;
use PortoSender\Inventory\CodeStore;
use PortoSender\Mail\MailerInterface;
use PortoSender\Settings\Settings;
use PortoSender\Postage\ProductCatalog;
use PortoSender\Support\Clock;

final class StockAlerterTest extends WpUnitTestCase
{
    private array $flags;
    protected function setUp(): void
    {
        parent::setUp();
        $this->flags = [];
        Functions\when('get_option')->alias(fn($k, $d = false) => $this->flags[$k] ?? $d);
        Functions\when('update_option')->alias(function ($k, $v) { $this->flags[$k] = $v; return true; });
        Functions\when('delete_option')->alias(function ($k) { unset($this->flags[$k]); return true; });
    }

    private function alerter(CodeStore $codes, MailerInterface $mailer): StockAlerter
    {
        $clock = Mockery::mock(Clock::class);
        $clock->shouldReceive('now')->andReturn(new \DateTimeImmutable('2026-06-24 10:00:00'));
        $settings = new Settings(['enabled_products' => ['grossbrief'], 'alert_email' => 'owner@e.de', 'default_low_stock' => 5]);
        return new StockAlerter($codes, $settings, $mailer, ProductCatalog::default(), $clock);
    }

    public function test_sends_low_stock_once_then_debounces(): void
    {
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('availableCount')->andReturn(3);
        $mailer = Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendLowStock')->once()->andReturn(true); // only once across two evaluates
        $a = $this->alerter($codes, $mailer);
        $a->evaluate();
        $a->evaluate();
        $this->assertSame('low', $this->flags['porto_sender_lowstock_grossbrief']);
    }

    public function test_out_of_stock_and_recovery(): void
    {
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('availableCount')->andReturn(0, 9); // empty then refilled
        $mailer = Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendOutOfStock')->once()->andReturn(true);
        $a = $this->alerter($codes, $mailer);
        $a->evaluate(); // out
        $this->assertSame('out', $this->flags['porto_sender_lowstock_grossbrief']);
        $a->evaluate(); // recovered -> flag cleared
        $this->assertArrayNotHasKey('porto_sender_lowstock_grossbrief', $this->flags);
    }

    public function test_partial_restock_rearms_so_out_of_stock_refires(): void
    {
        // out -> partial restock into the low band (re-arm to 'low', no duplicate alert)
        // -> deplete back to 0 -> out-of-stock must fire AGAIN.
        $codes = Mockery::mock(CodeStore::class);
        $codes->shouldReceive('availableCount')->andReturn(0, 3, 0); // empty, partial (<=threshold 5), empty again
        $mailer = Mockery::mock(MailerInterface::class);
        $mailer->shouldReceive('sendOutOfStock')->twice()->andReturn(true); // fires on each depletion to 0
        $mailer->shouldNotReceive('sendLowStock'); // silent re-arm, no low-stock alert on the restock
        $a = $this->alerter($codes, $mailer);

        $a->evaluate(); // out
        $this->assertSame('out', $this->flags['porto_sender_lowstock_grossbrief']);
        $a->evaluate(); // partial restock -> re-armed to 'low'
        $this->assertSame('low', $this->flags['porto_sender_lowstock_grossbrief']);
        $a->evaluate(); // depleted again -> out-of-stock re-fires
        $this->assertSame('out', $this->flags['porto_sender_lowstock_grossbrief']);
    }
}
