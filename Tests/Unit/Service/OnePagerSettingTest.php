<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\Site;
use WebNomads\WnAiBridge\Service\ConfigurationService;

/**
 * Resolution of the per-site OnePager switch for llms.txt and the Markdown
 * export.
 *
 * A site whose sub-pages are real pages must produce real page URLs. The anchor
 * form ("/#packing-list") used to be applied to every direct child of the site
 * root, which turned working links into anchors that do not exist.
 */
final class OnePagerSettingTest extends TestCase
{
    /**
     * @param array<string, mixed> $configuration
     */
    #[Test]
    #[DataProvider('siteConfigurations')]
    public function theSwitchResolvesAsDocumented(array $configuration, bool $expected, string $why): void
    {
        self::assertSame($expected, $this->subject($configuration)->isLlmsTxtOnePagerEnabled(), $why);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool, 2: string}>
     */
    public static function siteConfigurations(): array
    {
        return [
            'nothing configured' => [
                [],
                false,
                'A site that never heard of OnePager must keep real page URLs.',
            ],
            'explicitly on' => [
                ['llmsTxtOnePager' => 1],
                true,
                'The new switch decides once it is set.',
            ],
            'explicitly off' => [
                ['llmsTxtOnePager' => 0],
                false,
                'An explicit off is an off.',
            ],
            'off wins over the assistant switch' => [
                ['llmsTxtOnePager' => 0, 'aiAssistantOnePager' => 1],
                false,
                'Once saved, the llms.txt switch is authoritative — no fallback.',
            ],
            'falls back to the assistant switch' => [
                ['aiAssistantOnePager' => 1],
                true,
                'Sites configured before the switch existed keep their behaviour.',
            ],
            'fallback to an assistant switch that is off' => [
                ['aiAssistantOnePager' => 0],
                false,
                '',
            ],
            'string values from the yaml file' => [
                ['llmsTxtOnePager' => '1'],
                true,
                'Site configuration is YAML — values arrive as strings.',
            ],
        ];
    }

    #[Test]
    public function theAssistantKeepsItsOwnSwitch(): void
    {
        // Both exist on purpose: the chat widget and llms.txt can disagree.
        $subject = $this->subject(['llmsTxtOnePager' => 1, 'aiAssistantOnePager' => 0]);

        self::assertTrue($subject->isLlmsTxtOnePagerEnabled());
        self::assertFalse($subject->isAssistantOnePagerEnabled());
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function subject(array $configuration): ConfigurationService
    {
        $site = new Site('test', 1, array_merge(['base' => 'https://example.com/'], $configuration));

        return new class ($site) extends ConfigurationService {
            public function __construct(private readonly Site $site) {}

            protected function getCurrentSite(): ?Site
            {
                return $this->site;
            }
        };
    }
}
