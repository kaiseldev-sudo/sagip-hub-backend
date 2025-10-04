<?php
declare(strict_types=1);

namespace ReliefHub\Backend;

final class Util
{
    public static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    public static function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    public static function generateToken(int $length = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
    }

    public static function sha256(string $value): string
    {
        return hash('sha256', $value, true);
    }

    public static function ipToBinary(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        $bin = @inet_pton($ip);
        return $bin === false ? null : $bin;
    }
}


