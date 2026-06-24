<?php
declare(strict_types=1);

namespace PortoSender\Tests\unit;

use PHPUnit\Framework\TestCase;
use PortoSender\Plugin;

final class PluginBootstrapTest extends TestCase
{
    public function test_version_is_semver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Plugin::version());
    }
}
