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
        // Канонический маршрут консольных команд пакета.
        //
        // Дефис, а не camelCase: это публичный контракт эксплуатации, он
        // попадает в конфигурацию Supervisor и systemd, и менять его потом
        // означало бы ломать чужие развёртывания.
        'cms-job' => [
            'class' => \skeeks\cms\job\CmsJobModule::class,
            'controllerNamespace' => 'skeeks\cms\job\console\controllers',
        ],

        // Прежнее имя. Оставлено рабочим, чтобы уже настроенные вызовы и
        // строки расписания не сломались молча.
        'cmsJob' => [
            'class' => \skeeks\cms\job\CmsJobModule::class,
            'controllerNamespace' => 'skeeks\cms\job\console\controllers',
        ],
    ],
];
