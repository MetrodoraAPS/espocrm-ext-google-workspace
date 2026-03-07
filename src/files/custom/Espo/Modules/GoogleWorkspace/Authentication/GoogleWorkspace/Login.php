<?php

namespace Espo\Modules\GoogleWorkspace\Authentication\GoogleWorkspace;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\Login as LoginInterface;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Core\ApplicationState;
use Espo\Core\Container;
use Espo\Modules\GoogleWorkspace\Services\GoogleSyncService;
use Espo\Modules\GoogleWorkspace\Jobs\SyncGoogle;

class Login implements LoginInterface
{
    private const GW_USERNAME = '**google-workspace';

    public function __construct(
        private Espo $espoLogin,
        private Config $config,
        private EntityManager $entityManager,
        private Log $log,
        private ApplicationState $applicationState,
        private GoogleSyncService $syncService,
        private Container $container
    ) {}

    public function login(Data $data, Request $request): Result
    {
        if ($data->getUsername() !== self::GW_USERNAME) {
            return $this->loginFallback($data, $request);
        }

        $code = $data->getPassword();

        if (!$code) {
            return Result::fail(FailReason::NO_PASSWORD);
        }

        return $this->loginWithCode($code);
    }

    private function loginWithCode(string $code): Result
    {
        $clientId = $this->config->get('googleWorkspaceClientId');
        $clientSecret = $this->config->get('googleWorkspaceClientSecret');
        $siteUrl = rtrim($this->config->get('siteUrl') ?? '', '/');
        $redirectUri = $siteUrl . '/oauth-callback.php';

        if (!$clientId || !$clientSecret) {
            $this->log->error("GoogleWorkspace: Client ID or Secret is missing.");
            return Result::fail(FailReason::DENIED);
        }

        $tokenResponse = $this->requestToken($clientId, $clientSecret, $code, $redirectUri);

        if (empty($tokenResponse->access_token)) {
            $this->log->error("GoogleWorkspace: No access token received.");
            return Result::fail(FailReason::DENIED);
        }

        $userInfo = $this->requestUserInfo($tokenResponse->access_token);

        if (empty($userInfo->email)) {
            $this->log->error("GoogleWorkspace: No email returned from Google UserInfo.");
            return Result::fail(FailReason::DENIED);
        }

        $hostedDomain = $this->config->get('googleWorkspaceHostedDomain');
        if (!empty($hostedDomain)) {
            $userDomain = $userInfo->hd ?? explode('@', $userInfo->email)[1] ?? '';
            if ($userDomain !== $hostedDomain) {
                $this->log->warning("GoogleWorkspace: Hosted domain mismatch. Expected '$hostedDomain', got '$userDomain'");
                return Result::fail(FailReason::DENIED);
            }
        }

        /** @var \Espo\Entities\User|null $user */
        $user = $this->entityManager->getRDBRepository(\Espo\Entities\User::ENTITY_TYPE)
            ->where(['emailAddress' => $userInfo->email])
            ->findOne();

        if (!$user) {
            if ($this->config->get('googleWorkspaceCreateUser')) {
                /** @var \Espo\Entities\User $user */
                $user = $this->entityManager->getNewEntity(\Espo\Entities\User::ENTITY_TYPE);
                $userName = explode('@', $userInfo->email)[0];
                $user->set('userName', $userName);
                $user->set('emailAddressData', [
                    (object)[
                        'emailAddress' => $userInfo->email,
                        'primary' => true
                    ]
                ]);
                $user->set('firstName', $userInfo->given_name ?? '');
                $user->set('lastName', $userInfo->family_name ?? '');
                $user->set('isActive', true);
                $this->entityManager->saveEntity($user);
            } else {
                $this->log->warning("GoogleWorkspace: User not found and auto-create is disabled. Email: " . $userInfo->email);
                return Result::fail(FailReason::USER_NOT_FOUND);
            }
        }

        if (!$user->isActive()) {
            return Result::fail(FailReason::DENIED);
        }

        if ($this->config->get('googleWorkspaceSyncOnLogin')) {
            try {
                // Temporary register user in container to satisfy hooks/audit
                // Using reflection because Container::set throws if already exists, 
                // and we need to be able to UNSET it afterwards to avoid "Service already set" error later.
                $reflection = new \ReflectionClass($this->container);
                $property = $reflection->getProperty('data');
                $property->setAccessible(true);
                $data = $property->getValue($this->container);
                
                $data['user'] = $user;
                $property->setValue($this->container, $data);

                try {
                    $syncJob = new SyncGoogle($this->config, $this->log, $this->syncService);
                    $syncJob->syncSingleUser($userInfo->email);
                } finally {
                    // CRITICAL: Unset 'user' service so Espo can set it session-wide normally after login
                    unset($data['user']);
                    $property->setValue($this->container, $data);
                }
            } catch (\Exception $e) {
                $this->log->error("GoogleWorkspace SSO: Error triggering single user sync on login: " . $e->getMessage());
            }
        }

        return Result::success($user)->withBypassSecondStep();
    }

    private function loginFallback(Data $data, Request $request): Result
    {
        if (!$data->getAuthToken() && !$this->config->get('googleWorkspaceAllowAdminFallback', true) && !$this->config->get('googleWorkspaceAllowRegularFallback', false)) {
            return Result::fail(FailReason::METHOD_NOT_ALLOWED);
        }

        if (!$data->getAuthToken() && $this->applicationState->isPortal()) {
            return Result::fail(FailReason::METHOD_NOT_ALLOWED);
        }

        $result = $this->espoLogin->login($data, $request);
        $user = $result->getUser();

        if (!$user) {
            return $result;
        }

        if ($data->getAuthToken()) {
            return $result;
        }

        if ($user->isAdmin() && !$this->config->get('googleWorkspaceAllowAdminFallback', true)) {
            return Result::fail(FailReason::METHOD_NOT_ALLOWED);
        }

        if ($user->isRegular() && !$user->isAdmin() && !$this->config->get('googleWorkspaceAllowRegularFallback', false)) {
            return Result::fail(FailReason::METHOD_NOT_ALLOWED);
        }

        return $result;
    }

    private function requestToken(string $clientId, string $clientSecret, string $code, string $redirectUri): ?\stdClass
    {
        $params = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri
        ];

        return $this->apiRequest('https://oauth2.googleapis.com/token', $params);
    }

    private function requestUserInfo(string $accessToken): ?\stdClass
    {
        return $this->apiRequest('https://openidconnect.googleapis.com/v1/userinfo', null, $accessToken);
    }

    /**
     * @param array<string, mixed>|null $postParams
     */
    private function apiRequest(string $url, ?array $postParams = null, ?string $bearerToken = null): ?\stdClass
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $headers = [];
        if ($bearerToken) {
            $headers[] = "Authorization: Bearer {$bearerToken}";
        }

        if ($postParams !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postParams));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            $this->log->error("GoogleWorkspace API Error ($url): $response");
            return null;
        }

        if (!is_string($response)) {
            return null;
        }

        return Json::decode($response);
    }
}
