<?php

namespace tests\unit\Espo\Modules\GoogleWorkspace\Authentication\GoogleWorkspace;

use Espo\Core\Authentication\Login\Data;
use Espo\Core\Authentication\Logins\Espo;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\Api\Request;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\ApplicationState;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\GoogleWorkspace\Authentication\GoogleWorkspace\Login;
use PHPUnit\Framework\TestCase;

class LoginTest extends TestCase
{
    private $espoLoginMock;
    private $configMock;
    private $entityManagerMock;
    private $logMock;
    private $applicationStateMock;
    private $syncServiceMock;
    private $containerMock;
    private $login;

    protected function setUp(): void
    {
        $this->espoLoginMock = $this->createMock(Espo::class);
        $this->configMock = $this->createMock(Config::class);
        $this->entityManagerMock = $this->createMock(EntityManager::class);
        $this->logMock = $this->createMock(Log::class);
        $this->applicationStateMock = $this->createMock(ApplicationState::class);
        $this->syncServiceMock = $this->createMock(\Espo\Modules\GoogleWorkspace\Services\GoogleSyncService::class);
        $this->containerMock = $this->createMock(\Espo\Core\Container::class);

        $this->login = new Login(
            $this->espoLoginMock,
            $this->configMock,
            $this->entityManagerMock,
            $this->logMock,
            $this->applicationStateMock,
            $this->syncServiceMock,
            $this->containerMock
        );
    }

    public function testLoginFallbackWhenNotGoogleWorkspaceUsername(): void
    {
        $data = new Data('ordinary-user', 'password');
        $request = $this->createMock(Request::class);

        $this->configMock->method('get')->willReturnMap([
            ['googleWorkspaceAllowAdminFallback', true, true],
            ['googleWorkspaceAllowRegularFallback', false, true]
        ]);

        $failResult = Result::fail(FailReason::DENIED);
        $this->espoLoginMock->method('login')
            ->with($data, $request)
            ->willReturn($failResult);

        $result = $this->login->login($data, $request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(FailReason::DENIED, $result->getFailReason());
    }

    public function testLoginReturnsNoPasswordWhenCodeIsMissing(): void
    {
        $data = new Data('**google-workspace', '');
        $request = $this->createMock(Request::class);

        $result = $this->login->login($data, $request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(FailReason::NO_PASSWORD, $result->getFailReason());
    }

    public function testLoginFailsWhenConfigIsMissing(): void
    {
        $data = new Data('**google-workspace', 'auth_code_123');
        $request = $this->createMock(Request::class);

        $this->configMock->method('get')->willReturnMap([
            ['googleWorkspaceClientId', null, null],
            ['googleWorkspaceClientSecret', null, null]
        ]);

        $this->logMock->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Client ID or Secret is missing'));

        $result = $this->login->login($data, $request);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(FailReason::DENIED, $result->getFailReason());
    }
}
