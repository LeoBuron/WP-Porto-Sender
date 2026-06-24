<?php
declare(strict_types=1);
namespace PortoSender\Issuance;

final class UrlConfirmLinkBuilder implements ConfirmLinkBuilder
{
    public function build(string $token): string
    {
        return add_query_arg('porto_confirm', rawurlencode($token), home_url('/'));
    }
}
