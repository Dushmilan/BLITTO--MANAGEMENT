<?php

namespace Tests\Support;

/**
 * Source-scan detector for writes to the `deadlines` table
 * (CONTEXT.md invariant 4 — docketing is the sole authority on deadlines).
 *
 * Used by architecture tests to prove no module outside the docketing
 * domain creates or mutates deadline rows.
 */
class DeadlineWriteDetector
{
    /**
     * Patterns that mutate deadline rows (creation, update, deletion).
     *
     * @var list<string>
     */
    private const WRITE_PATTERNS = [
        '/\bDeadline::\s*(create|insert|upsert|forceCreate|updateOrCreate|firstOrCreate|update|delete|destroy|query)\s*\(/',
        '/\bnew\s+Deadline\s*\(/',
        '/->deadlines\(\)->\s*\w*\(.*->\s*(save|update|delete|create|insert|upsert)\s*\(/',
        '/->deadlines\(\)\s*->\s*(save|update|delete|create|insert|upsert)\s*\(/',
        '/DB::table\s*\(\s*[\'"]deadlines[\'"]\s*\)/',
        '/\$deadline\s*->\s*(save|update|delete|fill|forceFill)\s*\(/',
    ];

    /**
     * Heuristic: does this snippet look like a write to deadline rows?
     * Reads (find, where, property access, relation definitions) pass through.
     */
    public static function isWrite(string $snippet): bool
    {
        // Deadline::query() only opens a builder — a write needs a chained
        // mutator on the same line.
        $queryLine = preg_match('/\bDeadline::\s*query\s*\(/', $snippet) === 1;

        foreach (self::WRITE_PATTERNS as $pattern) {
            if (preg_match($pattern, $snippet) === 1) {
                if ($queryLine && str_contains($pattern, 'query')) {
                    // Deadline::query() alone is a read; require a mutator chain.
                    return preg_match('/->\s*(create|insert|upsert|update|delete|save|firstOrCreate|updateOrCreate)\s*\(/', $snippet) === 1;
                }

                return true;
            }
        }

        return false;
    }
}
