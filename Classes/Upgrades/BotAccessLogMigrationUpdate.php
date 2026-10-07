<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Upgrades;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Install\Updates\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Install\Updates\UpgradeWizardInterface;
use WebNomads\WnAiBridge\Agent\AgentDetector;
use WebNomads\WnAiBridge\Agent\AgentSettings;
use WebNomads\WnAiBridge\Agent\BotVerifier;
use WebNomads\WnAiBridge\Agent\VisitLogger;
use WebNomads\WnAiBridge\Agent\VisitRepository;

/**
 * Moves AI crawler rows of the former bot access log into Agent Analytics, IP addresses hashed; empties the old table
 */
final class BotAccessLogMigrationUpdate implements UpgradeWizardInterface
{
    public const IDENTIFIER = 'wnAiBridgeBotAccessLogMigration';

    // schema update renames a dropped table with this prefix
    private const OLD_TABLES = ['tx_wnaibridge_bot_access', 'zzz_deleted_tx_wnaibridge_bot_access'];

    private const BATCH = 1000;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AgentDetector $detector,
        private readonly AgentSettings $settings,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function getTitle(): string
    {
        return 'AI Bridge: move the bot access log into Agent Analytics';
    }

    public function getDescription(): string
    {
        return 'The "Bot Access Log" module became "Agent Analytics". This copies the logged requests of known AI '
            . 'crawlers into the new table, with the IP address as salted hash, and then empties the old table, '
            . 'which held IP addresses and user agents in plain text. Requests of other bots are not taken over. '
            . 'The old table can afterwards be removed with "Analyze Database Structure".';
    }

    public function getPrerequisites(): array
    {
        return [
            DatabaseUpdatedPrerequisite::class,
        ];
    }

    public function updateNecessary(): bool
    {
        foreach ($this->oldTables() as $table) {
            if ($this->connectionPool->getConnectionForTable($table)->count('uid', $table, []) > 0) {
                return true;
            }
        }

        return false;
    }

    public function executeUpdate(): bool
    {
        $target = $this->connectionPool->getConnectionForTable(VisitRepository::TABLE);
        $extraBots = $this->settings->extraBots();
        $hosts = [];
        foreach ($this->oldTables() as $table) {
            $connection = $this->connectionPool->getConnectionForTable($table);
            $lastUid = 0;
            do {
                $queryBuilder = $connection->createQueryBuilder();
                $queryBuilder->getRestrictions()->removeAll();
                $rows = $queryBuilder->select('*')
                    ->from($table)
                    ->where($queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($lastUid, Connection::PARAM_INT)))
                    ->orderBy('uid')
                    ->setMaxResults(self::BATCH)
                    ->executeQuery()
                    ->fetchAllAssociative();
                foreach ($rows as $row) {
                    $lastUid = (int)$row['uid'];
                    $crawler = $this->detector->crawler((string)($row['user_agent'] ?? ''), $extraBots);
                    if ($crawler === null) {
                        continue;
                    }
                    $site = (string)($row['site_identifier'] ?? '');
                    $query = (string)($row['query_string'] ?? '');
                    $target->insert(VisitRepository::TABLE, [
                        'crdate' => (int)$row['crdate'],
                        'site_identifier' => $site,
                        'host' => $hosts[$site] ??= $this->host($site),
                        'path' => mb_substr(VisitLogger::path((string)($row['path'] ?? '') . ($query !== '' ? '?' . $query : ''), true), 0, 1000),
                        'request_type' => (string)($row['request_type'] ?? ''),
                        'status' => (int)($row['http_status'] ?? 0),
                        'kind' => AgentDetector::CRAWLER,
                        'agent' => $crawler['token'],
                        'purpose' => $crawler['purpose'],
                        'ip_hash' => VisitLogger::hashIp((string)($row['ip_address'] ?? '')),
                        'verified' => BotVerifier::UNKNOWN,
                        'source' => VisitLogger::SOURCE_LIVE,
                        'line_hash' => '',
                    ]);
                }
            } while (count($rows) === self::BATCH);
            $connection->truncate($table);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function oldTables(): array
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $existing = array_map('strtolower', $connection->createSchemaManager()->listTableNames());

        return array_values(array_filter(self::OLD_TABLES, static fn(string $table): bool => in_array($table, $existing, true)));
    }

    private function host(string $siteIdentifier): string
    {
        try {
            return strtolower($this->siteFinder->getSiteByIdentifier($siteIdentifier)->getBase()->getHost());
        } catch (\Throwable) {
            return '';
        }
    }
}
