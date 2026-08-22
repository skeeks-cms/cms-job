<?php

return [
    'controllerMap' => [
        'migrate' => [
            'migrationPath' => [
                '@skeeks/cms/job/migrations',
            ],
        ],
    ],

    'modules' => [
        'cmsJob' => [
            'class' => \skeeks\cms\job\CmsJobModule::class,
            'controllerNamespace' => 'skeeks\cms\job\console\controllers',
        ],
    ],
];
