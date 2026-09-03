<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Tests\Unit\Subscription;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WebNomads\WnAiBridge\Subscription\OnlineCheckProtocol;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionToken;

/**
 * The domain list the issuing server publishes with its daily answer.
 *
 * This is what lets a licence cover a second site without the customer pasting a
 * new key: the domains are maintained on the server and travel with the status
 * check. Because they decide whether the assistant runs at all, they are only
 * ever adopted from a signature — and the signature has to be bound to this
 * particular answer, or a list could be lifted out of one and pasted into
 * another.
 */
final class PublishedDomainsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $publicKeyHex;

    private string $secretKey;

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_seed_keypair')) {
            self::markTestSkipped('The sodium extension is required for the online check.');
        }

        $keyPair = sodium_crypto_sign_seed_keypair(random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES));
        $this->publicKeyHex = bin2hex(sodium_crypto_sign_publickey($keyPair));
        $this->secretKey = sodium_crypto_sign_secretkey($keyPair);
    }

    #[Test]
    public function aSignedDomainListIsAdopted(): void
    {
        $result = $this->parse($this->body(['kunde.ch', '*.kunde.ch', 'zweitseite.ch']));

        self::assertNotNull($result);
        self::assertSame(['kunde.ch', '*.kunde.ch', 'zweitseite.ch'], $result->domains);
    }

    /**
     * The whole point: a key issued for one domain keeps working, and the second
     * site the customer had added to their licence works too.
     */
    #[Test]
    public function aDomainAddedOnTheServerCoversTheSecondSite(): void
    {
        $token = $this->token(['kunde.ch']);
        $verdict = $this->parse($this->body(['kunde.ch', 'zweitseite.ch']));

        self::assertNotNull($verdict);
        $domains = SubscriptionService::effectiveDomains($token, $verdict);

        self::assertTrue(SubscriptionToken::hostCoveredBy('www.zweitseite.ch', ['*.zweitseite.ch']));
        self::assertTrue(SubscriptionToken::hostCoveredBy('zweitseite.ch', $domains));
        self::assertFalse($token->matchesHost('zweitseite.ch'), 'the key itself still knows nothing about it');
    }

    /**
     * A server from before domains were published says nothing about them, and
     * an installation talking to one has to keep going by its key.
     */
    #[Test]
    public function anAnswerWithoutDomainsLeavesTheKeyStanding(): void
    {
        $result = $this->parse($this->body(null));

        self::assertNotNull($result);
        self::assertSame([], $result->domains);
        self::assertSame(
            ['kunde.ch'],
            SubscriptionService::effectiveDomains($this->token(['kunde.ch']), $result),
        );
    }

    /**
     * The legacy signature covers the same string it always did, so an
     * installation from before this existed still verifies an answer that now
     * carries domains.
     */
    #[Test]
    public function theOriginalSignatureIsUntouchedByTheAddedList(): void
    {
        $body = (string)json_encode($this->payload(['kunde.ch', 'zweitseite.ch']));
        $data = json_decode($body, true);

        self::assertIsArray($data);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            (string)base64_decode(strtr((string)$data['signature'], '-_', '+/'), true),
            OnlineCheckProtocol::canonical('sub_abc', 'active', self::NOW + 86400, self::NOW, 'n1'),
            (string)hex2bin($this->publicKeyHex),
        ));
    }

    #[Test]
    public function anUnsignedDomainListIsIgnored(): void
    {
        $payload = $this->payload(['kunde.ch']);
        unset($payload['domainsSignature']);

        $result = $this->parse((string)json_encode($payload));

        self::assertNotNull($result, 'the answer itself is still good');
        self::assertSame([], $result->domains);
    }

    #[Test]
    public function aDomainListFromAnotherIssuerIsIgnored(): void
    {
        $foreign = sodium_crypto_sign_keypair();
        $payload = $this->payload(['kunde.ch'], sodium_crypto_sign_secretkey($foreign));

        // The answer itself stays signed by us — only its domain list does not.
        $payload['signature'] = $this->payload(['kunde.ch'])['signature'];

        $result = $this->parse((string)json_encode($payload));

        self::assertNotNull($result);
        self::assertSame([], $result->domains);
    }

    /**
     * A list is bound to the answer it came with. Without that, a captured
     * "covers everything" list could be pasted into any later answer.
     */
    #[Test]
    public function aDomainListLiftedFromAnotherAnswerIsIgnored(): void
    {
        $stolen = $this->payload(['kunde.ch', 'fremde.ch'], null, 'other-nonce');
        $payload = $this->payload(['kunde.ch']);
        $payload['domains'] = $stolen['domains'];
        $payload['domainsSignature'] = $stolen['domainsSignature'];

        $result = $this->parse((string)json_encode($payload));

        self::assertNotNull($result);
        self::assertSame([], $result->domains);
    }

    #[Test]
    public function anEditedDomainListIsIgnored(): void
    {
        $payload = $this->payload(['kunde.ch']);
        $payload['domains'] = ['kunde.ch', 'fremde.ch'];

        $result = $this->parse((string)json_encode($payload));

        self::assertNotNull($result);
        self::assertSame([], $result->domains);
    }

    #[Test]
    public function aListThatIsNotAListOfStringsIsIgnored(): void
    {
        foreach ([['kunde.ch', 5], 'kunde.ch', [], [['kunde.ch']]] as $raw) {
            $payload = $this->payload(['kunde.ch']);
            $payload['domains'] = $raw;

            $result = $this->parse((string)json_encode($payload));

            self::assertNotNull($result);
            self::assertSame([], $result->domains, 'Unexpectedly accepted: ' . var_export($raw, true));
        }
    }

    #[Test]
    public function anAbsurdlyLongListIsRefusedOutright(): void
    {
        $domains = [];
        for ($i = 0; $i <= OnlineCheckProtocol::MAX_DOMAINS; $i++) {
            $domains[] = sprintf('site%d.ch', $i);
        }

        $result = $this->parse((string)json_encode($this->payload($domains)));

        self::assertNotNull($result);
        self::assertSame([], $result->domains);
    }

    #[Test]
    public function aRevokedAnswerCarriesNoDomains(): void
    {
        $payload = $this->payload(['kunde.ch'], null, 'n1', 'revoked', 0);

        $result = $this->parse((string)json_encode($payload));

        self::assertNotNull($result);
        self::assertTrue($result->isRevoked());
        self::assertSame([], $result->domains);
    }

    #[Test]
    public function theDomainsSurviveTheCacheRoundTrip(): void
    {
        $result = OnlineCheckResult::active(self::NOW + 86400, self::NOW, ['kunde.ch', 'zweitseite.ch']);
        $restored = OnlineCheckResult::fromArray($result->toArray());

        self::assertSame(['kunde.ch', 'zweitseite.ch'], $restored->domains);
    }

    /**
     * A cache entry written before this existed has no domain list at all, and
     * must read as "the server named none" rather than "the licence covers
     * nothing".
     */
    #[Test]
    public function aCacheEntryFromBeforeThisExistedHasNoDomains(): void
    {
        $restored = OnlineCheckResult::fromArray([
            'status' => OnlineCheckResult::STATUS_ACTIVE,
            'validUntil' => self::NOW + 86400,
            'checkedAt' => self::NOW,
            'failureReason' => '',
            'serverUrl' => '',
        ]);

        self::assertSame([], $restored->domains);
        self::assertSame(
            ['kunde.ch'],
            SubscriptionService::effectiveDomains($this->token(['kunde.ch']), $restored),
        );
    }

    /**
     * An unreachable server must never extend a licence — the same rule the end
     * date follows.
     */
    #[Test]
    public function anUnansweredCheckLeavesTheKeysListStanding(): void
    {
        self::assertSame(
            ['kunde.ch'],
            SubscriptionService::effectiveDomains($this->token(['kunde.ch']), OnlineCheckResult::unknown(self::NOW)),
        );
    }

    /**
     * @param list<string> $domains
     */
    private function token(array $domains): SubscriptionToken
    {
        return SubscriptionToken::fromPayload([
            'id' => 'sub_abc',
            'customer' => 'Acme AG',
            'email' => 'info@example.com',
            'domains' => $domains,
            'iat' => self::NOW - 86400,
            'exp' => self::NOW + 86400,
            'features' => [],
        ]);
    }

    private function parse(string $body): ?OnlineCheckResult
    {
        return OnlineCheckProtocol::parseResponse($body, 'sub_abc', 'n1', $this->publicKeyHex, self::NOW);
    }

    /**
     * @param list<string>|null $domains Null builds an answer that names none.
     */
    private function body(?array $domains): string
    {
        return (string)json_encode($this->payload($domains));
    }

    /**
     * @param list<string>|null $domains
     * @return array<string, mixed>
     */
    private function payload(
        ?array $domains,
        ?string $domainSecretKey = null,
        string $nonce = 'n1',
        string $status = 'active',
        ?int $validUntil = null,
    ): array {
        $validUntil ??= self::NOW + 86400;

        $payload = [
            'id' => 'sub_abc',
            'status' => $status,
            'validUntil' => $validUntil,
            'issuedAt' => self::NOW,
            'nonce' => $nonce,
            'signature' => $this->sign(
                OnlineCheckProtocol::canonical('sub_abc', $status, $validUntil, self::NOW, $nonce)
            ),
        ];

        if ($domains === null) {
            return $payload;
        }

        return $payload + [
            'domains' => $domains,
            'domainsSignature' => $this->sign(
                OnlineCheckProtocol::canonicalDomains('sub_abc', $status, $validUntil, self::NOW, $nonce, $domains),
                $domainSecretKey,
            ),
        ];
    }

    private function sign(string $message, ?string $secretKey = null): string
    {
        return rtrim(strtr(
            base64_encode(sodium_crypto_sign_detached($message, $secretKey ?? $this->secretKey)),
            '+/',
            '-_'
        ), '=');
    }
}
