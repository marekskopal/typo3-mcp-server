<?php

declare(strict_types=1);

namespace MarekSkopal\MsMcpServer\OAuth;

readonly class AuthorizeParamsValidator
{
    public function __construct(private ClientRepository $clientRepository)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return string|null Error description, or null if the params are valid.
     */
    public function validate(array $params): ?string
    {
        if (($params['response_type'] ?? '') !== 'code') {
            return 'response_type must be "code"';
        }

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : '';
        if ($clientId === '') {
            return 'client_id is required';
        }

        $client = $this->clientRepository->findByClientId($clientId);
        if ($client === null) {
            return 'Unknown client_id';
        }

        $redirectUri = is_string($params['redirect_uri'] ?? null) ? $params['redirect_uri'] : '';
        if ($redirectUri === '') {
            return 'redirect_uri is required';
        }

        if (!$this->clientRepository->validateRedirectUri($clientId, $redirectUri)) {
            return 'Invalid redirect_uri';
        }

        if (($params['code_challenge_method'] ?? '') !== 'S256') {
            return 'code_challenge_method must be "S256"';
        }

        if (($params['code_challenge'] ?? '') === '') {
            return 'code_challenge is required';
        }

        return null;
    }
}
