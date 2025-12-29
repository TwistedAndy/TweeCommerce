<?php

namespace App\Core\Action;

use App\Core\Container\Container;

class ActionService
{
    protected array $instantCallbacks  = [];
    protected array $deferredCallbacks = [];

    protected ActionModel $model;
    protected Container   $container;

    protected bool $hasPendingJobs = false;

    protected int $batchInterval;
    protected int $batchTimeout;
    protected int $batchSize;

    public function __construct(ActionModel $model, Container $container)
    {
        $this->container = $container;
        $this->model     = $model;

        $this->batchSize     = 10;
        $this->batchTimeout  = 7200;
        $this->batchInterval = 30;

        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Register a callback.
     */
    public function add(string $action, callable|array|string $callback, int $priority = 10, bool $instant = true): self
    {
        $store = $instant ? 'instantCallbacks' : 'deferredCallbacks';

        if (strlen($action) > 191) {
            throw new ActionException("Action name '{$action}' too long.");
        }

        if ($priority < 1) {
            $priority = 1;
        } elseif ($priority > 255) {
            $priority = 255;
        }

        $callbackKey = $this->container->getCallbackKey($callback);

        if (!isset($this->{$store}[$action])) {
            $this->{$store}[$action] = [];
        }

        if (!isset($this->{$store}[$action][$priority])) {
            $this->{$store}[$action][$priority] = [];
        }

        $this->{$store}[$action][$priority][$callbackKey] = $callback;

        return $this;
    }

    /**
     * Schedule an action to be executed later
     */
    public function schedule(string $action, callable|array|string $callback, array $args = [], int $priority = 10, int|string $scheduledAt = 0, int|string $recurringTime = 0): void
    {
        $now = time();

        // Prepare Callback and Payload
        list($storedCallback, $storedPayload) = $this->prepareCallbackData($callback, $args);

        // Validate scheduled time
        if (is_string($scheduledAt)) {
            $scheduledAt = strtotime($scheduledAt);
        }

        if (empty($scheduledAt)) {
            $scheduledAt = $now;
        }

        if ($recurringTime) {
            if (is_string($recurringTime) and !is_numeric($recurringTime) and strtotime($recurringTime) === false) {
                throw new ActionException("Invalid recurring string: $recurringTime");
            } elseif (is_numeric($recurringTime)) {
                $recurringTime = (int) $recurringTime;
            }
        } else {
            $recurringTime = 0;
        }

        $action = [
            'action'       => $action,
            'callback'     => $storedCallback,
            'payload'      => $storedPayload,
            'priority'     => $priority,
            'signature'    => hash('xxh3', $action . $storedCallback . $storedPayload),
            'scheduled_at' => $scheduledAt,
        ];

        if ($recurringTime) {
            $action['recurring'] = $recurringTime;
        }

        $this->model->deferBatch([$action]);
    }

    /**
     * Trigger an action instantly or schedule it to run later
     */
    public function trigger(string $action, ...$args): void
    {
        if (isset($this->instantCallbacks[$action])) {
            ksort($this->instantCallbacks[$action]);
            foreach ($this->instantCallbacks[$action] as $priorityGroup) {
                foreach ($priorityGroup as $callback) {
                    $this->container->call($callback, $args);
                }
            }
        }

        if (isset($this->deferredCallbacks[$action])) {

            $actions = [];

            foreach ($this->deferredCallbacks[$action] as $priority => $callbacks) {

                foreach ($callbacks as $callback) {
                    // Convert the live callback into storage-ready format
                    list($storedCallback, $storedPayload) = $this->prepareCallbackData($callback, $args);

                    $actions[] = [
                        'action'    => $action,
                        'callback'  => $storedCallback,
                        'payload'   => $storedPayload,
                        'priority'  => $priority,
                        'signature' => hash('xxh3', $action . $storedCallback . $storedPayload),
                    ];
                }

            }

            if ($actions) {
                $this->hasPendingJobs = true;
                $this->model->deferBatch($actions, $this->batchInterval, $this->batchTimeout);
            }

        }
    }

    /**
     * Worker method to execute jobs.
     */
    public function runBatch(): void
    {
        $timeLimit = (int) ini_get('max_execution_time');

        // Sanity check for CLI or infinite limits
        if ($timeLimit <= 0 or $timeLimit > 1800) {
            $timeLimit = 1800;
        }

        $now        = time();
        $cache      = app('cache');
        $startTime  = $now;
        $maxRunTime = $timeLimit - 5;

        // Cleanup stuck actions
        $lastCleanup = (int) $cache->get('actions_retry');

        if ($lastCleanup <= $now - $this->batchTimeout) {
            $this->model->retryActions($this->batchTimeout);
            $cache->save('actions_retry', $now, $this->batchTimeout);
        }

        while ((time() - $startTime) < $maxRunTime) {

            // Claim a batch
            $actions = $this->model->claimBatch($this->batchSize);

            // If no jobs left, we can exit early to save resources
            if (empty($actions)) {
                break;
            }

            foreach ($actions as $key => $action) {
                if ((time() - $startTime) >= $maxRunTime) {
                    $remaining = array_slice($actions, $key);
                    $this->model->releaseBatch($remaining);
                    return;
                }

                $callback = $action['callback'];
                $payload  = !empty($action['payload']) ? unserialize($action['payload']) : [];

                try {
                    // Use a DI container to auto-wire services and mixing in the arguments
                    if ($callback === ActionClosure::class) {
                        if (!$payload instanceof ActionClosure) {
                            throw new ActionException('Invalid closure data.');
                        }
                        $this->container->call($payload->getClosure(), $payload->getArgs());
                    } else {
                        $this->container->call($callback, $payload);
                    }

                    $this->model->completeBatch([$action]);

                    // Handle Recursion
                    if (!empty($action['recurring'])) {
                        $this->handleRecurring($action);
                    }
                } catch (\Throwable $e) {
                    $action['message'] = 'Error: ' . $e->getMessage() . '. Trace: ' . $e->getTraceAsString();
                    $this->model->failBatch([$action]);
                }
            }
        }
    }

    /**
     * Spawn a worker and retry failed actions on shutdown
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $now   = time();
        $cache = app('cache');

        $lastTrigger = (int) $cache->get('actions_spawn');

        if ($lastTrigger > $now - $this->batchInterval) {
            return;
        }

        $cache->save('actions_spawn', $now, $this->batchInterval);

        // Ensure the script keeps running even if the user disconnects
        \ignore_user_abort(true);

        if (function_exists('\fastcgi_finish_request')) {
            // FastCGI (Nginx/FPM)
            \fastcgi_finish_request();
        } elseif (function_exists('\litespeed_finish_request')) {
            // LiteSpeed
            \litespeed_finish_request();
        } elseif (!headers_sent() and !\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true)) {
            // Prepare headers for connection closure
            header('Connection: close');

            // Disable compression to ensure length accuracy
            header('Content-Encoding: none');

            if (ob_get_level() > 0) {
                header('Content-Length: ' . ob_get_length());
                ob_end_flush();
            } else {
                header('Content-Length: 0');
            }

            flush();
        }

        // Spawn a worker
        $this->spawnWorker();
    }

    /**
     * Spawn a worker to process actions
     */
    public function spawnWorker(): void
    {
        try {
            $client = app('curlrequest');
            $client->request('GET', site_url('actions/run'), [
                'query'       => ['key' => $this->getSpawnKey()],
                'timeout'     => 0.1,
                'verify'      => false,
                'http_errors' => false,
                'user_agent'  => 'ActionWorker/1.0',
            ]);
        } catch (\Throwable $e) {
            // Skip the timed out exceptions with code 28
            if (!str_contains($e->getMessage(), '28')) {
                log_message('error', 'Worker Spawn Error: ' . $e->getMessage(), ['exception' => $e]);
            }
        }
    }

    /**
     * Check the spawn key
     */
    public function checkSpawnKey(string $key): bool
    {
        return $this->getSpawnKey() === $key;
    }

    /**
     * Generate a spawn key
     *
     * @return string
     */
    protected function getSpawnKey(): string
    {
        return hash_hmac('sha256', floor(time() / 1000), getenv('ACTION_KEY') ?? 'default');
    }

    /**
     * Re-schedule recurring actions
     */
    protected function handleRecurring(array $action): void
    {
        if (empty($action['recurring'])) {
            return;
        }

        $now       = time();
        $recurring = $action['recurring'];

        // Use the original scheduled_at if available to prevent drift
        $baseTime = $action['scheduled_at'] ?? $now;

        if (is_numeric($recurring) and $recurring > 0) {
            $interval = (int) $recurring;
            $nextRun  = $baseTime + $interval;

            // If next run is in the past, jump to the future safely
            if ($nextRun <= $now) {
                // Calculate how many steps we missed and add them to the time
                $missedSteps = floor(($now - $nextRun) / $interval) + 1;
                $nextRun     += ($missedSteps * $interval);
            }
        } else {
            // For string offsets like '+1 hour', use standard strtotime logic
            $nextRun = strtotime($recurring, $baseTime);

            if ($nextRun === false) {
                throw new ActionException('Failed to resolve the recurring time: ' . $recurring);
            }

            if ($nextRun <= $now and strpos($recurring, 'next') === false) {
                $nextRun = strtotime('next ' . $recurring, $baseTime);
            }

            $attempts = 0;

            // Try to resolve the recurring time
            while ($nextRun <= $now and $attempts < 10) {
                $nextRun = strtotime($recurring, $nextRun);
                $attempts++;
            }
        }

        if ($nextRun === false or $nextRun < $now) {
            throw new ActionException('The recurring time is in the past: ' . $recurring);
        }

        $action['scheduled_at'] = $nextRun;

        $this->model->deferBatch([$action]);
    }

    /**
     * Convert a PHP callable into the callback key and payload
     */
    protected function prepareCallbackData(callable|array|string $callback, array $args): array
    {
        if ($callback instanceof \Closure) {
            $wrapper     = new ActionClosure($callback, $args);
            $callbackKey = ActionClosure::class;
            $serialized  = serialize($wrapper);
        } else {
            $callbackKey = $this->container->getCallbackKey($callback);
            $serialized  = serialize($args);
        }

        if (strlen($serialized) > 65000) {
            throw new ActionException('Serialized closure is too long.');
        }

        return [
            $callbackKey,
            $serialized
        ];
    }

}