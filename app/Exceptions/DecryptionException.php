<?php

declare(strict_types=1);

namespace Passway\Exceptions;

use RuntimeException;

/**
 * Выбрасывается когда расшифровка не удалась.
 *
 * ВАЖНО: сообщение об ошибке намеренно лаконично —
 * не раскрывает причину (неверный ключ vs повреждённые данные).
 * Подробности только в audit log (никогда не в HTTP-ответе).
 */
final class DecryptionException extends RuntimeException {}
