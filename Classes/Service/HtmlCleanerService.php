<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

/**
 * Service for cleaning TYPO3 HTML output
 * Removes framework-specific markup and presentational elements
 */
class HtmlCleanerService
{
    /**
     * Clean up TYPO3-specific HTML before conversion to Markdown
     *
     * Removes common TYPO3 wrapper elements and presentational markup
     * while preserving semantic HTML structure (headings, links, lists, paragraphs).
     */
    public function cleanTypo3Html(string $html): string
    {
        // Step 0: Remove tags that shouldn't be in Markdown along with their content
        $html = preg_replace('/<(script|style|noscript|iframe|video|audio|picture|figure|source)[^>]*>.*?<\/\1>/is', '', $html);

        // Step 1: Normalize whitespace - collapse all internal whitespace to a single space
        $html = preg_replace('/\s+/u', ' ', $html);
        $html = preg_replace('/>\s+</u', '><', $html);

        // Step 2: Handle vertical structure
        // We only want newlines around real block elements that represent sections/items
        $blockElements = 'h1|h2|h3|h4|h5|h6|p|section|article|li|ul|ol|blockquote|table|tr';
        $html = preg_replace('/<(?:' . $blockElements . ')(?:\s[^>]*)?>/i', "\n$0", $html);
        $html = preg_replace('/<\/(?:' . $blockElements . ')>/i', "$0\n", $html);

        // For specific elements like count-box or skill, ensure they start on a new line 
        // but don't break their internal content (like numbers and labels)
        $html = preg_replace('/<(?:div|span)[^>]*class="[^"]*(?:count-box|skill|progress)[^"]*"[^>]*>/i', "\n$0", $html);

        return trim($html);
    }
}
