<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use WebNomads\WnAiBridge\Security\BotDetectionService;

class BotDetectionServiceTest extends TestCase
{
    private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36';

    /**
     * @param array<string, string> $headers
     */
    private function request(array $headers, string $host = 'example.com'): ServerRequestInterface
    {
        $request = new ServerRequest('https://' . $host . '/wn-ai-bridge/ask', 'POST');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    private function subject(): BotDetectionService
    {
        return new BotDetectionService();
    }

    #[Test]
    public function acceptsGenuineWidgetRequest(): void
    {
        $request = $this->request([
            'User-Agent' => self::CHROME_UA,
            'X-Wn-Ai-Bridge' => 'widget',
            'Origin' => 'https://example.com',
        ]);

        self::assertNull($this->subject()->detect($request));
        self::assertFalse($this->subject()->isBot($request));
    }

    #[Test]
    public function blocksRequestWithoutProofHeader(): void
    {
        $request = $this->request(['User-Agent' => self::CHROME_UA]);

        self::assertSame('missing-widget-header', $this->subject()->detect($request));
        self::assertTrue($this->subject()->isBot($request));
    }

    #[Test]
    public function blocksKnownBotUserAgent(): void
    {
        $request = $this->request([
            'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        ]);

        self::assertSame('bot-user-agent', $this->subject()->detect($request));
    }

    #[Test]
    public function blocksHttpClientUserAgent(): void
    {
        $request = $this->request(['User-Agent' => 'python-requests/2.31.0']);
        self::assertSame('bot-user-agent', $this->subject()->detect($request));
    }

    #[Test]
    public function blocksEmptyUserAgent(): void
    {
        $request = $this->request(['X-Wn-Ai-Bridge' => 'widget']);
        self::assertSame('empty-user-agent', $this->subject()->detect($request));
    }

    #[Test]
    public function blocksCrossOriginRequest(): void
    {
        $request = $this->request([
            'User-Agent' => self::CHROME_UA,
            'X-Wn-Ai-Bridge' => 'widget',
            'Origin' => 'https://evil.example.net',
        ]);

        self::assertSame('cross-origin', $this->subject()->detect($request));
    }

    #[Test]
    public function doesNotFalsePositiveOnCubotDeviceName(): void
    {
        // The "CUBOT" phone brand contains "bot" but is a real human device.
        $request = $this->request([
            'User-Agent' => 'Mozilla/5.0 (Linux; Android 12; CUBOT NOTE 21) AppleWebKit/537.36 Chrome/125.0 Mobile Safari/537.36',
            'X-Wn-Ai-Bridge' => 'widget',
            'Origin' => 'https://example.com',
        ]);

        self::assertNull($this->subject()->detect($request));
    }
}
