<?php
declare(strict_types=1);
namespace PortoSender\Support;
interface Clock { public function now(): \DateTimeImmutable; }
