<?php

const CONFIG_SYNC_DIRECTORY = 'sync';

// This also includes settings.local.php and settings.ddev.php if these files exist.
require __DIR__ . '/../../profiles/contrib/burst-drupal-distribution/includes/settings.php';

$platformsh = new \Platformsh\ConfigReader\Config();
if (!$platformsh->inRuntime()) {
  return;
}

// Set redis configuration.
if ($platformsh->hasRelationship('redis') && !\Drupal\Core\Installer\InstallerKernel::installationAttempted() && extension_loaded('redis')) {
  $redis = $platformsh->credentials('redis');

  // Set Redis as the default backend for any cache bin not otherwise specified.
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['redis.connection']['host'] = $redis['host'];
  $settings['redis.connection']['port'] = $redis['port'];

  // Apply changes to the container configuration to make better use of Redis.
  // This includes using Redis for the lock and flood control systems, as well
  // as the cache tag checksum. Alternatively, copy the contents of that file
  // to your project-specific services.yml file, modify as appropriate, and
  // remove this line.
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

  // Allow the services to work before the Redis module itself is enabled.
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

  // Manually add the classloader path, this is required for the container cache bin definition below
  // and allows to use it without the redis module being enabled.
  $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

  // Use redis for container cache.
  // The container cache is used to load the container definition itself, and
  // thus any configuration stored in the container itself is not available
  // yet. These lines force the container cache to use Redis rather than the
  // default SQL cache.
  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      'redis.factory' => [
        'class' => 'Drupal\redis\ClientFactory',
      ],
      'cache.backend.redis' => [
        'class' => 'Drupal\redis\Cache\CacheBackendFactory',
        'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
      ],
      'cache.container' => [
        'class' => '\Drupal\redis\Cache\PhpRedis',
        'factory' => ['@cache.backend.redis', 'get'],
        'arguments' => ['container'],
      ],
      'cache_tags_provider.container' => [
        'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
        'arguments' => ['@redis.factory'],
      ],
      'serialization.phpserialize' => [
        'class' => 'Drupal\Component\Serialization\PhpSerialize',
      ],
    ],
  ];
}

if (getenv('LANDO_INFO')) {
  $lando_info = json_decode(getenv('LANDO_INFO'), TRUE);

  $settings['redis.connection']['interface'] = 'PhpRedis';
  $settings['redis.connection']['host'] = $lando_info['cache']['internal_connection']['host'];
  $settings['redis.connection']['port'] = $lando_info['cache']['internal_connection']['port'];
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['container_yamls'][] = 'modules/redis/example.services.yml';

  // Mailhog.
  $config['smtp.settings']['smtp_on'] = TRUE;
  $config['smtp.settings']['smtp_host'] = 'mailhog';
  $config['smtp.settings']['smtp_port'] = 1025;
}