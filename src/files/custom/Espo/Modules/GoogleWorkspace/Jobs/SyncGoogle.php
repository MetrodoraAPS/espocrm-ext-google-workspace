<?php

namespace Espo\Modules\GoogleWorkspace\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Log;
use Espo\Modules\GoogleWorkspace\Services\GoogleSyncService;

class SyncGoogle implements Job
{
    public function __construct(
        private Config $config,
        private Log $log,
        private GoogleSyncService $syncService
    ) {}

    public function run(?\Espo\Core\Job\Job\Data $data = null): void
    {
        if (!$this->config->get('googleWorkspaceEnableSync')) {
            return;
        }

        if (!$this->shouldRunBasedOnFrequency()) {
            return;
        }

        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            return;
        }

        $this->syncUsers($accessToken);

        if ($this->config->get('googleWorkspaceSyncGroups')) {
            $this->syncGroups($accessToken);
        }

        $this->config->set('googleWorkspaceLastSyncTime', time());
        $this->config->save();
    }

    protected function shouldRunBasedOnFrequency(): bool
    {
        $frequency = $this->config->get('googleWorkspaceSyncFrequency', '1_hour');
        $lastSync = $this->config->get('googleWorkspaceLastSyncTime', 0);

        if ($frequency === '15_minutes') {
            return true;
        }

        $freqMap = [
            '1_hour' => 3600,
            '2_hours' => 7200,
            '12_hours' => 12 * 3600,
            '24_hours' => 24 * 3600,
        ];

        // Give a 5-minute buffer to avoid cron timing issues
        $seconds = ($freqMap[$frequency] ?? 3600) - 300;

        return (time() - $lastSync) >= $seconds;
    }

    protected function getAccessToken(): ?string
    {
        $adminEmail = $this->config->get('googleWorkspaceAdminEmail');
        $serviceAccountJson = $this->config->get('googleWorkspaceServiceAccount');

        if (!$adminEmail || !$serviceAccountJson) {
            $this->log->info("GoogleWorkspace Sync: Missing Admin Email or Service Account.");
            return null;
        }
        
        $sa = Json::decode($serviceAccountJson);
        if (empty($sa->client_email) || empty($sa->private_key)) {
            $this->log->error("GoogleWorkspace Sync: Invalid Service Account JSON.");
            return null;
        }

        $now = time();
        $header = Json::encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $claim = Json::encode([
            'iss' => $sa->client_email,
            'sub' => $adminEmail,
            'scope' => 'https://www.googleapis.com/auth/admin.directory.user.readonly https://www.googleapis.com/auth/admin.directory.group.readonly https://www.googleapis.com/auth/admin.directory.group.member.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlClaim = $this->base64UrlEncode($claim);
        $signature = '';
        openssl_sign($base64UrlHeader . "." . $base64UrlClaim, $signature, $sa->private_key, 'SHA256');
        $base64UrlSignature = $this->base64UrlEncode($signature);

        $jwt = $base64UrlHeader . "." . $base64UrlClaim . "." . $base64UrlSignature;

        $data = $this->apiRequest('https://oauth2.googleapis.com/token', null, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]);

        return $data->access_token ?? null;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * @param array<string, mixed>|null $postData
     */
    private function apiRequest(string $url, ?string $accessToken = null, ?array $postData = null): ?object
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            $headers = [];
            if ($accessToken) {
                $headers[] = "Authorization: Bearer $accessToken";
            }
            if ($postData !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
                // Implicitly sends application/x-www-form-urlencoded
            }

            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }

            $response = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($status === 429) {
                $attempt++;
                if ($attempt < $maxRetries) {
                    $this->log->warning("GoogleWorkspace API Rate Limit (429) on $url. Retrying... (Attempt $attempt of $maxRetries)");
                    sleep(2);
                    continue;
                }
            }

            if (!$response) {
                return null;
            }

            try {
                if (!is_string($response)) {
                    return null;
                }
                $data = Json::decode($response);
                if ($status >= 400 && !empty($data->error)) {
                    $this->log->error("GoogleWorkspace API Error ($url): " . Json::encode($data->error));
                }
                return (object) $data;
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return \Generator<object>
     */
    private function fetchPaginated(string $baseUrl, string $accessToken): \Generator
    {
        $pageToken = null;
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        do {
            $url = $baseUrl;
            if ($pageToken) {
                $url .= $separator . 'pageToken=' . urlencode($pageToken);
            }

            $data = $this->apiRequest($url, $accessToken);
            if (!$data || !empty($data->error)) {
                break;
            }

            yield $data;

            $pageToken = $data->nextPageToken ?? null;
        } while ($pageToken);
    }

    protected function syncUsers(string $accessToken): void
    {
        $hostedDomain = $this->config->get('googleWorkspaceHostedDomain');
        $queryParam = $hostedDomain ? 'domain=' . urlencode($hostedDomain) : 'customer=my_customer';

        $syncGroupsConfig = $this->config->get('googleWorkspaceUserWhitelistGroups');
        $allowedEmails = null;
        
        if (!empty($syncGroupsConfig) && is_array($syncGroupsConfig)) {
            $allowedEmails = [];
            $groups = array_filter(array_map('trim', $syncGroupsConfig));
            foreach ($groups as $groupEmail) {
                $members = $this->fetchGroupMembers($accessToken, urlencode($groupEmail));
                foreach ($members as $email) {
                    $allowedEmails[strtolower($email)] = true;
                }
            }
        }

        $baseUrl = 'https://admin.googleapis.com/admin/directory/v1/users?' . $queryParam . '&projection=full';
        
        $adminGroupEmail = $this->config->get('googleWorkspaceAdminGroup');
        $adminEmails = [];
        if (!empty($adminGroupEmail)) {
            $adminEmails = array_flip(array_map('strtolower', $this->fetchGroupMembers($accessToken, urlencode($adminGroupEmail))));
        }

        foreach ($this->fetchPaginated($baseUrl, $accessToken) as $data) {
            if (empty($data->users)) continue;

            foreach ($data->users as $gUser) {
                $userEmail = strtolower($gUser->primaryEmail ?? '');
                
                if (str_ends_with($userEmail, 'test-google-a.com')) {
                    continue;
                }

                if ($allowedEmails !== null) {
                    if (empty($userEmail) || !isset($allowedEmails[$userEmail])) {
                        continue;
                    }
                }

                try {
                    $isAdmin = isset($adminEmails[$userEmail]);
                    $this->processGoogleUser($gUser, $accessToken, $isAdmin);
                } catch (\Exception $e) {
                    $this->log->error("GoogleWorkspace Sync: Failed to sync user {$gUser->primaryEmail}: " . $e->getMessage());
                }
            }
        }
    }

    private function processGoogleUser(object $gUser, string $accessToken, ?bool $isAdmin = null): void
    {
        $syncAvatar = $this->config->get('googleWorkspaceSyncAvatar');
        $syncEmails = $this->config->get('googleWorkspaceSyncEmails', true);
        $syncNames = $this->config->get('googleWorkspaceSyncNames', true);
        $syncPhones = $this->config->get('googleWorkspaceSyncPhones', true);
        $syncActive = $this->config->get('googleWorkspaceSyncActive', true);

        $emails = [];
        $seenEmails = [];
        $addEmail = function(string $addr, bool $isPrimary) use (&$emails, &$seenEmails) {
            $addr = strtolower(trim($addr));
            if (empty($addr) || isset($seenEmails[$addr])) return;
            if (str_ends_with($addr, 'test-google-a.com')) return;
            $seenEmails[$addr] = true;
            $emails[] = ['value' => $addr, 'primary' => $isPrimary];
        };

        $addEmail($gUser->primaryEmail ?? '', true);

        if ($syncEmails) {
            if (!empty($gUser->emails) && is_array($gUser->emails)) {
                foreach ($gUser->emails as $gEmail) {
                    $addEmail($gEmail->address ?? '', !empty($gEmail->primary));
                }
            }
        }

        $phones = null;
        if ($syncPhones) {
            $phones = [];
            if (!empty($gUser->phones) && is_array($gUser->phones)) {
                foreach ($gUser->phones as $gPhone) {
                    if (!empty($gPhone->value)) {
                        $phones[] = [
                            'value' => $gPhone->value,
                            'type' => $gPhone->type ?? 'work',
                            'primary' => !empty($gPhone->primary)
                        ];
                    }
                }
            }
        }

        $photoData = null;
        if ($syncAvatar) {
            $photoUrl = 'https://admin.googleapis.com/admin/directory/v1/users/' . urlencode($gUser->primaryEmail ?? '') . '/photos/thumbnail';
            $photoJson = $this->apiRequest($photoUrl, $accessToken);

            if ($photoJson && empty($photoJson->error) && !empty($photoJson->photoData)) {
                $base64Data = str_replace(['-', '_'], ['+', '/'], $photoJson->photoData);
                $photoData = [
                    'contents' => base64_decode($base64Data),
                    'mimeType' => $photoJson->mimeType ?? 'image/jpeg',
                ];
            }
        }

        $payload = [
            'userName' => $gUser->primaryEmail ?? '',
            'emails' => $emails,
        ];

        if ($syncActive) {
            $payload['active'] = !($gUser->suspended ?? false);
        }

        if ($isAdmin !== null) {
            $payload['isAdmin'] = $isAdmin;
        }

        if ($syncNames) {
            $payload['name'] = [
                'givenName' => $gUser->name->givenName ?? '',
                'familyName' => $gUser->name->familyName ?? ''
            ];
        }

        if ($syncPhones) {
            $payload['phones'] = $phones;
        }

        if ($syncAvatar) {
            $payload['photo'] = $photoData;
        }

        $this->syncService->syncUser($payload);
    }


    protected function syncGroups(string $accessToken): void
    {
        $hostedDomain = $this->config->get('googleWorkspaceHostedDomain');
        $queryParam = $hostedDomain ? 'domain=' . urlencode($hostedDomain) : 'customer=my_customer';
        $baseUrl = 'https://admin.googleapis.com/admin/directory/v1/groups?' . $queryParam;

        foreach ($this->fetchPaginated($baseUrl, $accessToken) as $data) {
            if (empty($data->groups)) continue;

            foreach ($data->groups as $gGroup) {
                $members = $this->fetchGroupMembers($accessToken, $gGroup->id);
                try {
                    $this->syncService->syncGroup($gGroup->name, $members);
                } catch (\Exception $e) {
                    $this->log->error("GoogleWorkspace Sync: Failed to sync group {$gGroup->name}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * @return string[]
     */
    private function fetchGroupMembers(string $accessToken, string $groupId): array
    {
        $members = [];
        $baseUrl = "https://admin.googleapis.com/admin/directory/v1/groups/$groupId/members";
        
        foreach ($this->fetchPaginated($baseUrl, $accessToken) as $data) {
            if (empty($data->members)) continue;
            
            foreach ($data->members as $m) {
                if ($m->type === 'USER' && !empty($m->email)) {
                    $members[] = $m->email;
                }
            }
        }
        
        return $members;
    }

    public function syncSingleUser(string $userEmail): void
    {
        if (str_ends_with(strtolower($userEmail), 'test-google-a.com')) {
            return;
        }

        $accessToken = $this->getAccessToken();
        
        if (!$accessToken) {
            return;
        }

        $url = 'https://admin.googleapis.com/admin/directory/v1/users/' . urlencode($userEmail) . '?projection=full';
        $gUser = $this->apiRequest($url, $accessToken);

        if (!$gUser) return;
        
        if (!empty($gUser->error)) {
            $this->log->warning("GoogleWorkspace Sync: User $userEmail not found or error fetching from directory API.");
            return;
        }

        $isAdmin = null;
        $adminGroupEmail = $this->config->get('googleWorkspaceAdminGroup');
        if (!empty($adminGroupEmail)) {
            $adminMembers = array_map('strtolower', $this->fetchGroupMembers($accessToken, urlencode($adminGroupEmail)));
            $isAdmin = in_array(strtolower($userEmail), $adminMembers);
        }

        try {
            $this->processGoogleUser($gUser, $accessToken, $isAdmin);
        } catch (\Exception $e) {
            $this->log->error("GoogleWorkspace Sync: Failed to process single user $userEmail. " . $e->getMessage());
        }
    }
}
