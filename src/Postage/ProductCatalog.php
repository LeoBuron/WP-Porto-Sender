<?php
declare(strict_types=1);
namespace PortoSender\Postage;

final class ProductCatalog
{
    /** @param array<string,PostageProduct> $products */
    public function __construct(private array $products) {}

    public static function default(): self
    {
        return new self([
            'standardbrief' => new PostageProduct('standardbrief', 'Standardbrief', 'bis 20 g, gefaltet (z. B. 3 Seiten)'),
            'grossbrief' => new PostageProduct('grossbrief', 'Großbrief', 'A4 flach, bis 500 g'),
        ]);
    }

    /** @return array<string,PostageProduct> */
    public function all(): array { return $this->products; }

    public function get(string $key): ?PostageProduct { return $this->products[$key] ?? null; }

    /** @return array<string,PostageProduct> */
    public function enabled(array $keys): array
    {
        return array_intersect_key($this->products, array_flip($keys));
    }
}
