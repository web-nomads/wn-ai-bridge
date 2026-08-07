<?php

declare(strict_types=1);

namespace WebNomads\WnAiBridge\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use WebNomads\WnAiBridge\Subscription\OnlineCheckResult;
use WebNomads\WnAiBridge\Subscription\SubscriptionService;
use WebNomads\WnAiBridge\Subscription\SubscriptionStatus;

/**
 * Reports the subscription state and refreshes it with the issuing server.
 *
 * Schedule this daily so a revoked subscription is noticed even on an
 * installation nobody logs into. Running it is optional: the backend performs
 * the same refresh on its own once a day.
 */
final class CheckSubscriptionCommand extends Command
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Verify the subscription key and refresh its status with the issuing server')
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'Host to validate the key against. Required on the command line, where no host can be derived from the request.',
                ''
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = $this->subscriptionService->getStatus();
        $token = $status->token;

        if ($token === null) {
            $io->error($status->getMessage());
            return Command::FAILURE;
        }

        $host = trim((string)$input->getOption('host'));
        if ($host !== '' && !$token->matchesHost($host)) {
            $io->error(sprintf(
                'Der Key gilt nicht für "%s", sondern für: %s.',
                $host,
                $token->getDomainList()
            ));
            return Command::FAILURE;
        }

        $verdict = $this->subscriptionService->getOnlineCheck()->refreshNow(
            $token,
            $this->subscriptionService->getVerificationKey(),
            $host !== '' ? $host : $this->subscriptionService->getCurrentHost(),
        );

        $io->definitionList(
            ['Subscription' => $token->id],
            ['Kunde' => $token->customer !== '' ? $token->customer : '—'],
            ['Domains' => $token->getDomainList()],
            ['Gültig bis (Key)' => $token->getExpiresAt()?->format('d.m.Y') ?? 'unbegrenzt'],
            ['Gültig bis (massgeblich)' => $verdict->isVerified() && $verdict->validUntil > 0
                ? date('d.m.Y', $verdict->validUntil) . ' (vom Server bestätigt)'
                : 'wie im Key (Server hat nicht geantwortet)'],
            ['Features' => $token->features === [] ? 'alle' : implode(', ', $token->features)],
            ['Server-Status' => $this->describeVerdict($verdict)],
        );

        if ($verdict->isRevoked()) {
            $io->error('Die Subscription wurde widerrufen und ist ab sofort nicht mehr aktiv.');
            return Command::FAILURE;
        }

        if ($status->reason === SubscriptionStatus::REASON_EXPIRED) {
            $io->error($status->getMessage());
            return Command::FAILURE;
        }

        if ($verdict->status === OnlineCheckResult::STATUS_UNKNOWN) {
            $io->warning(
                'Der Ausstellungsserver war nicht erreichbar oder seine Antwort war nicht prüfbar. '
                . 'Der Key bleibt anhand seines Ablaufdatums gültig.'
            );
            return Command::SUCCESS;
        }

        $io->success($status->getMessage());

        return Command::SUCCESS;
    }

    private function describeVerdict(OnlineCheckResult $verdict): string
    {
        return match ($verdict->status) {
            OnlineCheckResult::STATUS_ACTIVE => 'aktiv',
            OnlineCheckResult::STATUS_REVOKED => 'widerrufen',
            default => 'unbekannt (Server nicht erreichbar)',
        };
    }
}
