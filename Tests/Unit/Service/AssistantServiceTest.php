<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Llm\LlmClientInterface;
use WebNomads\WnAiBridge\Llm\LlmException;
use WebNomads\WnAiBridge\Search\SearchProviderInterface;
use WebNomads\WnAiBridge\Search\SearchService;
use WebNomads\WnAiBridge\Service\AssistantService;
use WebNomads\WnAiBridge\Service\ConfigurationService;

class AssistantServiceTest extends TestCase
{
    /**
     * @param list<SearchResultItem> $results
     */
    private function searchService(array $results, ConfigurationService $configuration): SearchService
    {
        $provider = new class ($results) implements SearchProviderInterface {
            /** @param list<SearchResultItem> $results */
            public function __construct(private readonly array $results) {}
            public function getKey(): string
            {
                return 'pages';
            }
            public function isAvailable(): bool
            {
                return true;
            }
            public function search(string $query, int $limit, int $languageId, int $rootPageId = 0): array
            {
                return array_slice($this->results, 0, $limit);
            }
        };

        return new SearchService([$provider], $configuration);
    }

    private function llmClient(?string $answer, ?\Throwable $throws = null): LlmClientInterface
    {
        return new class ($answer, $throws) implements LlmClientInterface {
            public function __construct(private readonly ?string $answer, private readonly ?\Throwable $throws) {}
            public function getProviderKey(): string
            {
                return 'anthropic';
            }
            public function complete(string $systemPrompt, array $messages, string $model, int $maxTokens): string
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return (string)$this->answer;
            }
        };
    }

    private function configuration(bool $llmConfigured): ConfigurationService
    {
        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->method('getAssistantSearchSources')->willReturn('auto');
        $configuration->method('getAssistantMaxResults')->willReturn(5);
        $configuration->method('getAssistantSearchRootPageId')->willReturn(0);
        $configuration->method('isAssistantLlmConfigured')->willReturn($llmConfigured);
        $configuration->method('getAssistantProvider')->willReturn('anthropic');
        $configuration->method('getAssistantModel')->willReturn('claude-haiku-4-5');
        $configuration->method('getAssistantMaxTokens')->willReturn(1024);
        $configuration->method('getAssistantSystemPrompt')->willReturn('');
        $configuration->method('getSiteName')->willReturn('Test Site');
        return $configuration;
    }

    /**
     * @return list<SearchResultItem>
     */
    private function sampleResults(): array
    {
        return [
            SearchResultItem::create('Studium', 'https://example.com/studium', 'Alles zum Studium', 5.0, 'pages', 10),
        ];
    }

    #[Test]
    public function returnsNoResultsMessageWhenNothingFound(): void
    {
        $configuration = $this->configuration(llmConfigured: false);
        $service = new AssistantService(
            $this->searchService([], $configuration),
            $configuration,
            $this->llmClient('unused'),
        );

        $response = $service->ask('etwas völlig unbekanntes', [], 0);

        self::assertSame('search', $response->mode);
        self::assertSame([], $response->sources);
        self::assertStringContainsString('nichts', mb_strtolower($response->answer));
    }

    #[Test]
    public function returnsSearchOnlyResponseWhenLlmNotConfigured(): void
    {
        $configuration = $this->configuration(llmConfigured: false);
        $service = new AssistantService(
            $this->searchService($this->sampleResults(), $configuration),
            $configuration,
            $this->llmClient('should not be used'),
        );

        $response = $service->ask('Studium', [], 0);

        self::assertSame('search', $response->mode);
        self::assertCount(1, $response->sources);
        self::assertSame('https://example.com/studium', $response->sources[0]->url);
    }

    #[Test]
    public function returnsLlmAnswerWhenConfigured(): void
    {
        $configuration = $this->configuration(llmConfigured: true);
        $service = new AssistantService(
            $this->searchService($this->sampleResults(), $configuration),
            $configuration,
            $this->llmClient('Das Studium beginnt im Herbst [1].'),
        );

        $response = $service->ask('Wann beginnt das Studium?', [], 0);

        self::assertSame('llm', $response->mode);
        self::assertSame('Das Studium beginnt im Herbst [1].', $response->answer);
        self::assertCount(1, $response->sources);
    }

    #[Test]
    public function fallsBackToSearchWhenLlmFails(): void
    {
        $configuration = $this->configuration(llmConfigured: true);
        $service = new AssistantService(
            $this->searchService($this->sampleResults(), $configuration),
            $configuration,
            $this->llmClient(null, new LlmException('boom')),
        );

        $response = $service->ask('Studium', [], 0);

        // LLM outage must never break the widget — degrade to search results.
        self::assertSame('search', $response->mode);
        self::assertCount(1, $response->sources);
    }
}
