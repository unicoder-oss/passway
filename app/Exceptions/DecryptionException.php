<?php

declare(strict_types=1);

namespace Passway\Exceptions;

use RuntimeException;

/**
 * Thrown when decryption fails.
 *
 * IMPORTANT: the error message is intentionally terse -
 * does not disclose the reason (wrong key vs corrupted data).
 * Details only in the audit log (never in the HTTP response).
 */
final class DecryptionException extends RuntimeException {}
