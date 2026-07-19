<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Search;

/**
 * Small, pure helpers for turning a visitor's raw query string into search
 * terms and backend-specific expressions. Kept separate so it can be unit
 * tested without a database.
 */
final class SearchQuery
{
    /**
     * Stop words that add noise to a fulltext query. Intentionally short and
     * bilingual (DE/EN) since those are the primary audiences here.
     *
     * @var list<string>
     */
    private const STOP_WORDS = [
        'der', 'die', 'das', 'und', 'oder', 'ein', 'eine', 'einen', 'wie', 'was',
        'wo', 'wer', 'ich', 'ist', 'für', 'mit', 'von', 'den', 'dem', 'auf',
        'the', 'and', 'or', 'a', 'an', 'how', 'what', 'where', 'who', 'is',
        'for', 'with', 'of', 'to', 'in', 'on',
    ];

    /**
     * Split a raw query into meaningful, de-duplicated search terms.
     *
     * @return list<string>
     */
    public static function terms(string $query, int $minLength = 2, int $maxTerms = 8): array
    {
        $query = trim(mb_strtolower($query));
        if ($query === '') {
            return [];
        }

        // Split on anything that is not a letter/digit (unicode aware).
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < $minLength) {
                continue;
            }
            if (in_array($part, self::STOP_WORDS, true)) {
                continue;
            }
            $terms[$part] = $part;
            if (count($terms) >= $maxTerms) {
                break;
            }
        }

        // If stop-word filtering removed everything (e.g. "wo ist das?"), fall
        // back to the longest raw token so the search still returns something.
        if ($terms === []) {
            foreach ($parts as $part) {
                if (mb_strlen($part) >= $minLength) {
                    $terms[$part] = $part;
                }
            }
        }

        return array_values($terms);
    }

    /**
     * Build a readable excerpt from a longer text, centred on the first place a
     * search term occurs, so the snippet shows the relevant passage instead of
     * the (often navigational) start of the page. Falls back to the beginning
     * when no term is found.
     *
     * @param list<string> $terms
     */
    public static function snippet(string $text, array $terms, int $maxLength = 320): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
        if ($text === '' || mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $lower = mb_strtolower($text);
        $pos = null;
        foreach ($terms as $term) {
            $found = mb_strpos($lower, mb_strtolower($term));
            if ($found !== false && ($pos === null || $found < $pos)) {
                $pos = $found;
            }
        }

        if ($pos === null) {
            return rtrim(mb_substr($text, 0, $maxLength - 1)) . '…';
        }

        // Centre a window around the first match, snapping the start to a word
        // boundary so the excerpt does not begin mid-word.
        $start = max(0, $pos - (int)floor(($maxLength - 40) / 2));
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            if ($space !== false && $space - $start < 25) {
                $start = $space + 1;
            }
        }

        $snippet = trim(mb_substr($text, $start, $maxLength));
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($start + $maxLength < mb_strlen($text)) {
            $snippet = rtrim($snippet) . '…';
        }

        return $snippet;
    }

    /**
     * Build a MySQL fulltext boolean-mode expression: each term is required and
     * gets a trailing wildcard for prefix matching, e.g. "+studium* +beginn*".
     *
     * @param list<string> $terms
     */
    public static function booleanMode(array $terms): string
    {
        $parts = [];
        foreach ($terms as $term) {
            $clean = str_replace(['+', '-', '*', '(', ')', '"', '~', '<', '>', '@'], '', $term);
            if ($clean === '') {
                continue;
            }
            $parts[] = '+' . $clean . '*';
        }
        return implode(' ', $parts);
    }
}
