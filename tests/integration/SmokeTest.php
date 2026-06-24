<?php
declare(strict_types=1);

namespace PortoSender\Tests\integration;

use WP_UnitTestCase;
use PortoSender\Plugin;

final class SmokeTest extends WP_UnitTestCase
{
    public function test_wordpress_and_plugin_are_loaded(): void
    {
        $this->assertTrue(function_exists('wp_insert_post'));
        $this->assertSame('0.1.0', Plugin::version());
    }
}
