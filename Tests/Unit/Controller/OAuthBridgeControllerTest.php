<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Tests\Unit\Controller;

use MarekSkopal\MsMcpServer\Controller\OAuthBridgeController;
use MarekSkopal\MsMcpServer\OAuth\AuthorizeParamsValidator;
use MarekSkopal\MsMcpServer\OAuth\ClientRepository;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

#[CoversClass(OAuthBridgeController::class)]
final class OAuthBridgeControllerTest extends TestCase
{
    /** @var list<string> */
    private array $capturedBodies = [];

    /** @var array<string, string> */
    private array $capturedHeaders = [];

    private int $capturedStatusCode = 0;

    public function testRedirectsToAuthorizeWhenParamsValid(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://client.example/cb'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => 'challenge-value',
            'code_challenge_method' => 'S256',
            'state' => 'opaque-state',
        ]);

        $controller = $this->createController($clientRepository);
        $controller->indexAction($request);

        self::assertSame(302, $this->capturedStatusCode);
        $location = $this->capturedHeaders['Location'] ?? '';
        self::assertStringStartsWith('/mcp/oauth/authorize?', $location);
        self::assertStringContainsString('response_type=code', $location);
        self::assertStringContainsString('client_id=client-abc', $location);
        self::assertStringContainsString('redirect_uri=https%3A%2F%2Fclient.example%2Fcb', $location);
        self::assertStringContainsString('code_challenge=challenge-value', $location);
        self::assertStringContainsString('code_challenge_method=S256', $location);
        self::assertStringContainsString('state=opaque-state', $location);
    }

    public function testReturns400WhenParamsInvalid(): void
    {
        $clientRepository = $this->createStub(ClientRepository::class);
        $clientRepository->method('findByClientId')->willReturn([
            'client_id' => 'client-abc',
            'client_name' => 'Test',
            'redirect_uris' => ['https://client.example/cb'],
        ]);
        $clientRepository->method('validateRedirectUri')->willReturn(true);

        $request = $this->createStub(ServerRequestInterface::class);
        // Missing code_challenge
        $request->method('getQueryParams')->willReturn([
            'response_type' => 'code',
            'client_id' => 'client-abc',
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge_method' => 'S256',
        ]);

        $controller = $this->createController($clientRepository);
        $controller->indexAction($request);

        self::assertSame(400, $this->capturedStatusCode);
        $body = $this->capturedBodies[0] ?? '';
        self::assertStringContainsString('code_challenge is required', $body);
    }

    private function createController(ClientRepository $clientRepository): OAuthBridgeController
    {
        $stream = $this->createStub(StreamInterface::class);

        $streamFactory = $this->createStub(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturnCallback(
            function (string $content) use ($stream): StreamInterface {
                $this->capturedBodies[] = $content;

                return $stream;
            },
        );

        $response = $this->createStub(ResponseInterface::class);
        $response->method('withHeader')->willReturnCallback(
            function (string $name, $value) use (&$response): ResponseInterface {
                $this->capturedHeaders[$name] = is_string($value) ? $value : implode(', ', (array) $value);

                return $response;
            },
        );
        $response->method('withBody')->willReturn($response);

        $responseFactory = $this->createStub(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturnCallback(
            function (int $statusCode = 200) use ($response): ResponseInterface {
                $this->capturedStatusCode = $statusCode;

                return $response;
            },
        );

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn(['mcpBasePath' => '/mcp']);

        return new OAuthBridgeController(
            new McpPathProvider($extensionConfiguration),
            new AuthorizeParamsValidator($clientRepository),
            $responseFactory,
            $streamFactory,
        );
    }
}
