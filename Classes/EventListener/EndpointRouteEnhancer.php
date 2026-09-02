<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds the extension's endpoints to a site's PageType route enhancer when they
 * are missing.
 *
 * :file:`Configuration/Routes/RouterEnhancer.yaml` is meant to be imported by
 * reference, and a site that does so picks up every endpoint the extension adds
 * later. Copying its content into the site configuration is just as common — and
 * that copy is a snapshot: ``llms-full.txt`` arrived in 1.27.0 and every site
 * holding such a copy answered it with a 404, with nothing in the backend to
 * suggest why.
 *
 * Only missing suffixes are added. A suffix the site already maps is left
 * exactly as it is, whatever it points at, because that is a decision someone
 * made. Sites without a PageType enhancer at all are left alone too: introducing
 * one changes how every URL on the site is built, which is not this listener's
 * to decide.
 */
final class EndpointRouteEnhancer
{
    public function __construct(
        private readonly YamlFileLoader $yamlFileLoader,
    ) {}

    #[AsEventListener('wn-ai-bridge/endpoint-route-enhancer')]
    public function __invoke(SiteConfigurationLoadedEvent $event): void
    {
        $configuration = self::withMissingEndpoints($event->getConfiguration(), $this->shippedMap());

        if ($configuration !== $event->getConfiguration()) {
            $event->setConfiguration($configuration);
        }
    }

    /**
     * The site configuration with the endpoints it does not map yet added to its
     * PageType enhancer. Returned unchanged when there is nothing to add.
     *
     * @param array<string, mixed> $configuration
     * @param array<string, int> $shippedMap
     * @return array<string, mixed>
     */
    public static function withMissingEndpoints(array $configuration, array $shippedMap): array
    {
        $enhancers = $configuration['routeEnhancers'] ?? null;
        if (!is_array($enhancers) || $shippedMap === []) {
            return $configuration;
        }

        $identifier = self::findPageTypeEnhancer($enhancers);
        if ($identifier === null) {
            return $configuration;
        }

        $map = $enhancers[$identifier]['map'] ?? [];
        if (!is_array($map)) {
            return $configuration;
        }

        $missing = array_diff_key($shippedMap, $map);
        if ($missing === []) {
            return $configuration;
        }

        $enhancers[$identifier]['map'] = $map + $missing;
        $configuration['routeEnhancers'] = $enhancers;

        return $configuration;
    }

    /**
     * The first enhancer of type "PageType". Found by its type rather than by
     * the name "PageTypeSuffix", which is a convention, not a requirement.
     *
     * @param array<string, mixed> $enhancers
     */
    private static function findPageTypeEnhancer(array $enhancers): ?string
    {
        foreach ($enhancers as $identifier => $enhancer) {
            if (is_array($enhancer) && ($enhancer['type'] ?? '') === 'PageType') {
                return (string)$identifier;
            }
        }

        return null;
    }

    /**
     * The mapping the extension ships, read from the file sites are asked to
     * import so the two can never drift apart.
     *
     * @return array<string, int>
     */
    private function shippedMap(): array
    {
        $file = GeneralUtility::getFileAbsFileName('EXT:wn_ai_bridge/Configuration/Routes/RouterEnhancer.yaml');
        if ($file === '' || !is_file($file)) {
            return [];
        }

        try {
            $shipped = $this->yamlFileLoader->load($file);
        } catch (\Throwable $e) {
            // A site configuration must never fail to load over this.
            return [];
        }

        $map = $shipped['routeEnhancers']['PageTypeSuffix']['map'] ?? [];

        return is_array($map) ? $map : [];
    }
}
