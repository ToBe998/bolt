<?php

namespace Bolt\Session\Handler;

use GuzzleHttp\Psr7;
use GuzzleHttp\Psr7\Uri;
use Memcache;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MemcacheSessionHandler as BaseHandler;

class MemcacheHandler extends BaseHandler implements SessionHandlerInterface {

    /**
     * Creates and returns an instance of the MemcacheHandler based on
     * the save_path string, commonly used in PHP ini files.
     *
     * Format: tcp://1.2.3.4:1121?persistent=1&weight=1&timeout=1&retry_interval=15,tcp://1.2.3.4:1121?persistent=1&weight=1&timeout=1&retry_interval=15, ...
     *
     * @param array $options session storage configuration options
     * @return MemcacheHandler
     */
    public static function create($options){

        $connections = self::parseConnections($options, 'localhost', 11211);

        $memcache = new Memcache();

        foreach ($connections as $conn) {
            $memcache->addServer(
                $conn['host'] ?: 'localhost',
                $conn['port'] ?: 11211,
                $conn['persistent'] ?: false,
                $conn['weight'] ?: 0,
                $conn['timeout'] ?: 1
            );
        }

        // Take expiretime and prefix as "special options" and relay them to the sf2 session handler
        $handlerOptions = array_intersect_key($options, array_flip(["expiretime", "prefix"]));

        return new self($memcache, $handlerOptions);
    }

    /**
     * Connection parser as moved from Bolt\Provider\SessionServiceProvider
     *
     * @param array $options          session storage configuration options
     * @param string $defaultHost     default host address
     * @param integer $defaultPort    default port
     * @return array
     */
    protected static function parseConnections($options, $defaultHost, $defaultPort)
    {
        if (isset($options['host']) || isset($options['port'])) {
            $options['connections'][] = $options;
        } elseif ($options['connection']) {
            $options['connections'][] = $options['connection'];
        }

        /** @var ParameterBag[] $toParse */
        $toParse = [];
        if (isset($options['connections'])) {
            foreach ((array) $options['connections'] as $alias => $conn) {
                if (is_string($conn)) {
                    $conn = ['host' => $conn];
                }
                $conn += [
                    'scheme' => 'tcp',
                    'host'   => $defaultHost,
                    'port'   => $defaultPort,
                ];
                $conn = new ParameterBag($conn);

                if ($conn->has('password')) {
                    $conn->set('pass', $conn->get('password'));
                    $conn->remove('password');
                }
                $conn->set('uri', Uri::fromParts($conn->all()));

                $toParse[] = $conn;
            }
        } elseif (isset($options['save_path'])) {
            foreach (explode(',', $options['save_path']) as $conn) {
                $uri = new Uri($conn);

                $connBag = new ParameterBag();
                $connBag->set('uri', $uri);
                $connBag->add(Psr7\parse_query($uri->getQuery()));

                $toParse[] = $connBag;
            }
        }

        $connections = [];
        foreach ($toParse as $conn) {
            /** @var Uri $uri */
            $uri = $conn->get('uri');

            $parts = explode(':', $uri->getUserInfo(), 2);
            $password = isset($parts[1]) ? $parts[1] : null;

            $connections[] = [
                'scheme'     => $uri->getScheme(),
                'host'       => $uri->getHost(),
                'port'       => $uri->getPort(),
                'path'       => $uri->getPath(),
                'alias'      => $conn->get('alias'),
                'prefix'     => $conn->get('prefix'),
                'password'   => $password,
                'database'   => $conn->get('database'),
                'persistent' => $conn->get('persistent'),
                'weight'     => $conn->get('weight'),
                'timeout'    => $conn->get('timeout'),
            ];
        }

        return $connections;
    }

}