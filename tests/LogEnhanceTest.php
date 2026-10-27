<?php
declare(strict_types=1);

/**
 * 日志增强测试（PSR-3 八级 / 按日切分 / 多通道）
 * 运行：php tests/run.php LogEnhanceTest
 */

require_once __DIR__ . '/bootstrap.php';

use Mtphp\Testing\Assert;
use Mtphp\Testing\TestCase;
use Mtphp\Log\Logger;
use Mtphp\Log\DailyLogger;
use Mtphp\Log\LogManager;

class LogEnhanceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mtphp_log_' . getmypid() . '_' . uniqid();
        @mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        // 清理临时日志文件
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testLevelFilteringIgnoresBelowMin(): void
    {
        $log = new Logger($this->dir . '/app.log', 'info');
        $log->debug('should be filtered');
        $log->info('kept');

        $content = @file_get_contents($this->dir . '/app.log');
        Assert::assertNotContains((string) $content, 'should be filtered');
        Assert::assertContains((string) $content, 'kept');
    }

    public function testAllPsr3LevelsRecordedAtDebug(): void
    {
        $log = new Logger($this->dir . '/all.log', 'debug');
        $log->debug('d'); $log->info('i'); $log->notice('n');
        $log->warning('w'); $log->error('e'); $log->critical('c');
        $log->alert('a'); $log->emergency('em');

        $content = (string) @file_get_contents($this->dir . '/all.log');
        foreach (['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'] as $tag) {
            Assert::assertContains($content, $tag);
        }
    }

    public function testDailyLoggerWritesDatedFile(): void
    {
        $log = new DailyLogger($this->dir . '/app', 'debug');
        $log->info('daily message');

        $expected = $this->dir . '/app-' . date('Y-m-d') . '.log';
        Assert::assertTrue(is_file($expected), '应按日生成文件：' . $expected);
        $content = (string) @file_get_contents($expected);
        Assert::assertContains($content, 'daily message');
    }

    public function testLogManagerChannelSelection(): void
    {
        $mgr = new LogManager([
            'default'  => 'single',
            'channels' => [
                'single' => ['driver' => 'single', 'path' => $this->dir . '/s.log', 'level' => 'info'],
                'daily'  => ['driver' => 'daily',  'path' => $this->dir . '/d',      'level' => 'debug'],
            ],
        ]);

        // 默认通道
        $mgr->info('default channel');
        Assert::assertContains((string) @file_get_contents($this->dir . '/s.log'), 'default channel');

        // 指定 daily 通道
        $mgr->channel('daily')->error('daily channel');
        $dailyFile = $this->dir . '/d-' . date('Y-m-d') . '.log';
        Assert::assertTrue(is_file($dailyFile));
        Assert::assertContains((string) @file_get_contents($dailyFile), 'daily channel');
    }

    public function testLogManagerFallsBackToFlatConfig(): void
    {
        // 兼容旧的扁平配置（只有 path / level）
        $mgr = new LogManager([
            'path'  => $this->dir . '/legacy.log',
            'level' => 'info',
        ]);
        $mgr->warning('legacy');
        Assert::assertContains((string) @file_get_contents($this->dir . '/legacy.log'), 'legacy');
    }
}
