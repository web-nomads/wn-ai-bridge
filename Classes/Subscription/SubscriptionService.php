<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Subscription;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single point of truth for "is this installation licensed, and for what?".
 *
 * An installation may hold several licences — one per site — so this tries every
 * key it has and answers with the first that comes out valid; see
 * {@see candidateKeys()} and {@see acceptableHosts()}. Everything is
 * fail-closed: any unexpected problem yields an invalid status rather than an
 * exception, so a broken key can never take a site down — it only switches the
 * subscription features off.
 *
 * Two things the key states are not the last word on. Its end date is overruled
 * by a renewal the issuing server confirms, and its domain list by the current
 * one that server publishes — so a licence can be extended and can grow without
 * a new key ever being pasted into a configuration. Both only ever come from a
 * signed answer; a server that stays silent changes nothing.
 */
final class SubscriptionService implements SingletonInterface
{
    /** The chat widget and its frontend endpoint. */
    public const FEATURE_CHATBOT = 'chatbot';
    /** The "Enquiries" backend module. */
    public const FEATURE_LOG = 'log';
    /** The "Answers" backend module and the local answer store. */
    public const FEATURE_CORRECTIONS = 'corrections';

    /**
     * How close to its end date a key has to be before the status check is also
     * performed from a frontend request. Chosen to cover the grace period the
     * issuing server adds to renewable keys, so a renewal is picked up before
     * the old key runs out even on a site with no scheduler and no backend use.
     */
    public const FRONTEND_REFRESH_WINDOW = 2592000; // 30 days

    private ?SubscriptionStatus $status = null;

    /**
     * Set while the resolved status carries a verdict that was never fetched.
     *
     * The backend module guard asks for the status while the module list is
     * built — that happens in a middleware, before the request reaches
     * $GLOBALS['TYPO3_REQUEST'], so the status check may not run yet. Without
     * this the whole request would keep that empty verdict, and the modules
     * would show no server state at all.
     */
    private bool $awaitingOnlineCheck = false;

    public function __construct(
        private readonly SubscriptionOnlineCheck $onlineCheck,
        private readonly TamperReporter $tamperReporter,
        /**
         * Only for finding the keys of the other sites — see {@see resolveKey()}.
         * Absent in tests that do not exercise per-site keys; without it only
         * the current request's site and the extension configuration are read.
         */
        private readonly ?SiteFinder $siteFinder = null,
    ) {}

    public function getStatus(): SubscriptionStatus
    {
        // Resolve again once the request context allows the check that was not
        // possible earlier — see $awaitingOnlineCheck.
        if ($this->status !== null && $this->awaitingOnlineCheck && $this->onlineCheck->mayRefreshNow()) {
            $this->status = null;
        }

        if ($this->status === null) {
            $this->status = $this->resolve();
            $this->reportIfSuspicious($this->status);
        }

        return $this->status;
    }

    /**
     * Let the issuing server know when this installation looks manipulated.
     *
     * Only findings that cannot be an honest state are sent — a missing or
     * expired key is not one of them. Everything here is best-effort and silent:
     * a licence check must never fail over its own reporting, and no visitor
     * request may wait for it.
     */
    private function reportIfSuspicious(SubscriptionStatus $status): void
    {
        try {
            $reason = TamperDetection::fromStatus($status);

            if ($reason === null && !TamperDetection::isExtensionIntact()) {
                $reason = TamperDetection::REASON_ALTERED_EXTENSION;
            }
            if ($reason === null
                && TamperDetection::usesForeignVerificationKey($this->configValue('subscriptionPublicKey'))
            ) {
                $reason = TamperDetection::REASON_FOREIGN_VERIFICATION_KEY;
            }
            if ($reason === null) {
                return;
            }

            // A forged key may not decode at all, so the address falls back to
            // the configured one and then to the shipped default.
            $baseUrl = SubscriptionOnlineCheck::resolveServerUrl(
                $this->configValue('subscriptionServerUrl'),
                $status->token?->checkUrl ?? '',
            );

            $this->tamperReporter->report(
                $reason,
                $status->token?->id ?? '',
                $status->host,
                $baseUrl,
            );
        } catch (\Throwable $e) {
            // Deliberately silent.
        }
    }

    public function isValid(): bool
    {
        return $this->getStatus()->valid;
    }

    public function hasFeature(string $feature): bool
    {
        return $this->getStatus()->hasFeature($feature);
    }

    /**
     * Drop the cached result — only needed after the extension configuration was
     * changed within the same request (e.g. in the install tool).
     */
    public function reset(): void
    {
        $this->status = null;
        $this->awaitingOnlineCheck = false;
    }

    /**
     * The subscription state of this request.
     *
     * An installation may hold more than one licence — one per site — so this is
     * a search rather than a lookup: every key it has is tried, and the first
     * one that comes out valid is the answer. Only when none does is the first
     * finding reported, because that is the message worth showing.
     */
    private function resolve(): SubscriptionStatus
    {
        $host = $this->currentHost();
        $keys = $this->candidateKeys();

        if ($keys === []) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_MISSING, null, $host);
        }

        $acceptableHosts = $this->acceptableHosts($host);

        $firstFinding = null;
        foreach ($keys as $key) {
            $status = $this->resolveWithKey($key, $host, $acceptableHosts);
            if ($status->valid) {
                return $status;
            }
            $firstFinding ??= $status;
        }

        // The list was not empty, so the loop has been through at least once.
        return $firstFinding;
    }

    /**
     * The state one particular key produces.
     *
     * @param list<string> $acceptableHosts The hosts this key may cover to count
     *        — see {@see acceptableHosts()}.
     */
    private function resolveWithKey(string $key, string $host, array $acceptableHosts): SubscriptionStatus
    {
        try {
            $token = SubscriptionKeyCodec::decode($key, $this->configValue('subscriptionPublicKey'));
        } catch (SubscriptionKeyException $e) {
            return SubscriptionStatus::invalid($e->getReason(), null, $host);
        } catch (\Throwable $e) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_MALFORMED, null, $host);
        }

        // The host reported to the issuing server: one this licence actually
        // covers, rather than whatever address the backend happens to be open
        // on. Otherwise every backend visit would look to that server like a key
        // in use on a domain it was not issued for.
        $covered = $this->firstCovered($acceptableHosts, $token->domains);
        $checkHost = $covered ?? $host;

        // Whether the key alone already covers one of them. It decides nothing
        // on its own any more — see below — but it is what tells this
        // installation that asking the server is worth the wait even in a
        // visitor request.
        $coveredByKey = $acceptableHosts === [] || $covered !== null;

        // The daily status check with the issuing server. It runs before every
        // decision below, because both the end date and the domain list can have
        // moved on the server while the key in the configuration kept the old
        // ones: that is how a renewal and an added domain reach an installation
        // without anyone pasting a new key.
        $verdict = $this->onlineCheck->verdict(
            $token,
            $this->getVerificationKey(),
            $checkHost,
            $token->isExpiringWithin(self::FRONTEND_REFRESH_WINDOW) || !$coveredByKey,
        );

        // "Unknown" with no failure means nobody asked. Worth another attempt
        // later in the request, when the context may allow one.
        $this->awaitingOnlineCheck = !$verdict->isVerified() && !$verdict->hasFailed();

        // The domains this installation goes by. Without a resolvable host (CLI:
        // scheduler, upgrade wizards, commands) there is nothing to check them
        // against; skipping it there keeps maintenance tasks working, every web
        // request is checked.
        $domains = self::effectiveDomains($token, $verdict);

        if ($acceptableHosts !== [] && $this->firstCovered($acceptableHosts, $domains) === null) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_DOMAIN, $token, $host, 0, $verdict, $domains);
        }

        if ($verdict->isRevoked()) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_REVOKED, $token, $host, 0, $verdict, $domains);
        }

        // A verified answer is authoritative for the end date — that is how a
        // renewal reaches this installation without anyone pasting a new key.
        // Without one, the date inside the key stands: an unreachable server can
        // never extend a subscription, only the signed answer of a reachable one.
        $validUntil = self::effectiveValidUntil($token, $verdict);

        if (self::isExpired($token, $verdict)) {
            return SubscriptionStatus::invalid(SubscriptionStatus::REASON_EXPIRED, $token, $host, $validUntil, $verdict, $domains);
        }

        return SubscriptionStatus::valid($token, $host, $validUntil, $verdict, $domains);
    }

    /**
     * The domain list this installation goes by: the issuing server's when it
     * answered and the answer verified, otherwise the one inside the key.
     *
     * This is what lets a licence grow. Domains are maintained on the server,
     * where a customer's second or fifth site can simply be added; the key keeps
     * the list it was issued with and no longer has to be re-delivered for it.
     *
     * An unreachable server can therefore never extend a licence either — its
     * silence leaves the key's own list standing, which is the same list that
     * was checked before any of this existed.
     *
     * Public and static so the rule can be exercised without a network.
     *
     * @return list<string>
     */
    public static function effectiveDomains(SubscriptionToken $token, OnlineCheckResult $verdict): array
    {
        return $verdict->isVerified() && $verdict->domains !== [] ? $verdict->domains : $token->domains;
    }

    /**
     * The end date this installation goes by: the issuing server's when it
     * answered and the answer verified, otherwise the one baked into the key.
     *
     * This is the whole mechanism behind a renewal arriving here — the key in the
     * configuration keeps its original date, the server's answer carries the new
     * one. It cuts both ways: a subscription that lapsed on the server is
     * reported with a date in the past and switches off, even if its key would
     * still be good for a while.
     *
     * Public and static so the rule can be exercised without a network.
     */
    public static function effectiveValidUntil(SubscriptionToken $token, OnlineCheckResult $verdict): int
    {
        return $verdict->isVerified() ? $verdict->validUntil : $token->expiresAt;
    }

    public static function isExpired(SubscriptionToken $token, OnlineCheckResult $verdict, ?int $now = null): bool
    {
        $validUntil = self::effectiveValidUntil($token, $verdict);

        return $validUntil > 0 && ($now ?? time()) > $validUntil;
    }

    /**
     * The decoded token regardless of validity — used by the CLI check command,
     * which needs the subscription id even when the key is currently rejected.
     */
    public function getToken(): ?SubscriptionToken
    {
        return $this->getStatus()->token;
    }

    public function getOnlineCheck(): SubscriptionOnlineCheck
    {
        return $this->onlineCheck;
    }

    /**
     * The issuing server this installation actually talks to, after the
     * configuration, the key and the shipped default have been weighed up.
     */
    public function getServerUrl(): string
    {
        return SubscriptionOnlineCheck::resolveServerUrl(
            $this->configValue('subscriptionServerUrl'),
            $this->getToken()?->checkUrl ?? '',
        );
    }

    public function getVerificationKey(): string
    {
        return $this->configValue('subscriptionPublicKey') ?: SubscriptionKeyCodec::DEFAULT_PUBLIC_KEY;
    }

    public function getCurrentHost(): string
    {
        return $this->currentHost();
    }

    /**
     * Every subscription key this installation holds, most specific first.
     *
     * A licence covers domains, and a domain belongs to a site, so keys are
     * maintained per site — two websites in one TYPO3 can be licensed
     * separately. The site of the current request comes first, then the
     * installation-wide key from the extension configuration, then the keys of
     * the other sites.
     *
     * @return list<string>
     */
    private function candidateKeys(): array
    {
        $keys = [$this->siteValue($this->currentSite()), $this->configValue('subscriptionKey')];

        foreach ($this->allSites() as $site) {
            $keys[] = $this->siteValue($site);
        }

        return array_values(array_unique(array_filter($keys, static fn(string $key): bool => $key !== '')));
    }

    /**
     * The hosts a licence may cover to count for this request.
     *
     * In a visitor request that is the one host being served: a website is
     * licensed or it is not, and no other site's licence can speak for it.
     *
     * In the backend and on the command line there is no site to speak for, and
     * the question is a different one — does this installation hold a valid
     * licence at all? Tying that to the address the backend happens to be open
     * on was wrong: an editor reaching it through a staging domain, a dedicated
     * admin domain, or simply the second of two websites would find the
     * subscription modules gone, although the installation is licensed. So every
     * domain this installation serves counts, plus the address in the browser.
     *
     * An empty list means "do not check the domain at all" — that is the command
     * line without any site configuration, where a domain cannot be established
     * and maintenance tasks have to keep working.
     *
     * @return list<string>
     */
    private function acceptableHosts(string $host): array
    {
        if ($this->belongsToOneSite()) {
            return $host !== '' ? [$host] : [];
        }

        $hosts = $host !== '' ? [$host] : [];
        foreach ($this->allSites() as $site) {
            try {
                $siteHost = $site->getBase()->getHost();
            } catch (\Throwable $e) {
                continue;
            }
            if ($siteHost !== '') {
                $hosts[] = $siteHost;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Whether this request speaks for one particular site — a frontend request
     * with a site resolved on it. Everything else (the backend, the command
     * line, a request the site resolver has not reached yet) speaks for the
     * installation.
     */
    private function belongsToOneSite(): bool
    {
        return $this->currentSite() !== null;
    }

    /**
     * The first of the given hosts that the domain list covers, or null.
     *
     * @param list<string> $hosts
     * @param list<string> $domains
     */
    private function firstCovered(array $hosts, array $domains): ?string
    {
        foreach ($hosts as $host) {
            if (SubscriptionToken::hostCoveredBy($host, $domains)) {
                return $host;
            }
        }

        return null;
    }

    private function siteValue(?Site $site): string
    {
        return $site instanceof Site
            ? trim((string)($site->getConfiguration()['aiAssistantSubscriptionKey'] ?? ''))
            : '';
    }

    private function currentSite(): ?Site
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $site = $request->getAttribute('site');

        return $site instanceof Site ? $site : null;
    }

    /**
     * @return list<Site>
     */
    private function allSites(): array
    {
        try {
            return $this->siteFinder !== null ? array_values($this->siteFinder->getAllSites()) : [];
        } catch (\Throwable $e) {
            // A broken site configuration must not take the licence check down.
            return [];
        }
    }

    private function currentHost(): string
    {
        try {
            return (string)GeneralUtility::getIndpEnv('HTTP_HOST');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function configValue(string $key): string
    {
        $extensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['wn_ai_bridge'] ?? [];
        return trim((string)($extensionConfiguration[$key] ?? ''));
    }
}
