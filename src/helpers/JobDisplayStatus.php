<?php
namespace skeeks\cms\job\helpers;

/** Presentation only. Never use this status for claiming, retries or cancellation. */
final class JobDisplayStatus
{
    public const METADATA_KEY = '_job_execution';
    public const STALE = 'awaiting_confirmation';
    public const MAX_AGE = 120;

    public static function resolve(string $status, array $result, ?int $now = null): string
    {
        if ($status !== 'queued') { return $status; }
        $execution = $result[self::METADATA_KEY] ?? null;
        if (!is_array($execution) || ($execution['state'] ?? null) !== 'running') { return $status; }
        $observed = $execution['observed_at'] ?? null;
        $now = $now ?? time();
        if (!is_int($observed) || $observed > $now || $now - $observed > self::MAX_AGE) { return self::STALE; }
        return 'running';
    }
}
