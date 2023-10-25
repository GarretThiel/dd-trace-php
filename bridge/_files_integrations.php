<?php

return [
    // config does not really belong here, but given it declares functions, we must always hard-load these
    __DIR__ . '/configuration.php',
    __DIR__ . '/../src/Integrations/Integrations/AbstractIntegrationConfiguration.php',
    __DIR__ . '/../src/Integrations/Integrations/DatabaseIntegrationHelper.php',
    __DIR__ . '/../src/Integrations/Integrations/HttpClientIntegrationHelper.php',
    __DIR__ . '/../src/Integrations/Integrations/DefaultIntegrationConfiguration.php',
    __DIR__ . '/../src/Integrations/Integrations/Integration.php',
    __DIR__ . '/../src/Integrations/Integrations/AMQP/AMQPIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/CakePHP/CakePHPIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/CodeIgniter/V2/CodeIgniterIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Drupal/DrupalIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Web/WebIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/IntegrationsLoader.php',
    __DIR__ . '/../src/Integrations/Integrations/Pcntl/PcntlIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/PDO/PDOIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/PHPRedis/PHPRedisIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Predis/PredisIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Eloquent/EloquentIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Logs/LogsIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Memcache/MemcacheIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Memcached/MemcachedIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Curl/CurlIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Mysqli/MysqliCommon.php',
    __DIR__ . '/../src/Integrations/Integrations/Mysqli/MysqliIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Mongo/MongoIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/MongoDB/MongoDBIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Slim/SlimIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/SQLSRV/SQLSRVIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Symfony/SymfonyIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/ElasticSearch/V1/ElasticSearchCommon.php',
    __DIR__ . '/../src/Integrations/Integrations/ElasticSearch/V1/ElasticSearchIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/ElasticSearch/V8/ElasticSearchIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Laminas/LaminasIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Laravel/LaravelIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/LaravelQueue/LaravelQueueIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Lumen/LumenIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Guzzle/GuzzleIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Psr18/Psr18Integration.php',
    __DIR__ . '/../src/Integrations/Integrations/Roadrunner/RoadrunnerIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Yii/YiiIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/Nette/NetteIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/OpenTelemetry/OpenTelemetryIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/WordPress/WordPressIntegration.php',
    __DIR__ . '/../src/Integrations/Integrations/WordPress/V1/WordPressIntegrationLoader.php',
    __DIR__ . '/../src/Integrations/Integrations/WordPress/V2/WordPressIntegrationLoader.php',
    __DIR__ . '/../src/Integrations/Integrations/ZendFramework/ZendFrameworkIntegration.php',
    __DIR__ . '/../src/Integrations/Util/Convention.php',
    __DIR__ . '/../src/Integrations/Util/Runtime.php',
    __DIR__ . '/../src/Integrations/Util/Versions.php',
    __DIR__ . '/../src/Integrations/Util/ObjectKVStore.php',
    __DIR__ . '/../src/Integrations/Util/ArrayKVStore.php',
    __DIR__ . '/../src/Integrations/Util/Normalizer.php',
    __DIR__ . '/../src/Integrations/Obfuscation.php',
];
