<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Revision;

/**
 * What changed from one revision of a page to the next: "text" when the
 * canonical text changed, the names of record members whose values changed,
 * and "key" when another key signed it.
 */
final class RevisionChanges
{
    // Follow from the text and the key, which are named on their own.
    private const IGNORED_MEMBERS = ['signature', 'contentHash', 'key'];

    /**
     * @param array<string, mixed>|null $previous revision row, null for the first revision
     * @param array<string, mixed> $current revision row
     * @return list<string>
     */
    public function between(?array $previous, array $current): array
    {
        if ($previous === null) {
            return [];
        }

        $changes = [];
        if ($previous['content_hash'] !== $current['content_hash']) {
            $changes[] = 'text';
        }
        $before = $this->members($previous);
        $after = $this->members($current);
        $names = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($names, SORT_STRING);
        foreach ($names as $name) {
            if (!in_array($name, self::IGNORED_MEMBERS, true) && ($before[$name] ?? null) !== ($after[$name] ?? null)) {
                $changes[] = $name;
            }
        }
        if ($previous['key_id'] !== $current['key_id']) {
            $changes[] = 'key';
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $revision
     * @return array<string, string> each member's value as JSON, for comparison
     */
    private function members(array $revision): array
    {
        $record = json_decode((string)$revision['signed_record'], true);
        if (!is_array($record)) {
            return [];
        }

        return array_map(
            static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $record
        );
    }
}
