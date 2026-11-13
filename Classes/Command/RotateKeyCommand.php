<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Command;

use CmsProvenance\ProvenanceSigning\Key\KeyStore;
use CmsProvenance\ProvenanceSigning\Key\KeyStoreException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'provenance:keys:rotate', description: 'Replace the active site key; the old key stays published as retired.')]
final class RotateKeyCommand extends Command
{
    public function __construct(
        private readonly KeyStore $keyStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $previous = $this->keyStore->activeKey();
        try {
            $key = $this->keyStore->rotate();
        } catch (KeyStoreException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Retired %s, new active key %s. Flush the page cache so pages get re-signed.',
            $previous?->keyId,
            $key->keyId
        ));
        return Command::SUCCESS;
    }
}
