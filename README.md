# PublicTweetStream for PHP

Playing around with streaming API and React in PHP.

## Usage

Download and install composer:

    curl -s https://getcomposer.org/installer | php

Install vendors:

    php composer.phar install

Example code

    $publicTweetStream = new \PublicTweetStream\PublicTweetStream($config);
    $publicTweetStream->on('tweet', function ($tweet) {
        echo '@' . $tweet->user->screen_name . ': ' . $tweet->text . PHP_EOL;
    });

    $publicTweetStream->startStream();
