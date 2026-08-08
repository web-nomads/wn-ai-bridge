<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "Web version" label under a Markdown page.
 *
 * It is rendered into the page's own language, so a missing translation shows an
 * English word on an otherwise German page. Cheap to check, easy to forget when
 * a language is added.
 */
final class WebVersionLabelTest extends TestCase
{
    private const KEY = 'markdown.webVersion';

    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';

    #[Test]
    public function theSourceLabelExists(): void
    {
        $units = $this->transUnits(self::LANGUAGE_DIR . 'locallang_widget.xlf');

        self::assertArrayHasKey(self::KEY, $units);
        self::assertSame('Web version', $units[self::KEY]['source']);
    }

    #[Test]
    #[DataProvider('translationFiles')]
    public function everyShippedLanguageTranslatesIt(string $file): void
    {
        $units = $this->transUnits($file);

        self::assertArrayHasKey(self::KEY, $units, basename($file) . ' is missing ' . self::KEY);
        self::assertNotSame(
            '',
            trim($units[self::KEY]['target'] ?? ''),
            basename($file) . ' has an empty target for ' . self::KEY
        );
    }

    #[Test]
    public function germanKeepsTheEstablishedWording(): void
    {
        $units = $this->transUnits(self::LANGUAGE_DIR . 'de.locallang_widget.xlf');

        // One word in German — "Web Version" would be an anglicism.
        self::assertSame('Webversion', $units[self::KEY]['target']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function translationFiles(): array
    {
        $files = glob(self::LANGUAGE_DIR . '*.locallang_widget.xlf') ?: [];
        self::assertNotEmpty($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    /**
     * @return array<string, array{source: string, target: string|null}>
     */
    private function transUnits(string $file): array
    {
        self::assertFileExists($file);

        $xml = simplexml_load_file($file);
        self::assertNotFalse($xml, $file . ' is not valid XML');

        $units = [];
        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $id = (string)$unit['id'];
            $units[$id] = [
                'source' => (string)$unit->source,
                'target' => isset($unit->target) ? (string)$unit->target : null,
            ];
        }

        return $units;
    }
}
