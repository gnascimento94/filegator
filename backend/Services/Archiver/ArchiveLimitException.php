<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Services\Archiver;

/**
 * Thrown when an archive is rejected (zip bomb limits exceeded or unreadable archive).
 * The message is safe to show to the user.
 */
class ArchiveLimitException extends \Exception {}
