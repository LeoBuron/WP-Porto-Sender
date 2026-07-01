<?php // tests/unit/Postage/ProductCatalogTest.php
declare(strict_types=1);
namespace PortoSender\Tests\unit\Postage;
use PHPUnit\Framework\TestCase;
use PortoSender\Postage\ProductCatalog;

final class ProductCatalogTest extends TestCase
{
    public function test_known_products_and_labels(): void
    {
        $c = ProductCatalog::default();
        $this->assertSame('Standardbrief', $c->get('standardbrief')->label);
        $this->assertSame('Großbrief', $c->get('grossbrief')->label);
        $this->assertNull($c->get('nope'));
    }

    public function test_enabled_filters_to_requested_keys(): void
    {
        $c = ProductCatalog::default();
        $this->assertSame(['grossbrief'], array_keys($c->enabled(['grossbrief', 'nope'])));
    }
}
