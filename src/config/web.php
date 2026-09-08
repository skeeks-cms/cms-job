<?php

return [
    'components' => [
        'backendAdmin' => [
            'menu' => [
                'data' => [
                    'settings' => [
                        'items' => [
                            [
                                'name' => ['skeeks/job', 'Фоновые операции'],
                                'url' => ['cmsJob/admin-cms-job-run'],
                                'image' => ['\skeeks\cms\assets\CmsAsset', 'images/icons/admin-menu/agent.svg'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'modules' => [
        'cmsJob' => [
            'class' => \skeeks\cms\job\CmsJobModule::class,
        ],
    ],
];
