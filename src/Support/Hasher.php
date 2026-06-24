<?php
declare(strict_types=1);
namespace PortoSender\Support;

final class Hasher
{
    public function __construct(private string $salt) {}

    public function email(string $email): string { return $this->hash(strtolower(trim($email))); }

    public function name(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
        return $this->hash(mb_strtolower($normalized));
    }

    public function ip(string $ip): string { return $this->hash(trim($ip)); }

    public function token(string $token): string { return $this->hash($token); }

    private function hash(string $value): string
    {
        return hash('sha256', $this->salt . '|' . $value);
    }
}
