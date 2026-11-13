<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Command;

use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'provenance:keys:list', description: 'List all site keys with their state.')]
final class ListKeysCommand extends Command
{
    public function __construct(
        private readonly KeyStore $keyStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rows = array_map(static fn($key): array => [
            $key->keyId,
            $key->isActive() ? 'active' : 'retired',
            $key->createdAt->format('Y-m-d H:i'),
            $key->retiredAt?->format('Y-m-d H:i') ?? '',
        ], $this->keyStore->publicKeys());

        $io = new SymfonyStyle($input, $output);
        if ($rows === []) {
            $io->note('No site keys yet. Run provenance:keys:generate.');
            return Command::SUCCESS;
        }
        $io->table(['Key ID', 'State', 'Created (UTC)', 'Retired (UTC)'], $rows);
        return Command::SUCCESS;
    }
}
