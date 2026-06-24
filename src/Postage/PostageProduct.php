<?php
declare(strict_types=1);
namespace PortoSender\Postage;

final class PostageProduct
{
    public function __construct(
        public readonly string $key,
        public readonly int $valueCents,
        public readonly string $label,
        public readonly string $limits,
    ) {}
}
