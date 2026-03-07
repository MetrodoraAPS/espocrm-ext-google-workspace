<?php

namespace Espo\Modules\GoogleWorkspace\Authentication\GoogleWorkspace;

use Espo\Core\Authentication\AuthToken\AuthToken;
use Espo\Core\Authentication\Logout as LogoutInterface;
use Espo\Core\Authentication\Logout\Params;
use Espo\Core\Authentication\Logout\Result;
use Espo\Core\Utils\Config;

class Logout implements LogoutInterface
{
    public function __construct(
        private Config $config
    ) {}

    public function logout(AuthToken $authToken, Params $params): Result
    {
        $siteUrl = rtrim($this->config->get('siteUrl') ?? '', '/');
        
        // Force the frontend to perform a hard reload to clear the authenticated cached configuration 
        // and fetch the fresh unauthenticated config which contains the Google Login handler data.
        return Result::create()->withRedirectUrl($siteUrl . '/?_hash=logout');
    }
}
