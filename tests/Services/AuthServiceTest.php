<?php

namespace Tests\Services;

use PHPUnit\Framework\TestCase;
use App\Services\AuthService;

class AuthServiceTest extends TestCase
{
    /** @var AuthService */
    private $authService;

    protected function setUp()
    {
        parent::setUp();
        // Use a secure key for testing
        $this->authService = new AuthService('abcdef1234567890abcdef1234567890');
    }

    public function testRejectsInsecureOrPlaceholderSecret()
    {
        $this->expectException(\RuntimeException::class);
        new AuthService('your_super_secret_key_here');
    }

    public function testPasswordHashAndVerification()
    {
        $password = 'TestPassword123!';
        $hash = $this->authService->hashPassword($password);

        $this->assertNotEmpty($hash);
        $this->assertTrue($this->authService->verifyPassword($password, $hash));
        $this->assertFalse($this->authService->verifyPassword('WrongPassword', $hash));
    }

    public function testLegacySha512Verification()
    {
        $password = 'legacy_sha512_pwd';
        $storedHash = hash('sha512', $password);

        $this->assertTrue($this->authService->verifyPassword($password, $storedHash));
    }

    public function testLegacyMd5Verification()
    {
        $password = 'legacy_md5_pwd';
        $storedHash = md5($password);

        $this->assertTrue($this->authService->verifyPassword($password, $storedHash));
    }

    public function testTokenGenerationAndDecoding()
    {
        $payload = [
            'email'  => 'hospital@lifebank.ng',
            'ref_id' => 42,
            'type'   => 'hospital'
        ];

        $token = $this->authService->generateToken($payload, 1);
        $this->assertNotEmpty($token);

        $decoded = $this->authService->decodeToken($token);
        $this->assertEquals('hospital@lifebank.ng', $decoded['email']);
        $this->assertEquals(42, $decoded['ref_id']);
        $this->assertEquals('hospital', $decoded['type']);
    }
}
