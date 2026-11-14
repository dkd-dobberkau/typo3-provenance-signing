<?php

declare(strict_types=1);

use CmsProvenance\ProvenanceSigning\Backend\Controller\RevisionsModuleController;

return [
    'provenance_revisions' => [
        'parent' => 'provenance',
        'access' => 'user',
        'path' => '/module/web/provenance/revisions',
        'iconIdentifier' => 'module-provenance',
        'labels' => 'LLL:EXT:provenance_signing/Resources/Private/Language/locallang_mod_revisions.xlf',
        'navigationComponent' => '@typo3/backend/tree/page-tree-element',
        'routes' => [
            '_default' => [
                'target' => RevisionsModuleController::class . '::handleRequest',
            ],
            'detail' => [
                'target' => RevisionsModuleController::class . '::detailAction',
            ],
        ],
    ],
];
