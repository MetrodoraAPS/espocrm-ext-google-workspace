<?php

namespace tests\unit\Espo\Modules\GoogleWorkspace\Services;

use Espo\Core\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\ORM\EntityCollection;
use Espo\Entities\Attachment;
use Espo\Entities\Team;
use Espo\Entities\User;
use Espo\Modules\GoogleWorkspace\Services\GoogleSyncService;
use PHPUnit\Framework\TestCase;

class GoogleSyncServiceTest extends TestCase
{
    private $entityManager;
    private $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->service = new GoogleSyncService($this->entityManager);
    }

    public function testSyncUserThrowsExceptionWhenUsernameIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Username is required for sync.");

        $this->service->syncUser([]);
    }

    public function testSyncUserWhenNewUser(): void
    {
        $data = [
            'userName' => 'test@example.com',
            'name' => [
                'givenName' => 'John',
                'familyName' => 'Doe',
            ],
            'emails' => [
                ['value' => 'john.doe@example.com', 'primary' => true],
            ],
            'phones' => [
                ['value' => '1234567890', 'type' => 'work', 'primary' => true],
            ],
            'active' => true,
        ];

        $selectBuilder = $this->createMock(RDBSelectBuilder::class);
        $selectBuilder->method('findOne')->willReturn(null);

        $userRepository = $this->createMock(RDBRepository::class);
        $userRepository->method('where')->willReturn($selectBuilder);

        $this->entityManager->method('getRDBRepository')
            ->with(User::ENTITY_TYPE)
            ->willReturn($userRepository);

        $user = $this->createMock(User::class);
        $user->method('get')->willReturnMap([
            ['userName', 'test'],
        ]);
        
        $user->method('has')->willReturn(false);

        $this->entityManager->method('getNewEntity')
            ->with(User::ENTITY_TYPE)
            ->willReturn($user);

        $this->entityManager->expects($this->once())
            ->method('saveEntity')
            ->with($this->identicalTo($user));

        $result = $this->service->syncUser($data);
        
        $this->assertSame($user, $result);
    }

    public function testSyncGroupWhenNewGroup(): void
    {
        $groupName = 'IT Support';
        $memberEmails = ['user1@example.com', 'user2@example.com'];

        $teamRepository = $this->createMock(RDBRepository::class);
        $userRepository = $this->createMock(RDBRepository::class);

        $teamSelectBuilder = $this->createMock(RDBSelectBuilder::class);
        $teamSelectBuilder->method('findOne')->willReturn(null);

        $this->entityManager->method('getRDBRepository')
            ->willReturnMap([
                [Team::ENTITY_TYPE, $teamRepository],
                [User::ENTITY_TYPE, $userRepository],
            ]);

        $teamRepository->method('where')->willReturn($teamSelectBuilder);

        $team = $this->createMock(Team::class);

        $this->entityManager->method('getNewEntity')
            ->with(Team::ENTITY_TYPE)
            ->willReturn($team);

        $this->entityManager->expects($this->once())
            ->method('saveEntity')
            ->with($team);

        $relation = $this->createMock(RDBRelation::class);
        $teamRepository->method('getRelation')
            ->with($team, 'users')
            ->willReturn($relation);

        $relation->method('find')->willReturn(new EntityCollection([]));

        $user1 = $this->createMock(User::class);
        $user1->method('get')->willReturnMap([
            ['id', 'u1'],
            ['userName', 'user1']
        ]);
        
        $user2 = $this->createMock(User::class);
        $user2->method('get')->willReturnMap([
            ['id', 'u2'],
            ['userName', 'user2']
        ]);

        $userSelectBuilder = $this->createMock(RDBSelectBuilder::class);
        $userSelectBuilder->method('findOne')->willReturnOnConsecutiveCalls($user1, $user2);

        $userRepository->method('where')->willReturn($userSelectBuilder);

        $relation->expects($this->exactly(2))->method('relateById');

        $result = $this->service->syncGroup($groupName, $memberEmails);

        $this->assertSame($team, $result);
    }

    public function testSyncUserWhenExistingUser(): void
    {
        $data = [
            'userName' => 'test@example.com',
            'name' => [
                'givenName' => 'Jane',
                'familyName' => 'Smith',
            ],
            'active' => false,
        ];

        $selectBuilder = $this->createMock(RDBSelectBuilder::class);

        $userRepository = $this->createMock(RDBRepository::class);
        $userRepository->method('where')->willReturn($selectBuilder);

        $this->entityManager->method('getRDBRepository')
            ->with(User::ENTITY_TYPE)
            ->willReturn($userRepository);

        $user = $this->createMock(User::class);
        $user->method('get')->willReturnMap([
            ['userName', 'test'],
        ]);
        $selectBuilder->method('findOne')->willReturn($user);

        $this->entityManager->expects($this->never())->method('getNewEntity');
        $this->entityManager->expects($this->once())->method('saveEntity')->with($user);

        $result = $this->service->syncUser($data);
        
        $this->assertSame($user, $result);
    }

    public function testSyncGroupWhenExistingGroupAndMembersNeedRemoval(): void
    {
        $groupName = 'IT Support';
        $memberEmails = ['user2@example.com'];

        $teamRepository = $this->createMock(RDBRepository::class);
        $userRepository = $this->createMock(RDBRepository::class);

        $teamSelectBuilder = $this->createMock(RDBSelectBuilder::class);
        $team = $this->createMock(Team::class);
        $teamSelectBuilder->method('findOne')->willReturn($team);

        $this->entityManager->method('getRDBRepository')
            ->willReturnMap([
                [Team::ENTITY_TYPE, $teamRepository],
                [User::ENTITY_TYPE, $userRepository],
            ]);

        $teamRepository->method('where')->willReturn($teamSelectBuilder);

        $this->entityManager->expects($this->never())->method('getNewEntity');

        $relation = $this->createMock(RDBRelation::class);
        $teamRepository->method('getRelation')
            ->with($team, 'users')
            ->willReturn($relation);

        $user1 = $this->createMock(User::class);
        $user1->method('get')->willReturnMap([
            ['id', 'u1'],
            ['userName', 'user1']
        ]);
        
        $collection = new EntityCollection([$user1]);
        $relation->method('find')->willReturn($collection);

        $user2 = $this->createMock(User::class);
        $user2->method('get')->willReturnMap([
            ['id', 'u2'],
            ['userName', 'user2']
        ]);
        
        $userSelectBuilder = $this->createMock(RDBSelectBuilder::class);
        $userSelectBuilder->method('findOne')->willReturn($user2);
        
        $userRepository->method('where')->willReturn($userSelectBuilder);

        $relation->expects($this->once())->method('unrelateById')->with('u1');
        $relation->expects($this->once())->method('relateById')->with('u2');

        $result = $this->service->syncGroup($groupName, $memberEmails);

        $this->assertSame($team, $result);
    }

    public function testSyncUserWithAvatarUpdate(): void
    {
        $data = [
            'userName' => 'test@example.com',
            'photo' => [
                'contents' => 'new_image_data_base_64',
                'mimeType' => 'image/png'
            ]
        ];

        $selectBuilder = $this->createMock(RDBSelectBuilder::class);
        $userRepository = $this->createMock(RDBRepository::class);
        $userRepository->method('where')->willReturn($selectBuilder);

        $this->entityManager->method('getRDBRepository')
            ->with(User::ENTITY_TYPE)
            ->willReturn($userRepository);

        $user = $this->createMock(User::class);
        $user->method('get')->willReturnMap([
            ['userName', 'test'],
            ['avatarId', 'old-avatar-id']
        ]);
        $selectBuilder->method('findOne')->willReturn($user);

        $oldAvatar = $this->createMock(Attachment::class);
        $oldAvatar->method('get')->with('contents')->willReturn('old_image_data_base_64');

        $this->entityManager->method('getEntity')
            ->with(Attachment::ENTITY_TYPE, 'old-avatar-id')
            ->willReturn($oldAvatar);

        $this->entityManager->expects($this->once())
            ->method('removeEntity')
            ->with($oldAvatar);

        $newAvatar = $this->createMock(Attachment::class);
        $newAvatar->method('get')->with('id')->willReturn('new-avatar-id');

        $this->entityManager->method('getNewEntity')
            ->with(Attachment::ENTITY_TYPE)
            ->willReturn($newAvatar);

        $this->entityManager->expects($this->exactly(2))
            ->method('saveEntity');

        $result = $this->service->syncUser($data);
        $this->assertSame($user, $result);
    }

    public function testSyncUserProperlyMapsPhoneTypes(): void
    {
        $data = [
            'userName' => 'phones@example.com',
            'phones' => [
                ['value' => '1111', 'type' => 'work', 'primary' => true],
                ['value' => '2222', 'type' => 'mobile', 'primary' => false],
                ['value' => '3333', 'type' => 'home', 'primary' => false],
                ['value' => '4444', 'type' => 'unknown', 'primary' => false]
            ]
        ];

        $selectBuilder = $this->createMock(RDBSelectBuilder::class);
        $userRepository = $this->createMock(RDBRepository::class);
        $userRepository->method('where')->willReturn($selectBuilder);
        $this->entityManager->method('getRDBRepository')->willReturn($userRepository);
        $selectBuilder->method('findOne')->willReturn(null);
        
        $user = $this->createMock(User::class);
        $user->method('get')->willReturnMap([['userName', 'phones']]);
        
        $user->method('set')->willReturnCallback(function($key, $val) use ($user) {
            if ($key === 'phoneNumberData') {
                TestCase::assertCount(4, $val);
                TestCase::assertEquals('Office', $val[0]->type);
                TestCase::assertTrue($val[0]->primary);

                TestCase::assertEquals('Mobile', $val[1]->type);
                TestCase::assertFalse($val[1]->primary);

                TestCase::assertEquals('Home', $val[2]->type);
                TestCase::assertEquals('Office', $val[3]->type);
            }
            return $user;
        });

        $this->entityManager->method('getNewEntity')->willReturn($user);
        $this->service->syncUser($data);
    }

    public function testSyncUserDoesNotUpdateAvatarIfHashMatches(): void
    {
        $data = [
            'userName' => 'avatar@example.com',
            'photo' => [
                'contents' => 'SAME_CONTENT',
                'mimeType' => 'image/png'
            ]
        ];

        $selectBuilder = $this->createMock(RDBSelectBuilder::class);
        $userRepository = $this->createMock(RDBRepository::class);
        $userRepository->method('where')->willReturn($selectBuilder);
        $this->entityManager->method('getRDBRepository')->willReturn($userRepository);
        
        $user = $this->createMock(User::class);
        $user->method('get')->willReturnMap([
            ['userName', 'avatar'],
            ['avatarId', 'existing-avatar-id']
        ]);
        $selectBuilder->method('findOne')->willReturn($user);

        $oldAvatar = $this->createMock(Attachment::class);
        $oldAvatar->method('get')->with('contents')->willReturn('SAME_CONTENT');
        
        $this->entityManager->method('getEntity')
            ->with(Attachment::ENTITY_TYPE, 'existing-avatar-id')
            ->willReturn($oldAvatar);

        $this->entityManager->expects($this->never())->method('removeEntity');
        $this->entityManager->expects($this->never())->method('getNewEntity');

        $this->service->syncUser($data);
    }

    public function testSyncUserWithMultipleEmailsCorrectlySetsPrimary(): void
    {
        $data = [
            'userName' => 'alias@example.com',
            'emails' => [
                ['value' => 'secondary@example.com', 'primary' => false],
                ['value' => 'alias@example.com', 'primary' => true],
                ['value' => 'third@example.com', 'primary' => false],
            ]
        ];

        $selectBuilder = $this->createMock(RDBSelectBuilder::class);
        $userRepository = $this->createMock(RDBRepository::class);
        $userRepository->method('where')->willReturn($selectBuilder);
        $this->entityManager->method('getRDBRepository')->willReturn($userRepository);
        $selectBuilder->method('findOne')->willReturn(null);
        
        $user = $this->createMock(User::class);
        $user->method('get')->willReturnMap([['userName', 'alias']]);
        $this->entityManager->method('getNewEntity')->willReturn($user);

        $user->method('set')->willReturnCallback(function($key, $val) use ($user) {
            if ($key === 'emailAddressData') {
                TestCase::assertCount(3, $val);
                TestCase::assertFalse($val[0]->primary);
                TestCase::assertEquals('secondary@example.com', $val[0]->emailAddress);
                
                TestCase::assertTrue($val[1]->primary);
                TestCase::assertEquals('alias@example.com', $val[1]->emailAddress);
            }
            return $user;
        });

        $this->service->syncUser($data);
    }
}
