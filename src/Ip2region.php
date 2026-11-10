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

namespace Xmsjdev\Ip2region;

use RuntimeException;

class Ip2region
{
    public const HeaderInfoLength = 256;
    public const VectorIndexRows = 256;
    public const VectorIndexCols = 256;
    public const VectorIndexSize = 8;
    public const SegmentIndexSize = 14;
    public const VectorIndexLength = 256 * 256 * 8;

    /** @var array<string, array{signature:string, content:string}> */
    private static $memoryCache = [];

    /** @var array<string, array{signature:string, content:string}> */
    private static $vectorCache = [];

    /** @var array<string, int> */
    private static $counters = [
        'memory_loads' => 0,
        'vector_loads' => 0,
        'file_opens' => 0,
        'file_reads' => 0,
        'searches' => 0,
    ];

    /** @var string */
    private $dbFile;

    /** @var string */
    private $cachePolicy;

    /** @var string|null */
    private $contentBuff;

    /** @var string|null */
    private $vectorIndex;

    public function __construct($dbFile = null, string $cachePolicy = 'memory')
    {
        if ($dbFile === null || $dbFile === '') {
            $dbFile = __DIR__ . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'ip2region.xdb';
        }

        if (!is_string($dbFile) || !is_file($dbFile) || !is_readable($dbFile)) {
            throw new RuntimeException('XDB file does not exist or is not readable: ' . (string) $dbFile);
        }

        $realPath = realpath($dbFile);
        if ($realPath === false) {
            throw new RuntimeException('Unable to resolve XDB path: ' . $dbFile);
        }

        $cachePolicy = strtolower($cachePolicy);
        if ($cachePolicy === 'vectorindex' || $cachePolicy === 'vector_index') {
            $cachePolicy = 'vectorIndex';
        }

        if (!in_array($cachePolicy, ['memory', 'vectorIndex', 'file'], true)) {
            throw new RuntimeException('Unsupported XDB cache policy: ' . $cachePolicy);
        }

        $this->dbFile = $realPath;
        $this->cachePolicy = $cachePolicy;
        $this->contentBuff = null;
        $this->vectorIndex = null;

        $this->validateFile();

        if ($cachePolicy === 'memory') {
            $this->contentBuff = self::loadCachedContent($this->dbFile);
        } elseif ($cachePolicy === 'vectorIndex') {
            $this->vectorIndex = self::loadCachedVectorIndex($this->dbFile);
        }
    }

    /**
     * Unified query API. Returns the original XDB region string or null.
     *
     * @param string|int $ip
     */
    public function search($ip): ?string
    {
        $ipLong = self::normalizeIp($ip);
        self::$counters['searches']++;

        if ($this->cachePolicy === 'memory') {
            return $this->searchFromMemory($ipLong);
        }

        $handle = @fopen($this->dbFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open XDB file: ' . $this->dbFile);
        }

        self::$counters['file_opens']++;

        try {
            return $this->searchFromHandle($ipLong, $handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Backward-compatible API.
     *
     * @param string|int $ip
     */
    public function memorySearch($ip): ?array
    {
        $region = $this->search($ip);
        return $region === null ? null : ['city_id' => 0, 'region' => $region];
    }

    /** @param string|int $ip */
    public function binarySearch($ip): ?array
    {
        return $this->memorySearch($ip);
    }

    /** @param string|int $ip */
    public function btreeSearch($ip): ?array
    {
        return $this->memorySearch($ip);
    }

    /**
     * Batch query API. Invalid IPs throw, matching search().
     *
     * @param array<int, string|int> $ips
     * @return array<int, array{city_id:int,region:string}|null>
     */
    public function batchSearch(array $ips): array
    {
        $result = [];
        foreach ($ips as $key => $ip) {
            $result[$key] = $this->memorySearch($ip);
        }
        return $result;
    }

    public function getCachePolicy(): string
    {
        return $this->cachePolicy;
    }

    /**
     * Process-local statistics. memory_usage is PHP allocator usage, not RSS.
     */
    public function getStats(): array
    {
        $stat = @stat($this->dbFile);

        return [
            'cache_policy' => $this->cachePolicy,
            'db_file' => $this->dbFile,
            'xdb_size' => $stat ? (int) $stat['size'] : 0,
            'xdb_loaded' => $this->contentBuff !== null,
            'vector_index_loaded' => $this->vectorIndex !== null,
            'component_buffer_bytes' => $this->contentBuff !== null
                ? strlen($this->contentBuff)
                : ($this->vectorIndex !== null ? strlen($this->vectorIndex) : 0),
            'memory_usage' => memory_get_usage(true),
            'memory_usage_real' => memory_get_usage(false),
            'memory_peak' => memory_get_peak_usage(true),
            'counters' => self::$counters,
        ];
    }

    /**
     * Explicitly release process-local cached XDB data.
     *
     * Do not call while searches using this cache are in progress. Existing
     * instances keep their own string reference until they are destroyed.
     */
    public static function clearCache(?string $dbFile = null): void
    {
        if ($dbFile === null) {
            self::$memoryCache = [];
            self::$vectorCache = [];
            return;
        }

        $realPath = realpath($dbFile);
        if ($realPath === false) {
            return;
        }

        unset(self::$memoryCache[$realPath], self::$vectorCache[$realPath]);
    }

    /** @param string|int $ip */
    private static function normalizeIp($ip)
    {
        if (is_int($ip)) {
            if (PHP_INT_SIZE < 8 && $ip < 0) {
                return sprintf('%u', $ip);
            }
            if ($ip < 0 || $ip > 0xFFFFFFFF) {
                throw new RuntimeException('Invalid IPv4 numeric address.');
            }
            return $ip;
        }

        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new RuntimeException('Invalid IPv4 address: ' . (string) $ip);
        }

        $long = ip2long($ip);
        if ($long === false) {
            throw new RuntimeException('Invalid IPv4 address: ' . $ip);
        }

        if (PHP_INT_SIZE < 8 && $long < 0) {
            return sprintf('%u', $long);
        }

        return $long;
    }

    private function validateFile(): void
    {
        $size = @filesize($this->dbFile);
        $minimum = self::HeaderInfoLength + self::VectorIndexLength + self::SegmentIndexSize;

        if ($size === false || $size < $minimum) {
            throw new RuntimeException(
                sprintf('Invalid or truncated XDB file: %s (size: %s)', $this->dbFile, (string) $size)
            );
        }
    }

    private static function fileSignature(string $path): string
    {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            throw new RuntimeException('Unable to stat XDB file: ' . $path);
        }

        return implode(':', [
            (string) $stat['dev'],
            (string) $stat['ino'],
            (string) $stat['size'],
            (string) $stat['mtime'],
            (string) $stat['ctime'],
        ]);
    }

    /**
     * flock serializes first-load work inside a process, including when a
     * coroutine-enabled filesystem call yields during initialization.
     *
     * @return resource
     */
    private static function acquireLoadLock(string $path)
    {
        $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'xmsjdev-ip2region-' . sha1($path) . '.lock';

        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to create XDB cache lock: ' . $lockPath);
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Unable to acquire XDB cache lock: ' . $lockPath);
        }

        return $lock;
    }

    private static function loadCachedContent(string $path): string
    {
        $signature = self::fileSignature($path);
        if (isset(self::$memoryCache[$path]) && self::$memoryCache[$path]['signature'] === $signature) {
            return self::$memoryCache[$path]['content'];
        }

        $lock = self::acquireLoadLock($path);
        try {
            $signature = self::fileSignature($path);
            if (isset(self::$memoryCache[$path]) && self::$memoryCache[$path]['signature'] === $signature) {
                return self::$memoryCache[$path]['content'];
            }

            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                throw new RuntimeException('Unable to load XDB content: ' . $path);
            }

            // Replace the old version for this path instead of retaining every
            // historical database version in a long-running Worker.
            self::$memoryCache[$path] = [
                'signature' => self::fileSignature($path),
                'content' => $content,
            ];
            self::$counters['memory_loads']++;

            return self::$memoryCache[$path]['content'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function loadCachedVectorIndex(string $path): string
    {
        $signature = self::fileSignature($path);
        if (isset(self::$vectorCache[$path]) && self::$vectorCache[$path]['signature'] === $signature) {
            return self::$vectorCache[$path]['content'];
        }

        $lock = self::acquireLoadLock($path);
        try {
            $signature = self::fileSignature($path);
            if (isset(self::$vectorCache[$path]) && self::$vectorCache[$path]['signature'] === $signature) {
                return self::$vectorCache[$path]['content'];
            }

            $handle = @fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open XDB file: ' . $path);
            }

            try {
                if (fseek($handle, self::HeaderInfoLength) !== 0) {
                    throw new RuntimeException('Unable to seek XDB vector index: ' . $path);
                }
                $vector = self::readExact($handle, self::VectorIndexLength);
            } finally {
                fclose($handle);
            }

            self::$vectorCache[$path] = [
                'signature' => self::fileSignature($path),
                'content' => $vector,
            ];
            self::$counters['vector_loads']++;

            return self::$vectorCache[$path]['content'];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function readExact($handle, int $length): string
    {
        $data = '';
        while (strlen($data) < $length && !feof($handle)) {
            $chunk = fread($handle, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        if (strlen($data) !== $length) {
            throw new RuntimeException('Unexpected end of XDB file while reading ' . $length . ' bytes.');
        }

        return $data;
    }

    /** @param string|int $ip */
    private function searchFromMemory($ip): ?string
    {
        $content = $this->contentBuff;
        if ($content === null) {
            throw new RuntimeException('XDB memory buffer is not initialized.');
        }

        $indexOffset = self::HeaderInfoLength
            + ((($ip >> 24) & 0xFF) * self::VectorIndexCols + (($ip >> 16) & 0xFF))
            * self::VectorIndexSize;

        $startPtr = self::getLong($content, $indexOffset);
        $endPtr = self::getLong($content, $indexOffset + 4);

        return $this->binarySearchMemory($content, $ip, $startPtr, $endPtr);
    }

    /** @param resource $handle */
    private function searchFromHandle($ip, $handle): ?string
    {
        if ($this->vectorIndex !== null) {
            $indexOffset = ((($ip >> 24) & 0xFF) * self::VectorIndexCols + (($ip >> 16) & 0xFF))
                * self::VectorIndexSize;
            $startPtr = self::getLong($this->vectorIndex, $indexOffset);
            $endPtr = self::getLong($this->vectorIndex, $indexOffset + 4);
        } else {
            $indexOffset = self::HeaderInfoLength
                + ((($ip >> 24) & 0xFF) * self::VectorIndexCols + (($ip >> 16) & 0xFF))
                * self::VectorIndexSize;
            $vector = $this->readAt($handle, $indexOffset, self::VectorIndexSize);
            $startPtr = self::getLong($vector, 0);
            $endPtr = self::getLong($vector, 4);
        }

        $low = 0;
        $high = intdiv($endPtr - $startPtr, self::SegmentIndexSize);

        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $offset = $startPtr + $middle * self::SegmentIndexSize;
            $segment = $this->readAt($handle, $offset, self::SegmentIndexSize);

            $startIp = self::getLong($segment, 0);
            if ($ip < $startIp) {
                $high = $middle - 1;
                continue;
            }

            $endIp = self::getLong($segment, 4);
            if ($ip > $endIp) {
                $low = $middle + 1;
                continue;
            }

            $dataLength = self::getShort($segment, 8);
            $dataPointer = self::getLong($segment, 10);
            return $dataLength > 0 ? $this->readAt($handle, $dataPointer, $dataLength) : null;
        }

        return null;
    }

    private function binarySearchMemory(string $content, $ip, int $startPtr, int $endPtr): ?string
    {
        $low = 0;
        $high = intdiv($endPtr - $startPtr, self::SegmentIndexSize);

        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            $offset = $startPtr + $middle * self::SegmentIndexSize;

            $startIp = self::getLong($content, $offset);
            if ($ip < $startIp) {
                $high = $middle - 1;
                continue;
            }

            $endIp = self::getLong($content, $offset + 4);
            if ($ip > $endIp) {
                $low = $middle + 1;
                continue;
            }

            $dataLength = self::getShort($content, $offset + 8);
            $dataPointer = self::getLong($content, $offset + 10);

            if ($dataLength <= 0 || $dataPointer < 0 || $dataPointer + $dataLength > strlen($content)) {
                return null;
            }

            return substr($content, $dataPointer, $dataLength);
        }

        return null;
    }

    /** @param resource $handle */
    private function readAt($handle, int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Invalid XDB read offset: ' . $offset);
        }

        self::$counters['file_reads']++;
        return self::readExact($handle, $length);
    }

    /**
     * Read little-endian uint32 from a binary string.
     */
    public static function getLong(string $buffer, int $offset)
    {
        $length = strlen($buffer);
        if ($offset < 0 || $offset + 4 > $length) {
            throw new RuntimeException('XDB uint32 read out of bounds.');
        }

        $value = ord($buffer[$offset])
            | (ord($buffer[$offset + 1]) << 8)
            | (ord($buffer[$offset + 2]) << 16)
            | (ord($buffer[$offset + 3]) << 24);

        if (PHP_INT_SIZE < 8 && $value < 0) {
            return sprintf('%u', $value);
        }

        return $value;
    }

    public static function getShort(string $buffer, int $offset): int
    {
        $length = strlen($buffer);
        if ($offset < 0 || $offset + 2 > $length) {
            throw new RuntimeException('XDB uint16 read out of bounds.');
        }

        return ord($buffer[$offset]) | (ord($buffer[$offset + 1]) << 8);
    }
}
