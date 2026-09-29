<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;

class AuthService
{
    /** @var string */
    private $secretKey;

    public function __construct(?string $secretKey = null)
    {
        $key = $secretKey ?? $_ENV['JWT_SECRET'] ?? $_ENV['secretkey'] ?? null;
        if (empty($key) || strlen($key) < 16 || $key === 'your_super_secret_key_here') {
            throw new \RuntimeException('JWT_SECRET must be configured with a secure key (minimum 16 characters) and cannot use the default placeholder.');
        }
        $this->secretKey = $key;
    }

    /**
     * Generate a JWT token.
     */
    public function generateToken(array $data, int $expiryDays = 7): string
    {
        $issuedAt = time();
        $expire = $issuedAt + (60 * 60 * 24 * $expiryDays);

        $payload = array_merge([
            'iat' => $issuedAt,
            'exp' => $expire
        ], $data);

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    /**
     * Decode and validate a JWT token.
     */
    public function decodeToken(string $token): array
    {
        $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
        return (array)$decoded;
    }

    /**
     * Verify password supporting password_hash (bcrypt/argon2), SHA-512, and legacy MD5 using timing-safe comparisons.
     */
    public function verifyPassword(string $password, string $storedHash): bool
    {
        if (password_verify($password, $storedHash)) {
            return true;
        }

        if (hash_equals(hash('sha512', $password), $storedHash)) {
            return true;
        }

        if (hash_equals(md5($password), $storedHash)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a stored password hash requires rehashing to a modern algorithm.
     */
    public function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_DEFAULT);
    }

    /**
     * Hash a password securely using PHP's native password_hash.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
