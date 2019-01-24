<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\console\controllers;

use Craft;
use craft\helpers\Console;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use putyourlightson\blitz\Blitz;
use yii\base\Exception;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\log\Logger;

/**
 * Performs functions on the Blitz cache.
 */
class CacheController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Clears the cache (pages only).
     *
     * @return int
     */
    public function actionClear(): int
    {
        Blitz::$plugin->invalidate->clearCache(false);

        $this->stdout(Craft::t('blitz', 'Blitz cache successfully cleared.').PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Flushes the cache (pages and database records).
     *
     * @return int
     */
    public function actionFlush(): int
    {
        Blitz::$plugin->invalidate->clearCache(true);

        $this->stdout(Craft::t('blitz', 'Blitz cache successfully flushed.').PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Refreshes expired cache.
     *
     * @return int
     */
    public function actionRefreshExpired(): int
    {
        Blitz::$plugin->invalidate->refreshExpiredCache();

        Craft::$app->getQueue()->run();

        $this->stdout(Craft::t('blitz', 'Expired Blitz cache successfully refreshed.').PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Flushes and warms the entire cache.
     *
     * @return int
     * @throws Exception
     */
    public function actionWarm(): int
    {
        if (!Blitz::$plugin->settings->cachingEnabled) {
            $this->stderr(Craft::t('blitz', 'Blitz caching is disabled.').PHP_EOL, Console::FG_RED);

            return ExitCode::OK;
        }

        // Get warm cache URLS
        $urls = Blitz::$plugin->invalidate->getAllCachedUrls();

        $this->stdout(Craft::t('blitz', 'Flushing Blitz cache.').PHP_EOL, Console::FG_GREEN);

        Blitz::$plugin->invalidate->clearCache(true);

        $total = count($urls);
        $count = 0;
        $success = 0;
        $urlErrors = false;
        $client = Craft::createGuzzleClient();
        $requests = [];

        $this->stdout(Craft::t('blitz', 'Warming Blitz cache.').PHP_EOL, Console::FG_GREEN);

        Console::startProgress(0, $total, '', 0.8);

        foreach ($urls as $url) {
            // Ensure URL is an absolute URL starting with http
            if (strpos($url, 'http') === 0) {
                $requests[] = new Request('GET', $url);
            }
            else {
                $urlErrors = true;
                $count++;
                Console::updateProgress($count, $total);
            }
        }

        // Create a pool of requests for sending multiple concurrent requests
        $pool = new Pool($client, $requests, [
            'concurrency' => Blitz::$plugin->settings->concurrency,
            'fulfilled' => function () use (&$success, &$count, $total) {
                $success++;
                $count++;
                Console::updateProgress($count, $total);
            },
            'rejected' => function ($reason) use (&$count, $total) {
                $count++;
                Console::updateProgress($count, $total);

                if ($reason instanceof RequestException) {
                    /** RequestException $reason */
                    preg_match('/^(.*?)\R/', $reason->getMessage(), $matches);

                    if (!empty($matches[1])) {
                        Craft::getLogger()->log(trim($matches[1], ':'), Logger::LEVEL_ERROR, 'blitz');
                    }
                }
            },
        ]);

        // Initiate the transfers and wait for the pool of requests to complete
        $pool->promise()->wait();

        Blitz::$plugin->invalidate->runGarbageCollection();

        Console::updateProgress($total, $total);
        Console::endProgress();

        if ($urlErrors) {
            $this->stdout(Craft::t('blitz', 'One or more URLs do not begin with "http" and were ignored. Please ensure that your site’s base URLs do not use the @web alias.').PHP_EOL, Console::FG_RED);
        }

        $this->stdout(Craft::t('blitz', 'Blitz cache successfully warmed {success} files.', ['success' => $success]).PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}