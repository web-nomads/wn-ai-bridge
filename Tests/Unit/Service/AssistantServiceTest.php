<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WebNomads\WnAiBridge\Domain\Repository\AssistantLearningRepository;
use WebNomads\WnAiBridge\Dto\SearchResultItem;
use WebNomads\WnAiBridge\Llm\LlmClientInterface;
use WebNomads\WnAiBridge\Llm\LlmException;
use WebNomads\WnAiBridge\Llm\LlmResult;
use WebNomads\WnAiBridge\Search\SearchProviderInterface;
use WebNomads\WnAiBridge\Search\SearchService;
use WebNomads\WnAiBridge\Security\PageAccessService;
use WebNomads\WnAiBridge\Service\AssistantService;
use WebNomads\WnAiBridge\Service\ConfigurationService;
use WebNomads\WnAiBridge\Service\LearningService;

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

        // Access is decided elsewhere and covered by its own test; here every
        // page is open so the assistant logic is what is being looked at.
        $pageAccess = new class () extends PageAccessService {
            public function __construct() {}

            public function isAccessible(int $pageId): bool
            {
                return true;
            }
        };

        return new SearchService([$provider], $configuration, $pageAccess);
    }

    private function llmClient(?string $answer, ?\Throwable $throws = null): LlmClientInterface
    {
        return new class ($answer, $throws) implements LlmClientInterface {
            public function __construct(private readonly ?string $answer, private readonly ?\Throwable $throws) {}
            public function getProviderKey(): string
            {
                return 'anthropic';
            }
            public function complete(string $systemPrompt, array $messages, string $model, int $maxTokens, ?float $temperature = null): LlmResult
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return new LlmResult((string)$this->answer, 12, 34);
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
        $configuration->method('getAssistantTemperature')->willReturn(0.7);
        $configuration->method('getAssistantInstructions')->willReturn('');
        $configuration->method('getAssistantSystemPrompt')->willReturn('');
        $configuration->method('getSiteName')->willReturn('Test Site');
        $configuration->method('isAssistantLearningEnabled')->willReturn(false);
        return $configuration;
    }

    /**
     * Learning disabled — no correction capture, no prompt injection.
     */
    private function learningService(ConfigurationService $configuration): LearningService
    {
        return new LearningService(
            $configuration,
            new AssistantLearningRepository($this->createMock(ConnectionPool::class)),
        );
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
            $this->learningService($configuration),
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
            $this->learningService($configuration),
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
            $this->learningService($configuration),
        );

        $response = $service->ask('Wann beginnt das Studium?', [], 0);

        self::assertSame('llm', $response->mode);
        self::assertSame('Das Studium beginnt im Herbst [1].', $response->answer);
        self::assertCount(1, $response->sources);
        // Provider/model and token usage are captured for logging.
        self::assertSame('anthropic', $response->provider);
        self::assertSame('claude-haiku-4-5', $response->model);
        self::assertSame(12, $response->inputTokens);
        self::assertSame(34, $response->outputTokens);
    }

    #[Test]
    public function fallsBackToSearchWhenLlmFails(): void
    {
        $configuration = $this->configuration(llmConfigured: true);
        $service = new AssistantService(
            $this->searchService($this->sampleResults(), $configuration),
            $configuration,
            $this->llmClient(null, new LlmException('boom')),
            $this->learningService($configuration),
        );

        $response = $service->ask('Studium', [], 0);

        // LLM outage must never break the widget — degrade to search results.
        self::assertSame('search', $response->mode);
        self::assertCount(1, $response->sources);
    }
}
