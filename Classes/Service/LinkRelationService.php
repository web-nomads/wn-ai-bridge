<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The two link relations llms.txt v2 asks for, so an agent holding a page never
 * has to guess where its machine-readable companions are: ``alternate`` with
 * type ``text/markdown`` points at the Markdown version of the page,
 * ``describedby`` at the llms.txt covering it.
 *
 * The pair is published twice — as ``<link>`` elements in the page head and as
 * an HTTP ``Link:`` response header. The header is not a duplicate: it is the
 * only form the .md and llms-full.txt responses can carry, and the only one a
 * HEAD request sees.
 *
 * @see https://llmstxt.org/
 */
final class LinkRelationService
{
    /**
     * Media type v2 names for the Markdown versions.
     */
    public const MARKDOWN_MEDIA_TYPE = 'text/markdown';

    /**
     * The suffix the shipped route enhancer maps to the Markdown page type.
     *
     * @see \WebNomads\WnAiBridge\Controller\LlmsTxtController::MARKDOWN_SUFFIX
     */
    public const MARKDOWN_SUFFIX = '.md';

    /**
     * File name a URL without one gets before the suffix ("/" -> "/index.md"),
     * as the spec requires. It is also what TYPO3's PageType enhancer generates
     * for the root page, so both ends agree without further configuration.
     */
    public const INDEX_SEGMENT = 'index';

    public const LLMS_TXT_FILE = 'llms.txt';

    public const LLMS_FULL_TXT_FILE = 'llms-full.txt';

    /**
     * The Markdown version of the requested URL. Empty when there is none to
     * point at: the request is already for a generated document, or its
     * arguments are part of the page's identity (a cHash), in which case the
     * path alone would resolve to a different page than the one being rendered.
     */
    public function markdownUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';

        if (self::isGeneratedDocument($path)) {
            return '';
        }

        parse_str($uri->getQuery(), $arguments);
        if (isset($arguments['cHash'])) {
            return '';
        }

        return (string)$uri
            ->withPath(self::markdownPath($path))
            ->withQuery('')
            ->withFragment('');
    }

    /**
     * The llms.txt covering the requested URL: the one of the current site
     * language, since that is the more specific file for a page below its base
     * and v2 has agents prefer the most specific.
     */
    public function llmsTxtUrl(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        return (string)$uri
            ->withPath(self::basePath($request) . self::LLMS_TXT_FILE)
            ->withQuery('')
            ->withFragment('');
    }

    /**
     * The value of the ``Link:`` response header, empty when the request needs
     * neither relation.
     */
    public function headerValue(ServerRequestInterface $request): string
    {
        $links = [];

        $markdownUrl = $this->markdownUrl($request);
        if ($markdownUrl !== '') {
            $links[] = '<' . $markdownUrl . '>; rel="alternate"; type="' . self::MARKDOWN_MEDIA_TYPE . '"';
        }

        if (!self::isLlmsTxt($request->getUri()->getPath())) {
            $links[] = '<' . $this->llmsTxtUrl($request) . '>; rel="describedby"';
        }

        return implode(', ', $links);
    }

    /**
     * The same relations as ``<link>`` elements for the page head, one per line.
     */
    public function linkTags(ServerRequestInterface $request): string
    {
        $tags = [];

        $markdownUrl = $this->markdownUrl($request);
        if ($markdownUrl !== '') {
            $tags[] = '<link rel="alternate" type="' . self::MARKDOWN_MEDIA_TYPE
                . '" href="' . self::escape($markdownUrl) . '">';
        }

        $tags[] = '<link rel="describedby" href="' . self::escape($this->llmsTxtUrl($request)) . '">';

        return implode("\n", $tags);
    }

    /**
     * The Markdown path for a page path: the suffix appended, with the index
     * file name filled in where the URL has none.
     */
    public static function markdownPath(string $path): string
    {
        if ($path === '' || str_ends_with($path, '/')) {
            return $path . self::INDEX_SEGMENT . self::MARKDOWN_SUFFIX;
        }

        return $path . self::MARKDOWN_SUFFIX;
    }

    /**
     * Whether a path is one of the documents this extension generates, rather
     * than a page that could have a Markdown version of its own.
     */
    public static function isGeneratedDocument(string $path): bool
    {
        return str_ends_with($path, self::MARKDOWN_SUFFIX)
            || self::isLlmsTxt($path)
            || self::endsWithFile($path, self::LLMS_FULL_TXT_FILE);
    }

    public static function isLlmsTxt(string $path): bool
    {
        return self::endsWithFile($path, self::LLMS_TXT_FILE);
    }

    private static function endsWithFile(string $path, string $file): bool
    {
        $path = rtrim($path, '/');

        return $path === '/' . $file || str_ends_with($path, '/' . $file);
    }

    /**
     * The path the current site language is served under, always with a
     * trailing slash ("/", "/en/", "/camino/en/").
     */
    private static function basePath(ServerRequestInterface $request): string
    {
        $language = $request->getAttribute('language');
        if ($language instanceof SiteLanguage) {
            return self::normalisePath($language->getBase()->getPath());
        }

        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            return self::normalisePath($site->getBase()->getPath());
        }

        return '/';
    }

    private static function normalisePath(string $path): string
    {
        $path = trim($path, '/');

        return $path === '' ? '/' : '/' . $path . '/';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
