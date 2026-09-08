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
        'jobLogs' => [
            'class' => \skeeks\cms\job\runtime\JobLogStorage::class,
            // SkeekS web/console have separate @runtime aliases. Both need one root.
            'basePath' => '@root/console/runtime/cms-jobs/logs',
        ],
        'jobRegistry' => [
            'class' => \skeeks\cms\job\JobRegistry::class,
            'types' => [
                // Мост для существующих консольных команд: история, stderr,
                // код возврата и отмена без правок в самих командах.
                //
                // Полоса намеренно `maintenance`. Команде, которой нужна
                // другая полоса, заводится собственный тип с тем же
                // обработчиком: полоса берётся из реестра и не редактируется,
                // иначе она разойдётся с определением типа.
                'console.command' => [
                    'type' => 'console.command',
                    'handler' => \skeeks\cms\job\handlers\ConsoleCommandJobHandler::class,
                    'title' => 'Консольная команда',
                    'queue' => \skeeks\cms\job\models\CmsJobRun::QUEUE_MAINTENANCE,
                    'maxAttempts' => 1,
                    'idempotent' => false,
                    'leaseSeconds' => 120,
                    'timeout' => 7200,
                    'overlapPolicy' => \skeeks\cms\job\models\CmsJobRun::OVERLAP_SKIP,
                    'retentionDays' => 30,
                ],
            ],
        ],

        // Пакеты-потребители добавляют свои queues и jobRegistry.types.
        // Проект переопределяет настройки. Полоса не запускает воркер.
        // TTR сообщения задаётся типом задания: timeout + leaseSeconds.
        'jobQueueFactory' => [
            'class' => \skeeks\cms\job\transport\yii2queue\QueueFactory::class,
            'defaults' => [
                'class' => \yii\queue\db\Queue::class,
                // То же соединение, что у приложения: на этом держится общая
                // транзакция постановки.
                'db' => 'db',
                'tableName' => '{{%cms_queue}}',
                'mutex' => 'mutex',
                'mutexTimeout' => 3,
                // Конверт содержит только номер запуска и версию формата,
                // поэтому JSON достаточно и предпочтителен: сериализованные
                // PHP-объекты в очереди ломаются при обновлении кода.
                'serializer' => \yii\queue\serializers\JsonSerializer::class,
                'ttr' => 300,
                'attempts' => 1,
            ],
            'queues' => [
                'default' => [],
                'maintenance' => [],
            ],
        ],

        'jobPublisher' => [
            'class' => \skeeks\cms\job\transport\yii2queue\Yii2QueuePublisher::class,
        ],

        'jobConsumer' => [
            'class' => \skeeks\cms\job\transport\yii2queue\Yii2QueueConsumer::class,
        ],

        'jobRunStore' => [
            'class' => \skeeks\cms\job\runtime\JobRunStore::class,
        ],

        // DB-драйвер yii2-queue требует мьютекс для резервирования сообщения.
        // В SkeekS CMS компонента не было; GET_LOCK в MariaDB работает.
        // Проект может заменить реализацию, но не убрать её.
        'mutex' => [
            'class' => \yii\mutex\MysqlMutex::class,
            'db' => 'db',
        ],

        'jobLockManager' => [
            'class' => \skeeks\cms\job\runtime\LockManager::class,
        ],

        'jobRunner' => [
            'class' => \skeeks\cms\job\runtime\CmsJobRunner::class,
        ],

        'jobs' => [
            'class' => \skeeks\cms\job\CmsJobComponent::class,
        ],

        'authManager' => [
            'config' => [
                'roles' => [
                    [
                        'name' => \skeeks\cms\rbac\CmsManager::ROLE_ADMIN,
                        'child' => [
                            'permissions' => [
                                'cmsJob/admin-cms-job-run',
                            ],
                        ],
                    ],
                ],
            ],
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
