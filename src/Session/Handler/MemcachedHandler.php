<?php

namespace Bolt\Session\Handler;

use Bolt\Session\OptionsBag;
use GuzzleHttp\Psr7;
use Memcached;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MemcachedSessionHandler as BaseHandler;

class MemcachedHandler extends BaseHandler implements SessionHandlerInterface {

    /**
     * Creates and returns an instance of the MemcacheHandler based on
     * the save_path string, commonly used in PHP ini files.
     *
     * Format: tcp://1.2.3.4:1121?persistent=1&weight=1&timeout=1&retry_interval=15,tcp://1.2.3.4:1121?persistent=1&weight=1&timeout=1&retry_interval=15, ...
     *
     * @param array $options session storage configuration options
     * @return MemcacheHandler
     */
    public static function create(OptionsBag $options){

        $connections = self::parseConnections($options, 'localhost', 11211);

        $memcache = new Memcached();

        foreach ($connections as $conn) {
            $memcache->addServer(
                $conn['host'] ?: 'localhost',
                $conn['port'] ?: 11211,
                $conn['weight'] ?: 0
            );
        }

        // Take expiretime and prefix as "special options" and relay them to the sf2 session handler
        $handlerOptions = array_intersect_key($options->all(), array_flip(["expiretime", "prefix"]));

        return new self($memcache, $handlerOptions);
    }

    /**
     * Connection parser for odd styled memcached save_paths
     *
     * @param array $options          session storage configuration options
     * @param string $defaultHost     default host address
     * @param integer $defaultPort    default port
     * @return array
     */
    protected static function parseConnections($options, $defaultHost, $defaultPort)
    {
        // Take anything already configured in options[host], options[port] or even options[connections]
        if (isset($options['host']) || isset($options['port'])) {
            $options['connections'][] = $options;
        } elseif ($options['connection']) {
            $options['connections'][] = $options['connection'];
        }

        $connections = [];
        // parse anything in options[connections]
        if (isset($options['connections'])) {
            foreach ((array) $options['connections'] as $conn) {
                if (is_string($conn)) {
                    $conn = ['host' => $conn];
                }
                $conn += [
                    'host'   => $defaultHost,
                    'port'   => $defaultPort
                ];

                $connections[] = $conn;
            }
        // parse anything in options[save_path]
        } elseif (isset($options['save_path'])) {
            foreach (explode(',', $options['save_path']) as $conn) {

                // TODO Take care of new list() behaviour in PHP7 (But this should allways be a two-element array anyways according to specs?)
                list($host, $port) = explode(":",$conn);

                $conn = [
                    "host" => $host ? $host : $defaultHost,
                    "port" => $port ? $port : $defaultPort
                ];

                $connections[] = $conn;
            }
        }

        return $connections;
    }

}