<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\OAuth;

use MarekSkopal\MsMcpServer\OAuth\AuthorizeParamsValidator;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthorizeParamsValidator::class)]
final class AuthorizeParamsValidatorTest extends TestCase
{
    public function testRejectsMissingResponseType(): void
    {
        $validator = new AuthorizeParamsValidator($this->createStub(ClientRepository::class));

        self::assertSame('response_type must be "code"', $validator->validate([]));
    }

    public function testRejectsMissingClientId(): void
    {
        $validator = new AuthorizeParamsValidator($this->createStub(ClientRepository::class));

        self::assertSame(
            'client_id is required',
            $validator->validate(['response_type' => 'code']),
        );
    }

    public function testRejectsUnknownClientId(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn(null);

        $validator = new AuthorizeParamsValidator($clientRepository);

        self::assertSame(
            'Unknown client_id',
            $validator->validate(['response_type' => 'code', 'client_id' => 'mystery']),
        );
    }

    public function testRejectsMissingRedirectUri(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://x'],
        ]);

        $validator = new AuthorizeParamsValidator($clientRepository);

        self::assertSame(
            'redirect_uri is required',
            $validator->validate(['response_type' => 'code', 'client_id' => 'client-abc']),
        );
    }

    public function testRejectsInvalidRedirectUri(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://x'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(false);

        $validator = new AuthorizeParamsValidator($clientRepository);

        self::assertSame(
            'Invalid redirect_uri',
            $validator->validate([
                'response_type' => 'code',
                'client_id' => 'client-abc',
                'redirect_uri' => 'https://evil.example',
            ]),
        );
    }

    public function testRejectsNonS256ChallengeMethod(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://x'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $validator = new AuthorizeParamsValidator($clientRepository);

        self::assertSame(
            'code_challenge_method must be "S256"',
            $validator->validate([
                'response_type' => 'code',
                'client_id' => 'client-abc',
                'redirect_uri' => 'https://x',
                'code_challenge_method' => 'plain',
            ]),
        );
    }

    public function testRejectsMissingChallenge(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://x'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $validator = new AuthorizeParamsValidator($clientRepository);

        self::assertSame(
            'code_challenge is required',
            $validator->validate([
                'response_type' => 'code',
                'client_id' => 'client-abc',
                'redirect_uri' => 'https://x',
                'code_challenge_method' => 'S256',
            ]),
        );
    }

    public function testAcceptsValidParams(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://x'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $validator = new AuthorizeParamsValidator($clientRepository);

        self::assertNull($validator->validate([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://x',
            'code_challenge_method' => 'S256',
            'code_challenge' => 'challenge-value',
        ]));
    }
}
