<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\Controller;

use MarekSkopal\MsMcpServer\OAuth\AuthorizeParamsValidator;
use MarekSkopal\MsMcpServer\Service\McpPathProvider;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use const JSON_THROW_ON_ERROR;

/**
 * Bridges TYPO3's `RouteRedirect` post-login bounce back to the frontend-mounted
 * `/mcp/oauth/authorize` endpoint. After the user signs into the TYPO3 backend,
 * `BackendController::mainAction` resolves a `RouteRedirect` pointing at this
 * controller; we re-validate the OAuth params and 302 the browser to the
 * authorize endpoint where the consent screen is rendered.
 */
#[AsController]
readonly class OAuthBridgeController
{
    public function __construct(
        private McpPathProvider $pathProvider,
        private AuthorizeParamsValidator $validator,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $params */
        $params = $request->getQueryParams();

        $error = $this->validator->validate($params);
        if ($error !== null) {
            $body = $this->streamFactory->createStream(json_encode(
                ['error' => 'invalid_request', 'error_description' => $error],
                JSON_THROW_ON_ERROR,
            ));

            return $this->responseFactory
                ->createResponse(400)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($body);
        }

        $authorizeQuery = http_build_query([
            'response_type' => 'code',
            'client_id' => is_string($params['client_id'] ?? null) ? $params['client_id'] : '',
            'redirect_uri' => is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '',
            'code_challenge' => is_string($params['code_challenge'] ?? null) ? $params['code_challenge'] : '',
            'code_challenge_method' => is_string($params['code_challenge_method'] ?? null) ? $params['code_challenge_method'] : '',
            'state' => is_string($params['state'] ?? null) ? $params['state'] : '',
            'scope' => is_string($params['scope'] ?? null) ? $params['scope'] : '',
        ]);

        $location = $this->pathProvider->getAuthorizePath() . '?' . $authorizeQuery;

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $location);
    }
}
