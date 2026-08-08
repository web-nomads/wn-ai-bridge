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
                'The key is not valid for "%s", but for: %s.',
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
            ['Type' => $token->trial ? 'trial (does not renew)' : 'subscription'],
            ['Customer' => $token->customer !== '' ? $token->customer : '—'],
            ['Domains' => $token->getDomainList()],
            ['Valid until (key)' => $token->getExpiresAt()?->format('Y-m-d') ?? 'unlimited'],
            ['Valid until (effective)' => $verdict->isVerified() && $verdict->validUntil > 0
                ? date('Y-m-d', $verdict->validUntil) . ' (confirmed by the server)'
                : 'as stated in the key (the server did not answer)'],
            ['Features' => $token->features === [] ? 'all' : implode(', ', $token->features)],
            ['Server status' => $this->describeVerdict($verdict)],
            ['Issuing server' => $this->subscriptionService->getServerUrl()],
        );

        if ($verdict->isRevoked()) {
            $io->error('The subscription was revoked and is no longer active.');
            return Command::FAILURE;
        }

        if ($status->reason === SubscriptionStatus::REASON_EXPIRED) {
            $io->error($status->getMessage());
            return Command::FAILURE;
        }

        if ($verdict->status === OnlineCheckResult::STATUS_UNKNOWN) {
            $io->error(
                ($verdict->getFailureMessage() ?: 'The issuing server did not answer.')
                . ' The key stays valid according to its own expiry date, but a renewal or a revocation'
                . ' cannot reach this installation until the server answers again.'
            );
            return Command::SUCCESS;
        }

        $io->success($status->getMessage());

        return Command::SUCCESS;
    }

    private function describeVerdict(OnlineCheckResult $verdict): string
    {
        return match ($verdict->status) {
            OnlineCheckResult::STATUS_ACTIVE => 'active',
            OnlineCheckResult::STATUS_REVOKED => 'revoked',
            default => 'unknown — ' . ($verdict->getFailureMessage() ?: 'not asked'),
        };
    }
}
