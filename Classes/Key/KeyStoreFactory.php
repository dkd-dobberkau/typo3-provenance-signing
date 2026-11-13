<?php

declare(strict_types=1);

namespace CmsProvenance\ProvenanceSigning\Key;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Builds the KeyStore from the extension configuration "keyDirectory",
 * absolute or relative to the project root.
 */
final readonly class KeyStoreFactory
{
    private const DEFAULT_DIRECTORY = 'var/provenance-keys';

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function create(): KeyStore
    {
        try {
            $directory = trim((string)$this->extensionConfiguration->get('provenance_signing', 'keyDirectory'));
        } catch (\Throwable) {
            $directory = '';
        }
        $directory = $directory !== '' ? $directory : self::DEFAULT_DIRECTORY;

        return new KeyStore(
            PathUtility::isAbsolutePath($directory) ? $directory : Environment::getProjectPath() . '/' . $directory
        );
    }
}
