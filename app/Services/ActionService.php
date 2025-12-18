<?php

namespace App\Services;

use App\Core\Container;
use App\Models\ActionModel;
use App\Exceptions\ActionException;
use CodeIgniter\Events\Events;

class ActionService
{
    protected array $instantCallbacks = [];
    protected array $deferredCallbacks = [];
    protected array $deferredBuffer = [];
    protected ActionModel $actionModel;
    protected Container $container;
    protected bool $hasPendingJobs = false;
    protected static bool $workerTriggered = false;

    public function __construct(ActionModel $actionModel, Container $container)
    {
        $this->actionModel = $actionModel;
        $this->container = $container;

        Events::on('post_system', [$this, 'handleShutdown']);

        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Register a callback.
     */
    public function on(string $action, callable|array|string $callback, int $priority = 10, bool $instant = true): self
    {
        $store = $instant ? 'instantCallbacks' : 'deferredCallbacks';

        if (strlen($action) > 191) {
            throw new ActionException("Action name '{$action}' too long.");
        }

        if (!isset($this->{$store}[$action])) {
            $this->{$store}[$action] = [];
        }
        if (!isset($this->{$store}[$action][$priority])) {
            $this->{$store}[$action][$priority] = [];
        }

        $this->{$store}[$action][$priority][] = $callback;

        return $this;
    }

    /**
     * Trigger an action instantly or schedule it to run later
     */
    public function trigger(string $action, array $parameters = []): void
    {
        if (!empty($this->instantCallbacks[$action])) {
            ksort($this->instantCallbacks[$action]);
            foreach ($this->instantCallbacks[$action] as $priorityGroup) {
                foreach ($priorityGroup as $callback) {
                    $this->container->call($callback, $parameters);
                }
            }
        }

        if (!empty($this->deferredCallbacks[$action])) {
            foreach ($this->deferredCallbacks[$action] as $priority => $callbacks) {
                foreach ($callbacks as $callback) {
                    $signature = $this->container->getCallbackKey($callback);
                    if ($signature) {
                        $this->schedule($action, $signature, $parameters, $priority, time());
                    } else {
                        throw new ActionException("Unable to generate signature for $action");
                    }
                }
            }
        }
    }

    /**
     * Prepare a job for the database.
     */
    protected function schedule(string $action, string $callbackKey, array $args = [], int $priority = 10, int|string $scheduledAt = 0, string|int $recurringTime = null): void
    {
        $sortedArgs = $args;
        if (!empty($args) && count($args) > 1) {
            $this->sortArgs($sortedArgs);
        }

        // Deduplication hash
        $signature = md5($action . $callbackKey . json_encode($sortedArgs));

        if (isset($this->deferredBuffer[$signature])) {
            return;
        }

        // Validate scheduled time
        if (is_string($scheduledAt)) {
            $scheduledAt = strtotime($scheduledAt);
        }
        if (empty($scheduledAt)) {
            $scheduledAt = time();
        }

        // Validate Recurring Time
        if (!empty($recurringTime)) {
            // If it's a string, ensure it is valid
            if (is_string($recurringTime) && !is_numeric($recurringTime)) {
                if (strtotime($recurringTime) === false) {
                    throw new ActionException("Invalid recurring string: $recurringTime");
                }
            }
        } else {
            $recurringTime = null;
        }

        // Buffer the job
        $this->deferredBuffer[$signature] = [
            'action'       => $action,
            'callback'     => $callbackKey,
            'payload'      => json_encode($args),
            'status'       => ActionModel::STATUS_PENDING,
            'priority'     => $priority,
            'scheduled_at' => $scheduledAt,
            'recurring'    => $recurringTime, // Store raw string or int
            'signature'    => $signature,
            'created_at'   => time(),
            'updated_at'   => time(),
        ];

        if ($scheduledAt <= (time() + 5)) {
            $this->hasPendingJobs = true;
        }
    }


    protected function flushBuffer(): void
    {
        if (empty($this->deferredBuffer)) {
            return;
        }

        $signatures = array_keys($this->deferredBuffer);

        if (!empty($signatures)) {
            $rows = $this->actionModel->getExistingSignatures($signatures);

            foreach ($rows as $row) {

                $signature = $row['signature'];

                if (isset($this->deferredBuffer[$signature])) {
                    unset($this->deferredBuffer[$signature]);
                }

            }
        }

        if (!empty($this->deferredBuffer)) {
            $this->actionModel->insertBatch(array_values($this->deferredBuffer));
            $this->deferredBuffer = [];
        }
    }

    /**
     * Worker method to execute jobs.
     */
    public function processBatch(int $batchSize = 20): void
    {
        $startTime = time() - 3; // Stop 3s before timeout
        $timeLimit = (int) ini_get('max_execution_time');

        // Sanity check for CLI or infinite limits
        if ($timeLimit <= 0 or $timeLimit > 1800) {
            $timeLimit = 1800; // Default safety fallback
        }

        $jobs = $this->actionModel->claimBatch($batchSize);

        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $key => $job) {
            // Timeout Check
            if ((time() - $startTime) >= $timeLimit) {
                $remainingJobs = array_slice($jobs, $key);
                $idsToRelease = array_column($remainingJobs, 'id');

                if (!empty($idsToRelease)) {
                    $this->actionModel->releaseBatch($idsToRelease);
                    log_message('warning', "Worker timeout. Released " . count($idsToRelease) . " jobs.");
                }
                break;
            }

            $action = $job['action'];
            $callbackKey = $job['callback'];
            $args = json_decode($job['payload'], true) ?? [];
            $jobId = $job['id'];

            try {
                $callback = $this->findCallback($action, $callbackKey);

                if (empty($callback)) {
                    throw new ActionException("Listener signature not found: " . $callbackKey);
                }

                $this->container->call($callback, $args);

                // Incremental Commit (Prevents Double Execution on crash)
                $this->actionModel->completeBatch([$jobId]);

                if (!empty($job['recurring'])) {
                    $this->handleRecurring($job, $args);
                }

            } catch (\Throwable $exception) {
                $this->actionModel->failBatch([
                    $jobId => [
                        'error_log' => [
                            'msg'   => $exception->getMessage(),
                            'trace' => $exception->getTraceAsString(),
                            'time'  => time()
                        ]
                    ]
                ]);
            }
        }

        // Save any new recurring jobs
        $this->flushBuffer();
    }

    /**
     * Flush callback buffer to the database and trigger a worker request
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        if (rand(1, 100) === 1) {
            $this->actionModel->retryStaleJobs(3600);
        }

        // FIX #3: Don't trigger worker if we only buffered future jobs
        if (empty($this->deferredBuffer) && !$this->hasPendingJobs) {
            return;
        }

        try {
            $this->flushBuffer();

            // Only trigger worker if we actually had *immediate* work
            if ($this->hasPendingJobs) {
                if (function_exists('fastcgi_finish_request')) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    fastcgi_finish_request();
                    $this->processBatch(20);
                } else {
                    $this->triggerWorkerSocket();
                }
            }
        } catch (\Throwable $e) {
            log_message('critical', 'ActionService Shutdown: ' . $e->getMessage());
        }
    }

    /**
     * Find a registered callback by a signature
     *
     * @param string $action
     * @param string $callbackKey
     *
     * @return callable|string|array|null
     */
    protected function findCallback(string $action, string $callbackKey): callable|string|array|null
    {
        if (empty($this->deferredCallbacks[$action])) {
            return null;
        }
        foreach ($this->deferredCallbacks[$action] as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                if ($this->container->getCallbackKey($callback) === $callbackKey) {
                    return $callback;
                }
            }
        }
        return null;
    }

    protected function sortArgs(array &$args): void
    {
        ksort($args);
        foreach ($args as &$value) {
            if (is_array($value)) {
                $this->sortArgs($value);
            }
        }
    }

    protected function triggerWorkerSocket(): void
    {
        if (self::$workerTriggered) {
            return;
        }

        $lastTrigger = app('cache')->get('queue_trigger');

        self::$workerTriggered = true;
        cache()->save('queue_running', true, 5);

        $url = site_url('queue/work');
        $parts = parse_url($url);
        $secret = getenv('QUEUE_SECRET') ? : 'default';

        $fp = @fsockopen(
            ($parts['scheme'] === 'https' ? 'ssl://' : '') . $parts['host'],
            $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80),
            $errno,
            $errstr,
            0.5
        );

        if ($fp) {
            $out = "POST " . ($parts['path'] ?? '/') . " HTTP/1.1\r\n";
            $out .= "Host: " . $parts['host'] . "\r\n";
            $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $out .= "Content-Length: 0\r\n";
            $out .= "X-Queue-Secret: " . $secret . "\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            fclose($fp);
        }
    }

    protected function handleRecurring(array $job, array $args): void
    {
        $time = $job['recurring'];
        // Use the original scheduled_at if available to prevent drift
        $baseTime = $job['scheduled_at'] ?? time();

        if (is_numeric($time)) {
            $nextRun = $baseTime + (int) $time;
        } else {
            // For string offsets like '+1 hour', we must use standard strtotime logic
            // Note: strtotime is always relative to NOW unless passed a base,
            // but simple relative strings are safer calculated from now or fixed intervals.
            // For simple int offsets, we use baseTime. For complex strings, be careful.
            $nextRun = strtotime($time, $baseTime);
        }

        if ($nextRun > time()) {
            $this->schedule(
                $job['action'],
                $job['callback'],
                $args,
                $job['priority'],
                $nextRun,
                $time
            );
        }
    }
}