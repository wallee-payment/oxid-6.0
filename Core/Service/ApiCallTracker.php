<?php
/**
 * Wallee OXID
 *
 * This OXID module enables to process payments with Wallee (https://www.wallee.com/).
 *
 * @package Whitelabelshortcut\Wallee
 * @author customweb GmbH (http://www.customweb.com/)
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache Software License (ASL 2.0)
 */


namespace Wle\Wallee\Core\Service;

use Monolog\Logger;
use Wle\Wallee\Core\WalleeModule;

/**
 * Class ApiCallTracker
 * Counts the Wallee API calls issued during a single request and logs a summary
 * when the request ends. Used to measure the checkout performance.
 *
 * @codeCoverageIgnore
 */
class ApiCallTracker
{
    /** @var array operation name => array('count' => int, 'time' => float) */
    private static $operations = array();
    private static $count = 0;
    private static $time = 0.0;
    private static $summaryRegistered = false;

    /**
     * Executes the given API call and records how long it took.
     *
     * @param string $operation
     * @param callable $call
     * @return mixed
     * @throws \Exception
     */
    public static function track($operation, $call)
    {
        self::registerSummary();
        $start = microtime(true);
        try {
            return $call();
        } finally {
            self::record($operation, microtime(true) - $start);
        }
    }

    private static function record($operation, $elapsed)
    {
        if (!isset(self::$operations[$operation])) {
            self::$operations[$operation] = array('count' => 0, 'time' => 0.0);
        }
        self::$operations[$operation]['count']++;
        self::$operations[$operation]['time'] += $elapsed;
        self::$count++;
        self::$time += $elapsed;
    }

    /**
     * Total number of API calls issued during this request.
     *
     * @return int
     */
    public static function getCount()
    {
        return self::$count;
    }

    /**
     * Total time spent waiting for the API during this request, in seconds.
     *
     * @return float
     */
    public static function getTime()
    {
        return self::$time;
    }

    /**
     * Human readable summary of the API calls issued during this request.
     *
     * @return string
     */
    public static function getSummary()
    {
        $details = array();
        foreach (self::$operations as $operation => $stats) {
            $details[] = sprintf('%s=%dx/%.3fs', $operation, $stats['count'], $stats['time']);
        }
        return sprintf('%d calls in %.3fs [%s]', self::$count, self::$time, implode(', ', $details));
    }

    /**
     * Resets the collected statistics.
     */
    public static function reset()
    {
        self::$operations = array();
        self::$count = 0;
        self::$time = 0.0;
    }

    private static function registerSummary()
    {
        if (self::$summaryRegistered) {
            return;
        }
        self::$summaryRegistered = true;
        $requestStart = isset($_SERVER['REQUEST_TIME_FLOAT']) ? $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        register_shutdown_function(function () use ($requestStart) {
            try {
                $request = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'cli';
                $total = microtime(true) - $requestStart;
                WalleeModule::log(Logger::DEBUG,
                    sprintf('API usage for %s: %s, request total %.3fs.', $request, ApiCallTracker::getSummary(), $total));
            } catch (\Exception $e) {
                // the summary must never break the response
            }
        });
    }
}
