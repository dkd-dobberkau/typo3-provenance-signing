<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Canonicalization;

/**
 * JSON Canonicalization Scheme (RFC 8785) as restricted by Annex A.4:
 * integers only, so number serialisation is plain decimal.
 *
 * Records are handled as decoded with json_decode(..., false) so that empty
 * objects and empty arrays stay distinguishable.
 */
final class JsonCanonicalizer
{
    private const MAX_SAFE_INTEGER = 9007199254740991;

    /** A.4: canonical bytes of the record without its top-level "signature" */
    public function canonicalizeRecord(\stdClass $record): string
    {
        $unsigned = clone $record;
        unset($unsigned->signature);

        return $this->canonicalize($unsigned);
    }

    public function canonicalize(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_int($value) => $this->integer($value),
            is_float($value) => throw new \InvalidArgumentException('Records must not contain non-integer numbers (Annex A.4).', 1794600001),
            is_string($value) => $this->string($value),
            is_array($value) && array_is_list($value) => '[' . implode(',', array_map($this->canonicalize(...), $value)) . ']',
            $value instanceof \stdClass => $this->object(get_object_vars($value)),
            default => throw new \InvalidArgumentException('Unsupported JSON value of type ' . get_debug_type($value), 1794600002),
        };
    }

    private function integer(int $value): string
    {
        if (abs($value) > self::MAX_SAFE_INTEGER) {
            throw new \InvalidArgumentException('Integer outside the I-JSON range: ' . $value, 1794600003);
        }

        return (string)$value;
    }

    /**
     * Members sorted by the UTF-16 code units of their names (RFC 8785, 3.2.3).
     *
     * @param array<array-key, mixed> $members
     */
    private function object(array $members): string
    {
        $names = array_map('strval', array_keys($members));
        usort($names, static fn(string $a, string $b): int => strcmp(
            (string)mb_convert_encoding($a, 'UTF-16BE', 'UTF-8'),
            (string)mb_convert_encoding($b, 'UTF-16BE', 'UTF-8')
        ));

        $serialised = array_map(
            fn(string $name): string => $this->string($name) . ':' . $this->canonicalize($members[$name]),
            $names
        );

        return '{' . implode(',', $serialised) . '}';
    }

    private function string(string $value): string
    {
        $escaped = preg_replace_callback(
            '/["\\\\\x00-\x1F]/',
            static fn(array $match): string => match ($match[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                "\x08" => '\\b',
                "\x0C" => '\\f',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => sprintf('\\u%04x', ord($match[0])),
            },
            $value
        );

        return '"' . $escaped . '"';
    }
}
