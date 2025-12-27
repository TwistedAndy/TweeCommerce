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
    protected bool $workerSpawned  = false;

    protected int $batchInterval;
    protected int $batchTimeout;
    protected int $batchSize;

    public function __construct(ActionModel $model, Container $container)
    {
        $this->container = $container;
        $this->model     = $model;

        $this->batchSize     = 10;
        $this->batchTimeout  = 7200;
        $this->batchInterval = 60;

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

        $startTime  = time();
        $maxRunTime = $timeLimit - 5;

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
                } catch (\Exception $e) {
                    $action['message'] = 'Error: ' . $e->getMessage() . '. Trace: ' . $e->getTraceAsString();
                    $this->model->failBatch([$action]);
                }
            }
        }
    }

    /**
     * Flush callback buffer to the database and trigger a worker request
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        if (rand(1, 100) === 1) {
            $this->model->retryActions($this->batchTimeout);
        }

        $this->spawnWorker();
    }

    /**
     * Spawrn a worker
     *
     * @return void
     */
    protected function spawnWorker(): void
    {
        if ($this->workerSpawned) {
            return;
        }

        $now = time();

        $lastTrigger = (int) app('cache')->get('spawned_worker');

        if ($lastTrigger > $now - $this->batchInterval) {
            return;
        }

        $this->workerSpawned = true;

        app('cache')->save('spawned_worker', $now, 60);

        $url    = site_url('actions/process');
        $parts  = parse_url($url);
        $secret = getenv('ACTION_SECRET') ? : 'default';

        $fp = @fsockopen(
            ($parts['scheme'] === 'https' ? 'ssl://' : '') . $parts['host'],
            $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80),
            $errno,
            $errstr,
            0.01
        );

        if ($fp) {
            $out = "POST " . ($parts['path'] ?? '/') . " HTTP/1.1\r\n";
            $out .= "Host: " . $parts['host'] . "\r\n";
            $out .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $out .= "Content-Length: 0\r\n";
            $out .= "X-Action-Secret: " . $secret . "\r\n";
            $out .= "Connection: Close\r\n\r\n";
            fwrite($fp, $out);
            fclose($fp);
        }
    }

    /**
     * Re-schedule recurring actions
     */
    protected function handleRecurring(array $action): void
    {
        if (empty($action['recurring'])) {
            return;
        }

        $now           = time();
        $recurringTime = $action['recurring'];

        // Use the original scheduled_at if available to prevent drift
        $baseTime = $action['scheduled_at'] ?? $now;

        if (is_numeric($recurringTime)) {
            $nextRun = $baseTime + (int) $recurringTime;
        } else {
            // For string offsets like '+1 hour', use standard strtotime logic
            $nextRun = strtotime($recurringTime, $baseTime);

            if ($nextRun === false) {
                throw new ActionException('Failed to resolve the recurring time: ' . $recurringTime);
            }

            if ($nextRun < $now and strpos($recurringTime, 'next') === false) {
                $nextRun = strtotime('next ' . $recurringTime, $baseTime);
            }

            if ($nextRun === false or $nextRun < $now) {
                throw new ActionException('The recurring time is in the past: ' . $recurringTime);
            }

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