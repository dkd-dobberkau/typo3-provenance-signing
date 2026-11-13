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

#[AsCommand(name: 'provenance:keys:generate', description: 'Generate the first Ed25519 site key.')]
final class GenerateKeyCommand extends Command
{
    public function __construct(
        private readonly KeyStore $keyStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $key = $this->keyStore->generate();
        } catch (KeyStoreException $exception) {
            $io->error($exception->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Generated site key %s. Flush the page cache so pages get signed.', $key->keyId));
        return Command::SUCCESS;
    }
}
