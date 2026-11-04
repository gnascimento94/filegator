<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Tests\Unit;

use Filegator\Services\Archiver\Adapters\ZipArchiver;
use Filegator\Services\Archiver\ArchiveLimitException;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\Adapters\Tmpfs;
use League\Flysystem\Memory\MemoryAdapter;
use League\Flysystem\Adapter\NullAdapter;
use Tests\TestCase;

/**
 * @internal
 */
class ArchiverTest extends TestCase
{
    protected $archiver;

    protected function setUp(): void
    {
        $tmpfs = new Tmpfs();
        $tmpfs->init([
            'path' => TEST_TMP_PATH,
            'gc_probability_perc' => 10,
            'gc_older_than' => 60 * 60 * 24 * 2, // 2 days
        ]);

        $this->archiver = new ZipArchiver($tmpfs);

        parent::setUp();
    }

    public function testCreatingEmptyArchive()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new NullAdapter();
            },
        ]);

        $uniqid = $this->archiver->createArchive($storage);

        $this->assertNotNull($uniqid);
        $this->assertFileNotExists(TEST_TMP_PATH . $uniqid);
    }

    public function testAddingDirectoryWithFilesAndSubdir()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'test');
        $storage->createDir('/test', 'sub');
        $storage->createFile('/test', 'file1.txt');
        $storage->createFile('/test', 'file2.txt');

        $name = $this->archiver->createArchive($storage);
        $this->archiver->addDirectoryFromStorage('/test');
        $this->archiver->closeArchive();

        $this->assertGreaterThan(0, filesize(TEST_TMP_PATH . $name));
    }

    public function testUploadingArchiveToStorage()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $storage->createDir('/', 'test');
        $storage->createDir('/test', 'sub');
        $storage->createFile('/test', 'file1.txt');
        $storage->createFile('/test', 'file2.txt');

        $name = $this->archiver->createArchive($storage);
        $this->archiver->addDirectoryFromStorage('/test');
        $this->archiver->storeArchive('/destination', 'myarchive.zip');

        $this->assertFileNotExists(TEST_TMP_PATH . $name);
    }

    public function testUncompressingArchiveFromStorage()
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $stream = fopen(TEST_ARCHIVE, 'r');
        $storage->store('/', 'testarchive.zip', $stream);
        fclose($stream);

        $storage->createDir('/', 'result');

        $this->archiver->uncompress('/testarchive.zip', '/result', $storage);

        $this->assertStringContainsString('testarchive', json_encode($storage->getDirectoryCollection('/')));
        $this->assertStringContainsString('onetwo', json_encode($storage->getDirectoryCollection('/result')));
    }

    public function testUncompressReturnsTrueOnSuccess()
    {
        $storage = $this->storageWithArchive(TEST_ARCHIVE);

        $this->assertTrue($this->archiver->uncompress('/archive.zip', '/result', $storage));
    }

    public function testRejectsArchiveWithTooManyFiles()
    {
        $this->archiver->init(['max_files' => 3]);
        $storage = $this->storageWithArchive($this->makeZip([
            'a.txt' => 'a', 'b.txt' => 'b', 'c.txt' => 'c', 'd.txt' => 'd',
        ]));

        $this->assertRejected($storage, 'Archive exceeds the maximum number of files');
    }

    public function testRejectsArchiveOverMaxUncompressedSize()
    {
        $this->archiver->init(['max_uncompressed_size' => 1000, 'max_compression_ratio' => 0]);
        $storage = $this->storageWithArchive($this->makeZip([
            'big.txt' => random_bytes(2000),
        ]));

        $this->assertRejected($storage, 'Archive exceeds the maximum uncompressed size');
    }

    public function testRejectsArchiveOverMaxCompressionRatio()
    {
        $this->archiver->init(['max_compression_ratio' => 100]);
        $storage = $this->storageWithArchive($this->makeZip([
            'zeros.txt' => str_repeat("\0", 1024 * 1024),
        ]));

        $this->assertRejected($storage, 'Archive exceeds the maximum compression ratio');
    }

    public function testLimitsCanBeDisabled()
    {
        $this->archiver->init(['max_uncompressed_size' => 0, 'max_files' => 0, 'max_compression_ratio' => 0]);
        $storage = $this->storageWithArchive($this->makeZip([
            'zeros.txt' => str_repeat("\0", 1024 * 1024),
        ]));

        $this->assertTrue($this->archiver->uncompress('/archive.zip', '/result', $storage));
        $this->assertTrue($storage->fileExists('/result/zeros.txt'));
    }

    public function testRejectsInvalidArchive()
    {
        $file = TEST_TMP_PATH . 'notazip.zip';
        file_put_contents($file, 'this is not a zip file');
        $storage = $this->storageWithArchive($file);
        unlink($file);

        $this->assertRejected($storage, 'Archive is invalid or corrupted');
    }

    public function testRealExtractedSizeIsEnforcedAndRolledBack()
    {
        // simulate an archive whose metadata lies about the uncompressed size:
        // metadata checks pass, but the real bytes exceed the budget mid extraction
        $tmpfs = new Tmpfs();
        $tmpfs->init(['path' => TEST_TMP_PATH, 'gc_probability_perc' => 0, 'gc_older_than' => 0]);
        $archiver = new class($tmpfs) extends ZipArchiver {
            protected function checkArchiveLimits(\ZipArchive $zip, int $archive_size): array
            {
                return ['bytes' => 150, 'message' => 'Archive exceeds the maximum uncompressed size'];
            }
        };

        $storage = $this->storageWithArchive($this->makeZip([
            'sub/first.txt' => str_repeat('a', 100),
            'sub/deep/second.txt' => str_repeat('b', 100),
        ]));
        $storage->createDir('/result', 'existing');

        try {
            $archiver->uncompress('/archive.zip', '/result', $storage);
            $this->fail('Expected ArchiveLimitException');
        } catch (ArchiveLimitException $e) {
            $this->assertEquals('Archive exceeds the maximum uncompressed size', $e->getMessage());
        }

        $this->assertFalse($storage->fileExists('/result/sub/first.txt'));
        $this->assertFalse($storage->fileExists('/result/sub'));
        $this->assertTrue($storage->fileExists('/result/existing'));
        $this->assertTrue($storage->fileExists('/archive.zip'));
    }

    protected function assertRejected(Filesystem $storage, string $message)
    {
        try {
            $this->archiver->uncompress('/archive.zip', '/result', $storage);
            $this->fail('Expected ArchiveLimitException');
        } catch (ArchiveLimitException $e) {
            $this->assertEquals($message, $e->getMessage());
        }

        $listing = json_encode($storage->getDirectoryCollection('/result', true));
        $this->assertStringNotContainsString('.txt', $listing);
    }

    protected function storageWithArchive(string $zip_file): Filesystem
    {
        $storage = new Filesystem();
        $storage->init([
            'separator' => '/',
            'adapter' => function () {
                return new MemoryAdapter();
            },
        ]);

        $stream = fopen($zip_file, 'r');
        $storage->store('/', 'archive.zip', $stream);
        fclose($stream);

        $storage->createDir('/', 'result');

        return $storage;
    }

    protected function makeZip(array $files): string
    {
        $location = TEST_TMP_PATH . bin2hex(random_bytes(8)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($location, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        // the archive is copied into the storage, the local file is not needed anymore
        register_shutdown_function(function () use ($location) {
            if (file_exists($location)) {
                unlink($location);
            }
        });

        return $location;
    }
}
