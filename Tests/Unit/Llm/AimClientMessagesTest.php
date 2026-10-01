<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Llm;

use B13\Aim\Request\Message\AssistantMessage;
use B13\Aim\Request\Message\UserMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Llm\AimClient;
use WebNomads\WnAiBridge\Llm\LlmException;

/**
 * The assistant keeps its conversation as role/content pairs; AiM wants typed
 * messages. Everything a provider would reject is caught here, before a request
 * is sent and billed.
 */
final class AimClientMessagesTest extends TestCase
{
    #[Test]
    public function rolesBecomeTypedMessages(): void
    {
        $messages = AimClient::toAimMessages([
            ['role' => 'user', 'content' => 'Wann beginnt das Studium?'],
            ['role' => 'assistant', 'content' => 'Im Herbst.'],
            ['role' => 'user', 'content' => 'Und die Anmeldung?'],
        ]);

        self::assertCount(3, $messages);
        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        self::assertInstanceOf(UserMessage::class, $messages[2]);
        self::assertSame('Im Herbst.', $messages[1]->content);
    }

    #[Test]
    public function anUnknownRoleIsTreatedAsTheVisitor(): void
    {
        $messages = AimClient::toAimMessages([['role' => 'system', 'content' => 'Hallo']]);

        self::assertInstanceOf(UserMessage::class, $messages[0]);
    }

    #[Test]
    public function emptyTurnsAreDroppedAndTheRestIsTrimmed(): void
    {
        $messages = AimClient::toAimMessages([
            ['role' => 'user', 'content' => '   '],
            ['role' => 'user', 'content' => '  Frage  '],
        ]);

        self::assertCount(1, $messages);
        self::assertSame('Frage', $messages[0]->content);
    }

    #[Test]
    public function aConversationOpenedByTheAssistantIsRefused(): void
    {
        $this->expectException(LlmException::class);
        $this->expectExceptionCode(1765400004);

        AimClient::toAimMessages([['role' => 'assistant', 'content' => 'Guten Tag']]);
    }

    #[Test]
    public function anEmptyConversationIsRefused(): void
    {
        $this->expectException(LlmException::class);

        AimClient::toAimMessages([]);
    }
}
