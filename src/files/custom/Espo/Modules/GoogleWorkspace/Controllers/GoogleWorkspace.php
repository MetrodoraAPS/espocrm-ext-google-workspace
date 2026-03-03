<?php

namespace Espo\Modules\GoogleWorkspace\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;

class GoogleWorkspace
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function getActionAuthorizationData(Request $request, Response $response): void
    {
        $siteUrl = rtrim($this->config->get('siteUrl') ?? '', '/');

        $data = [
            'clientId' => $this->config->get('googleWorkspaceClientId'),
            'redirectUri' => $siteUrl . '/oauth-callback.php',
            'endpoint' => 'https://accounts.google.com/o/oauth2/auth',
            'scopes' => ['openid', 'email', 'profile'],
            'hd' => $this->config->get('googleWorkspaceHostedDomain')
        ];

        $response->writeBody(Json::encode($data));
    }
}
