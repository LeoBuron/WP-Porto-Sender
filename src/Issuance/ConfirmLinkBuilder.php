<?php
declare(strict_types=1);
namespace PortoSender\Issuance;
interface ConfirmLinkBuilder { public function build(string $token): string; }
