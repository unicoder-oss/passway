<?php

declare(strict_types=1);

namespace Passway\Models;

use Passway\Core\Database;

/**
 * Тонкая модель ревьювера запроса на одобрение.
 *
 * Структура таблицы approval_reviewers:
 *   - id, approval_request_id, reviewer_id
 *   - notified_at — когда было отправлено уведомление
 *   - created_at
 */
final class ApprovalReviewer
{
    public function __construct(
        public readonly string  $id,
        public readonly string  $approvalRequestId,
        public readonly string  $reviewerId,
        public readonly ?string $notifiedAt,
        public readonly string  $createdAt,
    ) {}

    // ------------------------------------------------------------------ //
    //  Фабрика                                                            //
    // ------------------------------------------------------------------ //

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                (string) $row['id'],
            approvalRequestId: (string) $row['approval_request_id'],
            reviewerId:        (string) $row['reviewer_id'],
            notifiedAt:        isset($row['notified_at']) && $row['notified_at'] !== null
                ? (string) $row['notified_at'] : null,
            createdAt:         (string) $row['created_at'],
        );
    }

    // ------------------------------------------------------------------ //
    //  Запросы                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Все ревьюверы для данного запроса.
     *
     * @return self[]
     */
    public static function findByRequestId(string $requestId): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM approval_reviewers WHERE approval_request_id = ?',
            [(int) $requestId]
        );
        return \array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Проверить, является ли пользователь ревьювером запроса.
     */
    public static function isReviewer(string $requestId, string $userId): bool
    {
        $count = (int) Database::getInstance()->fetchColumn(
            'SELECT COUNT(*) FROM approval_reviewers WHERE approval_request_id = ? AND reviewer_id = ?',
            [(int) $requestId, (int) $userId]
        );
        return $count > 0;
    }
}
