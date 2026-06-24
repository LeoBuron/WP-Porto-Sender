<?php // tests/unit/Postage/ProductCatalogTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Postage;
use PHPUnit\Framework\TestCase;
use PortoSender\Postage\ProductCatalog;

final class ProductCatalogTest extends TestCase
{
    public function test_known_products_and_prices(): void
    {
        $c = ProductCatalog::default();
        $this->assertSame(95, $c->get('standardbrief')->valueCents);
        $this->assertSame(180, $c->get('grossbrief')->valueCents);
        $this->assertNull($c->get('nope'));
    }

    public function test_enabled_filters_to_requested_keys(): void
    {
        $c = ProductCatalog::default();
        $this->assertSame(['grossbrief'], array_keys($c->enabled(['grossbrief', 'nope'])));
    }
}
