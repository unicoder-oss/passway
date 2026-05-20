<?php

declare(strict_types=1);

namespace Passway\Services;

use Passway\Core\Database;
use Passway\Models\Secret;

/**
 * Minimal cron-like scheduler for secret rotation.
 */
final class SchedulerService
{
    /**
     * @return Secret[]
     */
    public function findDueSecrets(?\DateTimeImmutable $now = null): array
    {
        $now ??= now();

        $rows = Database::getInstance()->fetchAll(
            'SELECT * FROM secrets
             WHERE deleted_at IS NULL
               AND rotation_schedule IS NOT NULL
               AND rotation_schedule != \'\'
               AND type = ?',
            ['dynamic']
        );

        $due = [];

        foreach ($rows as $row) {
            $secret = Secret::fromRow($row);
            if ($this->isDue($secret->rotationSchedule, $now, $secret->lastRotatedAt)) {
                $due[] = $secret;
            }
        }

        return $due;
    }

    public function isDue(?string $expression, \DateTimeImmutable $now, ?string $lastRunAt = null): bool
    {
        if ($expression === null || \trim($expression) === '') {
            return false;
        }

        $normalizedNow = $now->setTime(
            (int) $now->format('H'),
            (int) $now->format('i'),
            0
        );

        if ($lastRunAt !== null) {
            $last = new \DateTimeImmutable($lastRunAt, new \DateTimeZone('UTC'));
            $normalizedLast = $last->setTime((int) $last->format('H'), (int) $last->format('i'), 0);
            if ($normalizedLast >= $normalizedNow) {
                return false;
            }
        }

        [$minute, $hour, $day, $month, $weekDay] = $this->parseExpression($expression);

        return $this->matchesField((int) $normalizedNow->format('i'), $minute)
            && $this->matchesField((int) $normalizedNow->format('G'), $hour)
            && $this->matchesField((int) $normalizedNow->format('j'), $day)
            && $this->matchesField((int) $normalizedNow->format('n'), $month)
            && $this->matchesField((int) $normalizedNow->format('w'), $weekDay);
    }

    /** @return array<int, string> */
    private function parseExpression(string $expression): array
    {
        $parts = \preg_split('/\s+/', \trim($expression)) ?: [];
        if (\count($parts) !== 5) {
            throw new \InvalidArgumentException(__('ui.backend.scheduler.cron_fields'));
        }

        return \array_values($parts);
    }

    private function matchesField(int $value, string $expression): bool
    {
        foreach (\explode(',', $expression) as $segment) {
            $segment = \trim($segment);
            if ($segment === '*') {
                return true;
            }

            if (\str_contains($segment, '/')) {
                [$base, $stepRaw] = \explode('/', $segment, 2);
                $step = (int) $stepRaw;
                if ($step <= 0) {
                    throw new \InvalidArgumentException(__('ui.backend.scheduler.cron_step_positive'));
                }

                if ($base === '*') {
                    if ($value % $step === 0) {
                        return true;
                    }
                    continue;
                }

                if (\str_contains($base, '-')) {
                    [$startRaw, $endRaw] = \explode('-', $base, 2);
                    $start = (int) $startRaw;
                    $end = (int) $endRaw;
                    if ($value >= $start && $value <= $end && (($value - $start) % $step === 0)) {
                        return true;
                    }
                }

                continue;
            }

            if (\str_contains($segment, '-')) {
                [$startRaw, $endRaw] = \explode('-', $segment, 2);
                $start = (int) $startRaw;
                $end = (int) $endRaw;
                if ($value >= $start && $value <= $end) {
                    return true;
                }
                continue;
            }

            if ((int) $segment === $value) {
                return true;
            }
        }

        return false;
    }
}
