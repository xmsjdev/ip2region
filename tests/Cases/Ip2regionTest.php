<?php

declare(strict_types=1);
/**
 * This file is part of the Xmsjdev.
 *
 * (c) Xmsjdev <dev@xiamenshiji.vip>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace HyperfTest\Cases;

use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Xmsjdev\Ip2region\Ip2region;

class Ip2regionTest extends AbstractTestCase
{
    private const DEFAULT_REGION = 'TestCountry|TestProvince|TestCity|TestISP|CN';

    private $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/ip2region-test-' . uniqid('', true) . '.xdb';
        $this->createFixture($this->dbFile);
        Ip2region::clearCache($this->dbFile);
    }

    protected function tearDown(): void
    {
        Ip2region::clearCache();
        if (is_file($this->dbFile)) {
            @unlink($this->dbFile);
        }
    }

    // ============================================================
    // 原有测试（保留并略增强）
    // ============================================================

    public function testAllCachePoliciesAndCompatibilityMethods(): void
    {
        foreach (['memory', 'vectorIndex', 'file'] as $policy) {
            Ip2region::clearCache($this->dbFile);
            $searcher = new Ip2region($this->dbFile, $policy);

            self::assertSame(self::DEFAULT_REGION, $searcher->search('1.2.3.4'));
            self::assertSame(
                ['city_id' => 0, 'region' => self::DEFAULT_REGION],
                $searcher->memorySearch('1.2.3.4')
            );
            self::assertSame($searcher->memorySearch('1.2.3.4'), $searcher->binarySearch('1.2.3.4'));
            self::assertSame($searcher->memorySearch('1.2.3.4'), $searcher->btreeSearch('1.2.3.4'));
            self::assertCount(2, $searcher->batchSearch(['1.2.3.4', '1.2.3.5']));
        }
    }

    public function testMemoryCacheIsReusedAcrossInstances(): void
    {
        Ip2region::clearCache($this->dbFile);

        $first = new Ip2region($this->dbFile);
        $first->search('1.2.3.4');
        $loadsAfterFirst = $first->getStats()['counters']['memory_loads'];

        $second = new Ip2region($this->dbFile);
        self::assertSame(self::DEFAULT_REGION, $second->search('1.2.3.4'));
        self::assertSame($loadsAfterFirst, $second->getStats()['counters']['memory_loads']);
    }

    public function testInvalidIpThrows(): void
    {
        $searcher = new Ip2region($this->dbFile);
        $this->expectException(RuntimeException::class);
        $searcher->search('not-an-ip');
    }

    // ============================================================
    // 新增 #1 #2 #3：缓存策略相关
    // ============================================================

    public function testVectorIndexCacheIsReusedAcrossInstances(): void
    {
        Ip2region::clearCache($this->dbFile);

        $first = new Ip2region($this->dbFile, 'vectorIndex');
        $first->search('1.2.3.4');
        $loadsAfterFirst = $first->getStats()['counters']['vector_loads'];

        $second = new Ip2region($this->dbFile, 'vectorIndex');
        $second->search('1.2.3.4');
        $loadsAfterSecond = $second->getStats()['counters']['vector_loads'];

        self::assertSame($loadsAfterFirst, $loadsAfterSecond, 'vectorIndex cache should be shared across instances');
    }

    public function testFilePolicyOpensPerSearch(): void
    {
        Ip2region::clearCache($this->dbFile);

        $searcher = new Ip2region($this->dbFile, 'file');
        $before = $searcher->getStats()['counters']['file_opens'];

        $searcher->search('1.2.3.4');
        $searcher->search('1.2.3.5');

        $after = $searcher->getStats()['counters']['file_opens'];
        self::assertSame($before + 2, $after, 'file policy should fopen on every search');
    }

    public function testVectorIndexAndMemoryProduceSameResult(): void
    {
        Ip2region::clearCache($this->dbFile);
        $memory = new Ip2region($this->dbFile, 'memory');

        Ip2region::clearCache($this->dbFile);
        $vector = new Ip2region($this->dbFile, 'vectorIndex');

        Ip2region::clearCache($this->dbFile);
        $file = new Ip2region($this->dbFile, 'file');

        foreach (['1.2.3.4', '1.3.0.1', '8.8.8.8', '114.114.114.114', '223.5.5.5'] as $ip) {
            $m = $memory->search($ip);
            $v = $vector->search($ip);
            $f = $file->search($ip);
            self::assertSame($m, $v, "vectorIndex mismatch for {$ip}");
            self::assertSame($m, $f, "file mismatch for {$ip}");
        }
    }

    // ============================================================
    // 新增 #4 #5：签名变更触发重载
    // ============================================================

    public function testSignatureChangeTriggersReload(): void
    {
        $searcher1 = new Ip2region($this->dbFile);
        self::assertSame(self::DEFAULT_REGION, $searcher1->search('1.2.3.4'));
        $loads1 = $searcher1->getStats()['counters']['memory_loads'];

        // 用不同长度的 region 重写，保证 size 变化，签名必然变化
        $newRegion = 'ModifiedRegion|Province|City|ISP|CN';
        $this->createFixture($this->dbFile, $newRegion);

        $searcher2 = new Ip2region($this->dbFile);
        self::assertSame($newRegion, $searcher2->search('1.2.3.4'));
        self::assertSame($loads1 + 1, $searcher2->getStats()['counters']['memory_loads']);
    }

    public function testVectorIndexSignatureChangeTriggersReload(): void
    {
        $searcher1 = new Ip2region($this->dbFile, 'vectorIndex');
        self::assertSame(self::DEFAULT_REGION, $searcher1->search('1.2.3.4'));
        $loads1 = $searcher1->getStats()['counters']['vector_loads'];

        $newRegion = 'VectorModified|Province|City|ISP|CN';
        $this->createFixture($this->dbFile, $newRegion);

        $searcher2 = new Ip2region($this->dbFile, 'vectorIndex');
        self::assertSame($newRegion, $searcher2->search('1.2.3.4'));
        self::assertSame($loads1 + 1, $searcher2->getStats()['counters']['vector_loads']);
    }

    // ============================================================
    // 新增 #6：clearCache 后重载
    // ============================================================

    public function testClearCacheSpecificPathForcesReload(): void
    {
        $first = new Ip2region($this->dbFile);
        $first->search('1.2.3.4');
        $loads1 = $first->getStats()['counters']['memory_loads'];

        Ip2region::clearCache($this->dbFile);

        $second = new Ip2region($this->dbFile);
        $second->search('1.2.3.4');
        self::assertSame($loads1 + 1, $second->getStats()['counters']['memory_loads']);
    }

    public function testClearCacheAllForcesReload(): void
    {
        $first = new Ip2region($this->dbFile);
        $first->search('1.2.3.4');
        $loads1 = $first->getStats()['counters']['memory_loads'];

        Ip2region::clearCache();

        $second = new Ip2region($this->dbFile);
        $second->search('1.2.3.4');
        self::assertSame($loads1 + 1, $second->getStats()['counters']['memory_loads']);
    }

    // ============================================================
    // 新增 #7：并发冷加载只加载一次
    // ============================================================
    //
    // 注意：
    //   1. flock 在 Swoole runtime hook 下是同步阻塞的；若 SWOOLE_HOOK_FILE 开启
    //      且 file_get_contents 在加载过程中 yield，会因持锁阻塞整个 worker 而
    //      导致该测试挂起。故本测试要求在关闭 SWOOLE_HOOK_FILE 的环境执行。
    //   2. 若测试已在协程中运行，直接使用当前协程上下文；否则包一层 run()。
    // ============================================================

    public function testConcurrentLoadingOnlyLoadsOnce(): void
    {
        if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
            $this->markTestSkipped('Swoole/OpenSwoole extension required');
        }
        if ($this->isFileIoHooked()) {
            $this->markTestSkipped('Concurrent loading test requires SWOOLE_HOOK_FILE off to avoid flock deadlock');
        }

        // 预热一次建立基线
        $warmup = new Ip2region($this->dbFile);
        $warmup->search('1.2.3.4');
        $baseline = $warmup->getStats()['counters']['memory_loads'];

        // 清缓存，模拟冷启动
        Ip2region::clearCache($this->dbFile);

        $results = [];
        $errors = [];
        $worker = function () use (&$results, &$errors) {
            $wg = new WaitGroup();
            for ($i = 0; $i < 20; $i++) {
                $wg->add();
                Coroutine::create(function () use ($wg, &$results, &$errors) {
                    try {
                        $searcher = new Ip2region($this->dbFile);
                        $results[] = $searcher->search('1.2.3.4');
                    } catch (\Throwable $e) {
                        $errors[] = $e;
                    } finally {
                        $wg->done();
                    }
                });
            }
            $wg->wait();
        };

        if (Coroutine::getCid() > 0) {
            $worker();
        } else {
            Coroutine\run($worker);
        }

        self::assertEmpty(
            $errors,
            'Concurrent loading errors: ' . implode(', ', array_map(
                static fn (\Throwable $e) => $e->getMessage(),
                $errors
            ))
        );
        self::assertCount(20, $results);
        foreach ($results as $r) {
            self::assertSame(self::DEFAULT_REGION, $r);
        }

        $checker = new Ip2region($this->dbFile);
        self::assertSame(
            $baseline + 1,
            $checker->getStats()['counters']['memory_loads'],
            'Concurrent cold-load must load exactly once'
        );
    }

    // ============================================================
    // 新增 #8：边界 IP
    // ============================================================

    public function testBoundaryIps(): void
    {
        $searcher = new Ip2region($this->dbFile);

        // 未铺向量的前缀应返回 null
        self::assertNull($searcher->search('0.0.0.0'));
        self::assertNull($searcher->search('255.255.255.255'));
        self::assertNull($searcher->search('127.0.0.1'));

        // 已铺向量的前缀能命中
        self::assertSame(self::DEFAULT_REGION, $searcher->search('1.2.3.4'));
        self::assertSame(self::DEFAULT_REGION, $searcher->search('1.2.255.255'));
    }

    public function testBoundaryIpsAcrossPolicies(): void
    {
        foreach (['memory', 'vectorIndex', 'file'] as $policy) {
            Ip2region::clearCache($this->dbFile);
            $searcher = new Ip2region($this->dbFile, $policy);

            self::assertNull($searcher->search('0.0.0.0'), "[{$policy}] 0.0.0.0 should be null");
            self::assertNull($searcher->search('255.255.255.255'), "[{$policy}] 255.255.255.255 should be null");
            self::assertNull($searcher->search('127.0.0.1'), "[{$policy}] 127.0.0.1 should be null");
            self::assertSame(self::DEFAULT_REGION, $searcher->search('1.2.3.4'), "[{$policy}] 1.2.3.4 should hit");
        }
    }

    // ============================================================
    // 新增 #9：多前缀 fixture
    // ============================================================

    public function testMultipleVectorPrefixesResolve(): void
    {
        $searcher = new Ip2region($this->dbFile);

        // fixture 里铺了 [1,2], [1,3], [8,8], [114,114], [223,5]
        foreach (['1.2.0.1', '1.3.0.1', '8.8.8.8', '114.114.114.114', '223.5.5.5'] as $ip) {
            self::assertSame(self::DEFAULT_REGION, $searcher->search($ip), "prefix for {$ip} should hit");
        }
    }

    // ============================================================
    // 新增 #10：batchSearch 一致性
    // ============================================================

    public function testBatchSearchMatchesIndividualSearch(): void
    {
        $searcher = new Ip2region($this->dbFile);
        $ips = ['1.2.3.4', '1.3.0.1', '8.8.8.8', '0.0.0.0'];
        $batch = $searcher->batchSearch($ips);

        self::assertCount(4, $batch);
        foreach ($ips as $i => $ip) {
            self::assertSame($searcher->memorySearch($ip), $batch[$i], "batch[{$ip}] mismatch");
        }
    }

    // ============================================================
    // 新增 #11：getLong / getShort 边界与字节序
    // ============================================================

    public function testGetLongOutOfBoundsThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Ip2region::getLong('ab', 0);
    }

    public function testGetShortOutOfBoundsThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Ip2region::getShort('a', 0);
    }

    public function testGetLongLittleEndian(): void
    {
        self::assertSame(0x04030201, Ip2region::getLong("\x01\x02\x03\x04", 0));
        self::assertSame(0x04030201, Ip2region::getLong("xx\x01\x02\x03\x04", 2));
    }

    public function testGetShortLittleEndian(): void
    {
        self::assertSame(0x0201, Ip2region::getShort("\x01\x02", 0));
        self::assertSame(0x0201, Ip2region::getShort("xx\x01\x02", 2));
    }

    // ============================================================
    // 新增 #12：非法构造参数
    // ============================================================

    public function testInvalidCachePolicyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        new Ip2region($this->dbFile, 'invalid-policy');
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        new Ip2region('/nonexistent/path/ip2region.xdb');
    }

    public function testTruncatedFileThrows(): void
    {
        $truncated = sys_get_temp_dir() . '/ip2region-truncated-' . uniqid('', true) . '.xdb';
        file_put_contents($truncated, 'short');
        try {
            $this->expectException(RuntimeException::class);
            new Ip2region($truncated);
        } finally {
            @unlink($truncated);
        }
    }

    // ============================================================
    // 新增 #13：getStats 字段正确性
    // ============================================================

    public function testGetStatsFields(): void
    {
        Ip2region::clearCache($this->dbFile);
        $searcher = new Ip2region($this->dbFile, 'memory');
        $stats = $searcher->getStats();

        self::assertSame('memory', $stats['cache_policy']);
        self::assertTrue($stats['xdb_loaded']);
        self::assertFalse($stats['vector_index_loaded']);
        self::assertGreaterThan(0, $stats['xdb_size']);
        self::assertGreaterThan(0, $stats['component_buffer_bytes']);
        self::assertArrayHasKey('counters', $stats);
    }

    public function testGetStatsForVectorIndexPolicy(): void
    {
        Ip2region::clearCache($this->dbFile);
        $searcher = new Ip2region($this->dbFile, 'vectorIndex');
        $stats = $searcher->getStats();

        self::assertSame('vectorIndex', $stats['cache_policy']);
        self::assertFalse($stats['xdb_loaded']);
        self::assertTrue($stats['vector_index_loaded']);
        self::assertSame(Ip2region::VectorIndexLength, $stats['component_buffer_bytes']);
    }

    // ============================================================
    // fixture：支持自定义 region 与多前缀覆盖
    // ============================================================

    private function createFixture(string $path, string $region = self::DEFAULT_REGION): void
    {
        $header = str_repeat("\0", Ip2region::HeaderInfoLength);
        $vector = str_repeat("\0", Ip2region::VectorIndexLength);

        $segmentOffset = Ip2region::HeaderInfoLength + Ip2region::VectorIndexLength;
        $dataOffset = $segmentOffset + Ip2region::SegmentIndexSize;

        // 多前缀覆盖：所有列出的 (a, b) 都指向同一个 segment
        $prefixes = [
            [1, 2],       // 1.2.x.x
            [1, 3],       // 1.3.x.x
            [8, 8],       // 8.8.x.x
            [114, 114],   // 114.114.x.x
            [223, 5],     // 223.5.x.x
        ];
        foreach ($prefixes as [$a, $b]) {
            $idx = ($a * 256 + $b) * Ip2region::VectorIndexSize;
            $vector = substr_replace(
                $vector,
                pack('V2', $segmentOffset, $segmentOffset),
                $idx,
                8
            );
        }

        // segment: startIp=0, endIp=0xFFFFFFFF, dataLength, dataPointer
        $segment = pack('V2vV', 0, 0xFFFFFFFF, strlen($region), $dataOffset);
        file_put_contents($path, $header . $vector . $segment . $region);
    }

    // ============================================================
    // helper：检测 SWOOLE_HOOK_FILE 是否开启
    // ============================================================

    private function isFileIoHooked(): bool
    {
        if (! extension_loaded('swoole') && ! extension_loaded('openswoole')) {
            return false;
        }
        // Swoole 4.6+ 提供 getHookFlags；老版本回退到 ini 判断
        if (method_exists(\Swoole\Runtime::class, 'getHookFlags')) {
            return (\Swoole\Runtime::getHookFlags() & SWOOLE_HOOK_FILE) !== 0;
        }
        return (bool) ini_get('swoole.enable_coroutine') && (int) ini_get('swoole.enable_library') === 1;
    }
}
