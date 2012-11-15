<?php

namespace PublicTweetStream;

use Evenement\EventEmitter2 as EventEmitter,
    React\HttpClient\Client,
    React\HttpClient\Request,
    React\EventLoop\StreamSelectLoop,
    Exception,
    React\EventLoop\Factory as EventLoopFactory,
    React\Dns\Resolver\Factory as DnsResolverFactory,
    React\HttpClient\Factory as HttpClientFactory;

/**
 * Public tweets streaming API connection
 */
class PublicTweetStream extends EventEmitter
{
    /**
     * @var array
     */
    protected $_config;
    /**
     * @var Client
     */
    protected $_client;
    /**
     * @var StreamSelectLoop
     */
    protected $_loop;
    /**
     * @var Request
     */
    protected $_request;

    /**
     * @param array $config
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
     */
    public function startStream()
    {
        $this->_request->end();
        $this->_loop->run();
    }

    /**
     * @param $config
     * @throws Exception
     */
    protected function parseConfig($config)
    {
        if (!isset($config['dns'])) {
            throw new Exception('No DNS settings');
        }

        if (!isset($config['dns']['server'])) {
            $config['dns']['server'] = '8.8.8.8';
        }

        if (!isset($config['dns']['cached'])) {
            $config['dns']['cached'] = true;
        }

        if (!isset($config['twitter'], $config['twitter']['username'], $config['twitter']['password'])) {
            throw new Exception('No Twitter settings');
        }

        if (!isset($config['twitter']['search'])) {
            throw new Exception('No search term provided');
        }
        else if (is_array($config['twitter']['search'])) {
            $search = $config['twitter']['search'];
            $search = array_map('urlencode', $search);
            $search = implode(',', $search);
            $config['twitter']['search'] = $search;
        }
        else if (is_string($config['twitter']['search'])) {
            $config['twitter']['search'] = urlencode($config['twitter']['search']);
        }

        $this->_config = $config;
    }

    /**
     * Initialise client connection
     */
    protected function initialiseClient()
    {
        $this->_loop = EventLoopFactory::create();

        $dnsResolverFactory = new DnsResolverFactory();
        if ($this->_config['dns']['cached']) {
            $dnsResolver = $dnsResolverFactory->createCached($this->_config['dns']['server'], $this->_loop);
        }
        else {
            $dnsResolver = $dnsResolverFactory->create($this->_config['dns']['server'], $this->_loop);
        }

        $factory = new HttpClientFactory();
        $client = $factory->create($this->_loop, $dnsResolver);

        $this->_client = $client;
    }

    /**
     * Initialise request on client
     */
    protected function initialiseRequest()
    {
        $data = 'track=' . $this->_config['twitter']['search'];

        $request = $this->_client->request('POST', 'https://stream.twitter.com/1.1/statuses/filter.json', array(
            'Authorization' => 'Basic ' . base64_encode($this->_config['twitter']['username'] . ':' . $this->_config['twitter']['password']),
            'Content-type' => 'application/x-www-form-urlencoded',
            'Content-length' => strlen($data),
            'Connection-type' => 'Close'
        ));
        $request->write($data);

        // adding support for earlier versions of PHP - this is not available in closure before 5.4
        $that = $this;

        /** @var $response \React\HttpClient\Response */
        $request->on('response', function ($response) use ($that) {
            $response->on('data', function ($data) use ($that) {
                if ('' === trim($data)) {
                    $that->emit('empty data');
                    return;
                }

                $tweet = json_decode($data);

                if (null === $tweet) {
                    $that->emit('invalid data', array('data' => $data));
                    return;
                }

                if (isset($tweet->limit)) {
                    $that->emit('limit', array('limit' => $tweet));
                    return;
                }

                $that->emit('tweet', array('tweet' => $tweet));
            });
        });
        $request->on('end', function ($error) use ($that) {
            $that->emit('error', array('error' => $error));
        });

        $this->_request = $request;
    }
}