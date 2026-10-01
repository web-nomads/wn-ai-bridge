<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Event\AfterAiResponseEvent;
use B13\Aim\Response\TextResponse;

/**
 * Remembers which AiM provider configuration answered a request.
 *
 * AiM's response carries the model and the cost but not the provider or the
 * currency that cost is quoted in; both belong to the configuration that was
 * finally used, after any fallback, and only the response event names it.
 */
final class AimResponseTracker
{
    /** @var \WeakMap<TextResponse, ProviderConfiguration> */
    private \WeakMap $configurations;

    private ?ProviderConfiguration $last = null;

    public function __construct()
    {
        $this->configurations = new \WeakMap();
    }

    public function __invoke(AfterAiResponseEvent $event): void
    {
        $this->configurations[$event->response] = $event->configuration;
        $this->last = $event->configuration;
    }

    public function configurationFor(TextResponse $response): ?ProviderConfiguration
    {
        // last one as fallback, a middleware may hand back another response object
        return $this->configurations[$response] ?? $this->last;
    }
}
