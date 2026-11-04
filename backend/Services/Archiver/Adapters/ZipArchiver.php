<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Archiver\Adapters;

use Filegator\Services\Archiver\ArchiveLimitException;
use Filegator\Services\Archiver\ArchiverInterface;
use Filegator\Services\Service;
use Filegator\Services\Storage\Filesystem as Storage;
use Filegator\Services\Tmpfs\TmpfsInterface;
use League\Flysystem\Config as Flyconfig;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\ZipArchive\ZipArchiveAdapter;

class ZipArchiver implements Service, ArchiverInterface
{
    protected $archive;

    protected $storage;

    protected $tmpfs;

    protected $uniqid;

    protected $tmp_files = [];

    protected $max_uncompressed_size = 1024 * 1024 * 1024;

    protected $max_files = 1000;

    protected $max_compression_ratio = 100;

    public function __construct(TmpfsInterface $tmpfs)
    {
        $this->tmpfs = $tmpfs;
    }

    public function init(array $config = [])
    {
        // zip bomb protection limits, 0 = unlimited
        $this->max_uncompressed_size = (int) ($config['max_uncompressed_size'] ?? 1024 * 1024 * 1024);
        $this->max_files = (int) ($config['max_files'] ?? 1000);
        $this->max_compression_ratio = (float) ($config['max_compression_ratio'] ?? 100);
    }

    public function createArchive(Storage $storage): string
    {
        $this->uniqid = bin2hex(random_bytes(32));

        $this->archive = new Flysystem(
            new ZipAdapter($this->tmpfs->getFileLocation($this->uniqid))
        );

        $this->storage = $storage;

        return $this->uniqid;
    }

    public function addDirectoryFromStorage(string $path)
    {
        $content = $this->storage->getDirectoryCollection($path, true);
        $this->archive->createDir($path);

        foreach ($content->all() as $item) {
            if ($item['type'] == 'dir') {
                $this->archive->createDir($item['path']);
            }
            if ($item['type'] == 'file') {
                $this->addFileFromStorage($item['path']);
            }
        }
    }

    public function addFileFromStorage(string $path)
    {
        $file_uniqid = bin2hex(random_bytes(16));

        $file = $this->storage->readStream($path);

        $this->tmpfs->write($file_uniqid, $file['stream']);

        $this->archive->write($path, $this->tmpfs->getFileLocation($file_uniqid));

        $this->tmp_files[] = $file_uniqid;
    }

    public function uncompress(string $source, string $destination, Storage $storage)
    {
        $name = bin2hex(random_bytes(16)) . '.zip';

        $remote_archive = $storage->readStream($source);
        $this->tmpfs->write($name, $remote_archive['stream']);
        if (is_resource($remote_archive['stream'])) {
            fclose($remote_archive['stream']);
        }

        $archive = null;
        $created_files = [];
        $created_dirs = [];

        try {
            try {
                $archive = new Flysystem(
                    new ZipAdapter($this->tmpfs->getFileLocation($name))
                );
            } catch (\Throwable $e) {
                throw new ArchiveLimitException('Archive is invalid or corrupted');
            }

            $archive_size = (int) filesize($this->tmpfs->getFileLocation($name));
            $budget = $this->checkArchiveLimits($archive->getAdapter()->getArchive(), $archive_size);
            $extracted = 0;

            foreach ($archive->listContents('/', true) as $item) {
                if ($item['type'] == 'dir') {
                    $dir = $this->joinPath($storage, $destination, $item['path']);
                    if (! $storage->fileExists($dir)) {
                        $created_dirs = array_merge($created_dirs, $this->findMissingDirs($storage, $destination, $item['path']));
                        $storage->createDir($destination, $item['path']);
                    }
                }
                if ($item['type'] == 'file') {
                    $created_dirs = array_merge($created_dirs, $this->findMissingDirs($storage, $destination, $item['dirname']));
                    $extracted += $this->extractFile($archive, $item, $storage, $destination, $budget['bytes'] - $extracted, $created_files);
                    if ($extracted > $budget['bytes']) {
                        // real extracted size is larger than what the archive declares
                        throw new ArchiveLimitException($budget['message']);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->rollback($storage, $created_files, $created_dirs);

            throw $e;
        } finally {
            if ($archive) {
                $archive->getAdapter()->getArchive()->close();
            }
            $this->tmpfs->remove($name);
        }

        return true;
    }

    /**
     * Checks the declared archive metadata against the configured limits
     * and returns the maximum number of bytes allowed to be extracted.
     */
    protected function checkArchiveLimits(\ZipArchive $zip, int $archive_size): array
    {
        if ($this->max_files > 0 && $zip->numFiles > $this->max_files) {
            throw new ArchiveLimitException('Archive exceeds the maximum number of files');
        }

        $declared_size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new ArchiveLimitException('Archive is invalid or corrupted');
            }
            $declared_size += $stat['size'];
        }

        $budget = ['bytes' => PHP_INT_MAX, 'message' => ''];

        if ($this->max_uncompressed_size > 0) {
            if ($declared_size > $this->max_uncompressed_size) {
                throw new ArchiveLimitException('Archive exceeds the maximum uncompressed size');
            }
            $budget = ['bytes' => $this->max_uncompressed_size, 'message' => 'Archive exceeds the maximum uncompressed size'];
        }

        if ($this->max_compression_ratio > 0) {
            $max_by_ratio = (int) min(PHP_INT_MAX, floor($archive_size * $this->max_compression_ratio));
            if ($declared_size > $max_by_ratio) {
                throw new ArchiveLimitException('Archive exceeds the maximum compression ratio');
            }
            if ($max_by_ratio < $budget['bytes']) {
                $budget = ['bytes' => $max_by_ratio, 'message' => 'Archive exceeds the maximum compression ratio'];
            }
        }

        return $budget;
    }

    /**
     * Extracts one file, never writing more than $remaining + 1 bytes,
     * and returns the number of bytes written.
     */
    protected function extractFile(Flysystem $archive, array $item, Storage $storage, string $destination, int $remaining, array &$created_files): int
    {
        $tmp_name = bin2hex(random_bytes(16));
        $tmp_location = $this->tmpfs->getFileLocation($tmp_name);
        $stream = $archive->readStream($item['path']);
        $tmp = fopen($tmp_location, 'w+b');

        try {
            if (! is_resource($stream) || ! is_resource($tmp)) {
                throw new ArchiveLimitException('Archive is invalid or corrupted');
            }

            // copy at most one byte over the limit, enough to detect the overflow
            $written = stream_copy_to_stream($stream, $tmp, $remaining < PHP_INT_MAX ? $remaining + 1 : null);
            if ($written === false) {
                throw new ArchiveLimitException('Archive is invalid or corrupted');
            }
            if ($written > $remaining) {
                return $written;
            }

            rewind($tmp);
            $stored = $storage->storeFile($this->joinPath($storage, $destination, $item['dirname']), $item['basename'], $tmp);
            if ($stored === null) {
                throw new \Exception('Unable to store ' . $item['path']);
            }
            $created_files[] = $stored;

            return $written;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_resource($tmp)) {
                fclose($tmp);
            }
            if ($this->tmpfs->exists($tmp_name)) {
                $this->tmpfs->remove($tmp_name);
            }
        }
    }

    /**
     * Returns the folders between $destination and $destination/$relative that do not exist yet
     */
    protected function findMissingDirs(Storage $storage, string $destination, string $relative): array
    {
        $missing = [];
        $path = $destination;
        $separator = $storage->getSeparator();

        foreach (explode($separator, trim($relative, $separator)) as $segment) {
            if ($segment === '') {
                continue;
            }
            $path = $this->joinPath($storage, $path, $segment);
            if (! $storage->fileExists($path)) {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    /**
     * Removes everything extracted so far
     */
    protected function rollback(Storage $storage, array $created_files, array $created_dirs)
    {
        foreach ($created_files as $file) {
            try {
                $storage->deleteFile($file);
            } catch (\Throwable $e) {
                continue;
            }
        }

        // deepest folders first
        $created_dirs = array_unique($created_dirs);
        usort($created_dirs, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($created_dirs as $dir) {
            try {
                if ($storage->fileExists($dir)) {
                    $storage->deleteDir($dir);
                }
            } catch (\Throwable $e) {
                continue;
            }
        }
    }

    protected function joinPath(Storage $storage, string $path, string $name): string
    {
        $separator = $storage->getSeparator();

        return rtrim($path, $separator) . $separator . trim($name, $separator);
    }

    public function closeArchive()
    {
        $this->archive->getAdapter()->getArchive()->close();

        foreach ($this->tmp_files as $file) {
            $this->tmpfs->remove($file);
        }
    }

    public function storeArchive($destination, $name)
    {
        $this->closeArchive();

        $file = $this->tmpfs->readStream($this->uniqid);
        $this->storage->store($destination, $name, $file['stream']);
        if (is_resource($file['stream'])) {
            fclose($file['stream']);
        }

        $this->tmpfs->remove($this->uniqid);
    }
}

class ZipAdapter extends ZipArchiveAdapter
{
    public function write($path, $contents, Flyconfig $config)
    {
        $location = $this->applyPathPrefix($path);

        // using addFile instead of addFromString
        // is more memory efficient
        $this->archive->addFile($contents, $location);

        return compact('path', 'contents');
    }
}
