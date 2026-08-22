<?php
/**
 * Общая конфигурация подсистемы фоновых заданий.
 *
 * Реестр типов пуст: типы регистрируют пакеты-потребители и проект, дописывая
 * `components.jobRegistry.types`. Запускать можно только зарегистрированный
 * тип, поэтому произвольная строка обработчиком не станет.
 */
return [
    'components' => [
        'jobRegistry' => [
            'class' => \skeeks\cms\job\JobRegistry::class,
            'types' => [],
        ],

        'jobTransport' => [
            'class' => \skeeks\cms\job\transport\DbTransport::class,
        ],

        'jobLockManager' => [
            'class' => \skeeks\cms\job\runtime\LockManager::class,
        ],

        'jobRunner' => [
            'class' => \skeeks\cms\job\runtime\JobRunner::class,
        ],

        'jobs' => [
            'class' => \skeeks\cms\job\CmsJobComponent::class,
        ],

        'i18n' => [
            'translations' => [
                'skeeks/job' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@skeeks/cms/job/messages',
                    'fileMap' => [
                        'skeeks/job' => 'main.php',
                    ],
                ],
            ],
        ],
    ],
];
