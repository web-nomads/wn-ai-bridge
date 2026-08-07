<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Llm;

/**
 * Thrown when an LLM completion cannot be produced (misconfiguration, network
 * error, API error). The assistant catches this and falls back to search-only
 * results, so a failing LLM never breaks the visitor experience.
 */
final class LlmException extends \RuntimeException {}
