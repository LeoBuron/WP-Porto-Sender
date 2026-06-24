<?php
declare(strict_types=1);
namespace PortoSender\Limiting;

use PortoSender\Requests\RequestStore;

final class RequestLimiter
{
    public function __construct(private RequestStore $requests) {}

    public function allow(string $mode, string $emailHash, string $nameHash): bool
    {
        return match ($mode) {
            'none' => true,
            'email' => !$this->requests->hasPriorRequest($emailHash, null),
            'name' => !$this->requests->hasPriorRequest(null, $nameHash),
            default => !$this->requests->hasPriorRequest($emailHash, $nameHash), // name_or_email
        };
    }
}
