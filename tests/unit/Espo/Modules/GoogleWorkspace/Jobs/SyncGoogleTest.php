<?php

namespace tests\unit\Espo\Modules\GoogleWorkspace\Jobs;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\GoogleWorkspace\Jobs\SyncGoogle;
use Espo\Modules\GoogleWorkspace\Services\GoogleSyncService;
use PHPUnit\Framework\TestCase;

class SyncGoogleTest extends TestCase
{
    private $configMock;
    private $logMock;
    private $syncServiceMock;
    private $job;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(Config::class);
        $this->logMock = $this->createMock(Log::class);
        $this->syncServiceMock = $this->createMock(GoogleSyncService::class);

        $this->job = new SyncGoogle(
            $this->configMock,
            $this->logMock,
            $this->syncServiceMock
        );
    }

    public function testRunBailsWhenSyncDisabled(): void
    {
        $this->configMock->method('get')
            ->with('googleWorkspaceEnableSync')
            ->willReturn(false);

        // Job should just return early, without calling syncUser or error logs
        $this->logMock->expects($this->never())->method('info');
        $this->logMock->expects($this->never())->method('error');

        $this->job->run();
    }

    public function testRunBailsWhenFrequencyNotMet(): void
    {
        $this->configMock->method('get')->willReturnMap([
            ['googleWorkspaceEnableSync', null, true],
            ['googleWorkspaceSyncFrequency', '1_hour', '1_hour'],
            ['googleWorkspaceLastSyncTime', 0, time() - 1800] // 30 mins ago
        ]);

        $this->configMock->expects($this->never())->method('set');

        $this->logMock->expects($this->never())->method('info');
        $this->logMock->expects($this->never())->method('error');

        $this->job->run();
    }

    public function testRunBailsWhenTokensAreMissing(): void
    {
        $this->configMock->method('get')->willReturnMap([
            ['googleWorkspaceEnableSync', null, true],
            ['googleWorkspaceAdminEmail', null, null],
            ['googleWorkspaceServiceAccount', null, null]
        ]);

        $this->logMock->expects($this->once())
            ->method('info')
            ->with("GoogleWorkspace Sync: Missing Admin Email or Service Account.");

        $this->job->run();
    }

    public function testRunExecutesGroupSyncWhenConfigured(): void
    {
        $this->configMock->method('get')->willReturnMap([
            ['googleWorkspaceEnableSync', null, true],
            ['googleWorkspaceSyncGroups', null, true]
        ]);

        $job = $this->getMockBuilder(SyncGoogle::class)
            ->setConstructorArgs([$this->configMock, $this->logMock, $this->syncServiceMock])
            ->onlyMethods(['getAccessToken', 'syncUsers', 'syncGroups'])
            ->getMock();

        $job->expects($this->once())
            ->method('getAccessToken')
            ->willReturn('fake_token_123');

        $job->expects($this->once())
            ->method('syncUsers')
            ->with('fake_token_123');

        $job->expects($this->once())
            ->method('syncGroups')
            ->with('fake_token_123');

        $this->configMock->expects($this->once())
            ->method('set')
            ->with('googleWorkspaceLastSyncTime', $this->isType('int'));

        $this->configMock->expects($this->once())
            ->method('save');

        $job->run();
    }

    public function testRunSkipsGroupSyncWhenNotConfigured(): void
    {
        $this->configMock->method('get')->willReturnMap([
            ['googleWorkspaceEnableSync', null, true],
            ['googleWorkspaceSyncGroups', null, false]
        ]);

        $job = $this->getMockBuilder(SyncGoogle::class)
            ->setConstructorArgs([$this->configMock, $this->logMock, $this->syncServiceMock])
            ->onlyMethods(['getAccessToken', 'syncUsers', 'syncGroups'])
            ->getMock();

        $job->expects($this->once())
            ->method('getAccessToken')
            ->willReturn('fake_token_123');

        $job->expects($this->once())
            ->method('syncUsers')
            ->with('fake_token_123');

        $job->expects($this->never())
            ->method('syncGroups');

        $this->configMock->expects($this->once())
            ->method('set')
            ->with('googleWorkspaceLastSyncTime', $this->isType('int'));

        $this->configMock->expects($this->once())
            ->method('save');

        $job->run();
    }
}
