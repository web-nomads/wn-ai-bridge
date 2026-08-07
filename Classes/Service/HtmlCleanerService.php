<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

/**
 * Normalises frontend HTML before it is handed to the Markdown converter.
 *
 * TYPO3 output carries a lot of layout scaffolding (wrapper elements, media
 * embeds, collapsed whitespace) that has no meaning in Markdown. This service
 * strips that noise and re-introduces line breaks around the block-level
 * elements that actually structure the document, so the downstream converter
 * receives clean, predictable input.
 */
class HtmlCleanerService
{
    /**
     * Tags whose whole subtree is irrelevant for a text representation and is
     * therefore dropped together with its content.
     */
    private const DISCARDABLE_TAGS = 'script|style|noscript|iframe|video|audio|picture|figure|source';

    /**
     * Block-level tags that must sit on their own line so the Markdown
     * converter can tell paragraphs, headings and list items apart.
     */
    private const BLOCK_TAGS = 'h1|h2|h3|h4|h5|h6|p|section|article|li|ul|ol|blockquote|table|tr';

    /**
     * Prepare TYPO3 frontend HTML for Markdown conversion while keeping the
     * semantic structure (headings, links, lists, paragraphs) intact.
     */
    public function cleanTypo3Html(string $html): string
    {
        $html = $this->dropNonTextualElements($html);
        $html = $this->collapseWhitespace($html);
        $html = $this->isolateBlockElements($html);

        return trim($html);
    }

    /**
     * Remove media/scripting containers along with everything they wrap.
     */
    private function dropNonTextualElements(string $html): string
    {
        return (string)preg_replace(
            '/<(' . self::DISCARDABLE_TAGS . ')[^>]*>.*?<\/\1>/is',
            '',
            $html
        );
    }

    /**
     * Squeeze runs of whitespace down to a single space and remove the gaps
     * that sit purely between adjacent tags.
     */
    private function collapseWhitespace(string $html): string
    {
        $html = (string)preg_replace('/\s+/u', ' ', $html);

        return (string)preg_replace('/>\s+</u', '><', $html);
    }

    /**
     * Put each block element on its own line and give a few metric widgets
     * (count boxes, skills, progress bars) a leading break without splitting
     * their inline label/number content.
     */
    private function isolateBlockElements(string $html): string
    {
        $html = (string)preg_replace('/<(?:' . self::BLOCK_TAGS . ')(?:\s[^>]*)?>/i', "\n$0", $html);
        $html = (string)preg_replace('/<\/(?:' . self::BLOCK_TAGS . ')>/i', "$0\n", $html);

        return (string)preg_replace(
            '/<(?:div|span)[^>]*class="[^"]*(?:count-box|skill|progress)[^"]*"[^>]*>/i',
            "\n$0",
            $html
        );
    }
}
