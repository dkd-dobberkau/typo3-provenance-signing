<?php

declare(strict_types=1);

use CmsProvenance\ProvenanceSigning\Middleware\PublicKeyEndpoint;

return [
    'frontend' => [
        'cms-provenance/public-keys' => [
            'target' => PublicKeyEndpoint::class,
            'after' => ['typo3/cms-core/normalized-params-attribute'],
            'before' => ['typo3/cms-frontend/site'],
        ],
    ],
];
