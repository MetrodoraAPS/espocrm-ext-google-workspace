<?php

namespace Espo\Modules\GoogleWorkspace\Authentication\GoogleWorkspace;

use Espo\Core\Api\Request;
use Espo\Core\Authentication\Login as LoginInterface;
use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\ORM\EntityManagerProxy;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Core\ApplicationState;

class Login implements LoginInterface
{
    private const GW_USERNAME = '**google-workspace';

    public function __construct(
        private Espo $espoLogin,
        private Config $config,
        private EntityManagerProxy $entityManager,
        private Log $log,
        private ApplicationState $applicationState
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
        $user = $this->entityManager->getRepository('User')
            ->where(['emailAddress' => $userInfo->email])
            ->findOne();

        if (!$user) {
            if ($this->config->get('googleWorkspaceCreateUser')) {
                /** @var \Espo\Entities\User $user */
                $user = $this->entityManager->getEntity('User');
                $user->set('userName', $userInfo->email);
                $user->set('emailAddress', $userInfo->email);
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
            return Result::fail(FailReason::INACTIVE_USER);
        }

        if ($this->config->get('googleWorkspaceSyncAvatar') && !empty($userInfo->picture)) {
            $this->syncAvatar($user, $userInfo->picture);
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

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            $this->log->error("GoogleWorkspace: Bad token request. Response: $response");
            return null;
        }

        return Json::decode($response);
    }

    private function requestUserInfo(string $accessToken): ?\stdClass
    {
        $ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}"
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            $this->log->error("GoogleWorkspace: Bad userinfo request. Response: $response");
            return null;
        }

        return Json::decode($response);
    }

    private function syncAvatar(\Espo\Entities\User $user, string $pictureUrl): void
    {
        $ch = curl_init($pictureUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $imageContent = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400 || empty($imageContent)) {
            $this->log->error("GoogleWorkspace: Failed to download user avatar from $pictureUrl");
            return;
        }

        $avatarId = $user->get('avatarId');
        if ($avatarId) {
            $oldAttachment = $this->entityManager->getEntityById('Attachment', $avatarId);
            if ($oldAttachment && $oldAttachment->get('size') === strlen($imageContent)) {
                return; // Picture probably didn't change (heuristic to avoid unnecessary database operations)
            }
        }

        /** @var \Espo\Entities\Attachment $attachment */
        $attachment = $this->entityManager->getNewEntity('Attachment');
        $attachment->set('name', 'google_avatar.jpg');
        $attachment->set('type', 'image/jpeg');
        $attachment->set('role', 'Attachment');
        $attachment->set('global', true);
        $attachment->set('contents', $imageContent);

        $this->entityManager->saveEntity($attachment, ['skipHooks' => true]);

        $user->set('avatarId', $attachment->getId());
        // Temporarily avoid tracking changes just to update the avatar ID
        $this->entityManager->saveEntity($user, ['silent' => true, 'skipHooks' => true]);

        if (isset($oldAttachment) && $oldAttachment) {
            $this->entityManager->getRDBRepository('Attachment')->remove($oldAttachment); // Clean up the old avatar to free up space
        }
    }
}
