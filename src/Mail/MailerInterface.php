<?php
declare(strict_types=1);
namespace PortoSender\Mail;

use PortoSender\Postage\PostageProduct;

interface MailerInterface
{
    public function sendConfirmation(string $email, string $name, string $confirmUrl): bool;
    public function sendDelivery(string $email, string $name, string $code, PostageProduct $product): bool;
    public function sendLowStock(string $to, string $productLabel, int $remaining): bool;
    public function sendOutOfStock(string $to, string $productLabel): bool;
    /**
     * @param array{product_label:string,count:int,remaining:int,
     *     requesters?:list<array{name:string,email:string}>,name?:?string,email?:?string} $data
     *     Prefer `requesters` (the batch of claimants); `name`/`email` is the legacy single-claimant shape.
     */
    public function sendAdminNotification(string $to, array $data): bool;
}
