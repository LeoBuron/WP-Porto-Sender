<?php
declare(strict_types=1);
namespace PortoSender\Frontend;

final class BlockRegistrar
{
    public function __construct(private RequestForm $form) {}

    public function register(): void
    {
        register_block_type(dirname(__DIR__, 2) . '/build/block', [
            'render_callback' => fn(array $attributes, string $content): string => $this->form->render($attributes),
        ]);
    }
}
