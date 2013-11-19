<?php

namespace PublicTweetStream;

use Evenement\EventEmitter2 as EventEmitter,
    React\HttpClient\Client,
    React\HttpClient\Request,
    React\EventLoop\StreamSelectLoop,
    React\EventLoop\Factory as EventLoopFactory,
    React\Dns\Resolver\Factory as DnsResolverFactory,
    React\HttpClient\Factory as HttpClientFactory;

/**
 * Public tweets streaming API connection
 *
 * @author Mark Wilson <mark@89allport.co.uk>
 */
class PublicTweetStream extends EventEmitter
{
    const HTTP_METHOD = 'POST';
    const FILTER_URL= 'https://stream.twitter.com/1.1/statuses/filter.json';

    /**
     * Configuration
     *
     * @var array
     */
    private $config;

    /**
     * HTTP client
     *
     * @var Client
     */
    private $client;

    /**
     * Event loop
     *
     * @var StreamSelectLoop
     */
    private $loop;

    /**
     * HTTP request
     *
     * @var Request
     */
    private $request;

    /**
     * Constructor
     *
     * @param array $config Configuration
     */
    public function __construct($config)
    {
        parent::__construct();

        $this->parseConfig($config);
        $this->initialiseClient();
        $this->initialiseRequest();
    }

    /**
     * Initialise streaming
     *
     * @return void
     */
    public function startStream()
    {
        $this->request->end();
        $this->loop->run();
    }

    /**
     * Parse configuration
     *
     * @param array $config Configuration
     *
     * @throws \Exception If invalid configuration is provided
     *
     * @return void
     */
    protected function parseConfig($config)
    {
        if (!isset($config['dns'])) {
            throw new \Exception('No DNS settings');
        }

        if (!isset($config['dns']['server'])) {
            $config['dns']['server'] = '8.8.8.8';
        }

        if (!isset($config['dns']['cached'])) {
            $config['dns']['cached'] = true;
        }

        if (!isset($config['twitter'], $config['twitter']['consumer_key'], $config['twitter']['consumer_secret'], $config['twitter']['access_token'], $config['twitter']['access_token_secret'])) {
            throw new \Exception('No Twitter settings');
        }

        if (!isset($config['twitter']['search'])) {
            throw new \Exception('No search term provided');
        } elseif (is_string($config['twitter']['search'])) {
            $config['twitter']['search'] = array($config['twitter']['search']);
        }

        $this->config = $config;
    }

    /**
     * Initialise client connection
     *
     * @return void
     */
    protected function initialiseClient()
    {
        $this->loop = EventLoopFactory::create();

        $dnsResolverFactory = new DnsResolverFactory();
        if ($this->config['dns']['cached']) {
            $dnsResolver = $dnsResolverFactory->createCached($this->config['dns']['server'], $this->loop);
        }
        else {
            $dnsResolver = $dnsResolverFactory->create($this->config['dns']['server'], $this->loop);
        }

        $factory = new HttpClientFactory();
        $client = $factory->create($this->loop, $dnsResolver);

        $this->client = $client;
    }

    /**
     * Initialise request on client
     *
     * @return void
     */
    protected function initialiseRequest()
    {
        $postData = array();
        $params   = array(
            'track' => $this->config['twitter']['search']
        );

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = implode(',', $value);

                $urlEncodedValues = array_map('urlencode', $value);
                $postData[] = $key . '=' . implode(',', $urlEncodedValues);
            } else {
                $postData[] = $key . '=' . urlencode($value);
            }
        }

        $headers = array(
            'Authorization' => $this->generateHeader($params),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Content-Length' => strlen(implode('&', $postData)),
            'Connection-Type' => 'Close'
        );

        $request = $this->client->request(self::HTTP_METHOD, self::FILTER_URL, $headers);

        // adding support for earlier versions of PHP - $this is not available in closure before 5.4
        $scope = $this;

        $request->on('response', function ($response) use ($scope) {
            if ($response->getCode() != 200) {
                die($response->getCode() . ': ' . $response->getReasonPhrase() . PHP_EOL);
            }

            $response->on('data', function ($data) use ($scope) {
                if ('' === trim($data)) {
                    $scope->emit('empty data');
                    return;
                }

                $tweet = json_decode($data);

                if (null === $tweet) {
                    $scope->emit('invalid data', array('data' => $data));
                    return;
                }

                if (isset($tweet->limit)) {
                    $scope->emit('limit', array('limit' => $tweet));
                    return;
                }

                // @todo: update this to process tweet as HTML if required by config
                $scope->emit('tweet', array('tweet' => $tweet));
            });
        });

        $request->on('headers-written', function ($that) use ($postData) {
            $that->write(implode('&', $postData));
        });

        $request->on('error', function () {
            die(var_dump(func_get_args()));
        });

        $request->on('end', function ($error) use ($scope) {
            $scope->emit('error', array('error' => $error));
        });

        $this->request = $request;
    }

    /**
     * Generate Authorization header
     *
     * @param array $params Parameters we're sending
     *
     * @return string
     */
    private function generateHeader(array $params = null)
    {
        $consumer = new \JacobKiers\OAuth\Consumer\Consumer($this->config['twitter']['consumer_key'], $this->config['twitter']['consumer_secret']);
        $token    = new \JacobKiers\OAuth\Token\Token($this->config['twitter']['access_token'], $this->config['twitter']['access_token_secret']);

        $oauthRequest = \JacobKiers\OAuth\Request\Request::fromConsumerAndToken($consumer, $token, self::HTTP_METHOD, self::FILTER_URL, $params);
        $oauthRequest->signRequest(new \JacobKiers\OAuth\SignatureMethod\HmacSha1(), $consumer, $token);

        return trim(substr($oauthRequest->toHeader(), 15));
    }
}
