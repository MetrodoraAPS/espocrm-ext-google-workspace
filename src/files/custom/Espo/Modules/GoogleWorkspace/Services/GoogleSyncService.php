<?php

namespace Espo\Modules\GoogleWorkspace\Services;

use Espo\Core\ORM\EntityManager;
use Espo\Entities\User;
use Espo\Entities\Team;
use Espo\Entities\Attachment;

class GoogleSyncService
{
    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function syncUser(array $data): User
    {
        $rawUserName = $data['userName'] ?? '';
        $cleanUserName = explode('@', $rawUserName)[0];
        
        if (empty($cleanUserName)) {
            throw new \RuntimeException("Username is required for sync.");
        }

        /** @var User|null $user */
        $user = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)
            ->where(['userName' => $cleanUserName])
            ->findOne();

        if (!$user) {
            /** @var User $user */
            $user = $this->entityManager->getNewEntity(User::ENTITY_TYPE);
            $user->set('userName', $cleanUserName);
            $user->set('authMethod', 'GoogleWorkspace');
        }

        return $this->updateUserEntity($user, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateUserEntity(User $user, array $data): User
    {
        $cleanUserName = $user->get('userName');

        if (isset($data['name'])) {
            if (isset($data['name']['givenName'])) {
                $user->set('firstName', $data['name']['givenName']);
            }

            if (array_key_exists('familyName', $data['name'])) {
                $user->set('lastName', $data['name']['familyName']);
            }
        } elseif (!$user->has('lastName') || !$user->get('lastName')) {
            $user->set('lastName', $cleanUserName ?: 'Unknown');
        }

        $this->syncUserEmails($user, $data);
        $this->syncUserPhones($user, $data);

        if (isset($data['active'])) {
            $user->set('isActive', (bool)$data['active']);
        } elseif (!$user->has('isActive')) {
            $user->set('isActive', true);
        }

        if (array_key_exists('isAdmin', $data)) {
            $user->set('type', $data['isAdmin'] ? 'admin' : 'regular');
        }

        $this->syncUserAvatar($user, $data, $cleanUserName);

        $this->entityManager->saveEntity($user);

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncUserEmails(User $user, array $data): void
    {
        if (empty($data['emails']) || !is_array($data['emails'])) {
            return;
        }

        $emailAddressData = [];
        foreach ($data['emails'] as $emailItem) {
            if (!empty($emailItem['value'])) {
                $emailAddressData[] = (object) [
                    'emailAddress' => $emailItem['value'],
                    'primary' => !empty($emailItem['primary'])
                ];
            }
        }

        if (!empty($emailAddressData)) {
            $user->set('emailAddressData', $emailAddressData);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncUserPhones(User $user, array $data): void
    {
        if (empty($data['phones']) || !is_array($data['phones'])) {
            return;
        }

        $phoneNumberData = [];
        foreach ($data['phones'] as $phoneItem) {
            if (!empty($phoneItem['value'])) {
                $type = $phoneItem['type'] ?? 'work';
                $mappedType = match (strtolower($type)) {
                    'work' => 'Office',
                    'home' => 'Home',
                    'mobile' => 'Mobile',
                    default => 'Office',
                };

                $phoneNumberData[] = (object) [
                    'phoneNumber' => $phoneItem['value'],
                    'primary' => !empty($phoneItem['primary']),
                    'type' => $mappedType,
                ];
            }
        }

        if (!empty($phoneNumberData)) {
            $user->set('phoneNumberData', $phoneNumberData);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncUserAvatar(User $user, array $data, string $cleanUserName): void
    {
        if (empty($data['photo']) || !is_array($data['photo']) || empty($data['photo']['contents'])) {
            return;
        }

        $avatarId = $user->get('avatarId');
        $oldAvatar = $avatarId ? $this->entityManager->getEntity(Attachment::ENTITY_TYPE, $avatarId) : null;

        if ($oldAvatar) {
            $oldContents = $oldAvatar->get('contents');
            if ($oldContents && md5($oldContents) === md5($data['photo']['contents'])) {
                return;
            }
            $this->entityManager->removeEntity($oldAvatar);
        }

        $mimeType = $data['photo']['mimeType'] ?? 'image/jpeg';
        $extension = $mimeType === 'image/png' ? 'png' : 'jpg';

        /** @var Attachment $attachment */
        $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);
        $attachment->set([
            'name' => "{$cleanUserName}_avatar.{$extension}",
            'contents' => $data['photo']['contents'],
            'type' => $mimeType,
            'role' => 'Avatar',
            'global' => true,
        ]);

        $this->entityManager->saveEntity($attachment);
        $user->set('avatarId', $attachment->get('id'));
    }

    /**
     * @param string[] $members
     */
    public function syncGroup(string $groupName, array $members = []): Team
    {
        $teamRepository = $this->entityManager->getRDBRepository(Team::ENTITY_TYPE);
        $userRepository = $this->entityManager->getRDBRepository(User::ENTITY_TYPE);

        /** @var Team|null $team */
        $team = $teamRepository->where(['name' => $groupName])->findOne();

        if (!$team) {
            $team = $this->entityManager->getNewEntity(Team::ENTITY_TYPE);
            $team->set('name', $groupName);
            $this->entityManager->saveEntity($team);
        }

        if (!empty($members)) {
            $relation = $teamRepository->getRelation($team, 'users');
            $currentUsers = $relation->find();
            
            $currentUserIds = [];
            foreach ($currentUsers as $u) {
                $currentUserIds[$u->id ?? $u->get('id')] = true;
            }

            $targetUserIds = [];
            foreach ($members as $email) {
                /** @var User|null $u */
                $u = $userRepository->where(['emailAddress' => $email])->findOne();
                if ($u) {
                    $targetUserIds[$u->id ?? $u->get('id')] = true;
                }
            }

            foreach (array_keys($currentUserIds) as $id) {
                if (!isset($targetUserIds[$id])) {
                    $relation->unrelateById($id);
                }
            }

            foreach (array_keys($targetUserIds) as $id) {
                if (!isset($currentUserIds[$id])) {
                    $relation->relateById($id);
                }
            }
        }

        return $team;
    }
}
