<?php

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\FileLoader;
use Symfony\Component\Yaml\Yaml;

$configDirectories = array(__DIR__ . '/../config');

$locator = new FileLocator($configDirectories);
try {
    $yamlConfig = $locator->locate('public-tweet-stream.prod.yml', null, true);
}
catch (InvalidArgumentException $e) {
    try {
        $yamlConfig = $locator->locate('public-tweet-stream.yml', null, true);
    }
    catch (InvalidArgumentException $e) {
        throw new Exception('Config not found');
    }
}

$loader = new PublicTweetStream\SettingsLoader($locator);
$config = $loader->import($yamlConfig);

$publicTweetStream = new \PublicTweetStream\PublicTweetStream($config);
$publicTweetStream->on('tweet', function ($tweet) {
    echo '@' . $tweet->user->screen_name . ': ' . $tweet->text . PHP_EOL;
});

$publicTweetStream->startStream();
