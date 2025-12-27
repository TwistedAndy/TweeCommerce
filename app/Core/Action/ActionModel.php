<?php

namespace App\Core\Action;

use CodeIgniter\Model;

class ActionModel extends Model
{
    protected $table         = 'actions';
    protected $primaryKey    = 'id';
    protected $allowedFields = [
        'action',
        'callback',
        'payload',
        'status',
        'priority',
        'recurring',
        'signature',
        'created_at',
        'updated_at',
        'scheduled_at',
    ];

    protected string $logsTable = 'action_logs';

    protected $useTimestamps = true;
    protected $dateFormat    = 'int';

    /**
     * Action statuses
     */
    public const STATUS_PENDING  = 'pending';
    public const STATUS_RUNNING  = 'running';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED   = 'failed';

    protected static array $statuses = [];

    /**
     * Get available action statuses
     */
    public static function getStatuses(): array
    {
        $class = static::class;

        if (!isset(static::$statuses[$class])) {
            static::$statuses[$class] = [
                static::STATUS_PENDING  => __('Pending', 'system'),
                static::STATUS_RUNNING  => __('Running', 'system'),
                static::STATUS_COMPLETE => __('Complete', 'system'),
                static::STATUS_CANCELED => __('Canceled', 'system'),
                static::STATUS_FAILED   => __('Failed', 'system'),
            ];
        }

        return static::$statuses[$class];
    }

    /**
     * Current pending actions
     */
    protected ?array $pendingCache = null;

    /**
     * Defer a batch of actions
     */
    public function deferBatch(array $actions, int $batchInterval = 60, int $batchTimeout = 7200): void
    {
        if (empty($actions)) {
            return;
        }

        if (!is_array($this->pendingCache)) {
            $this->preloadPendingCache($batchInterval, $batchTimeout);
        }

        $batch = [];

        foreach ($actions as $action) {
            if (!isset($action['signature']) or isset($this->pendingCache[$action['signature']])) {
                continue;
            }

            $row = $this->prepareRow($action);

            if (is_array($row)) {
                $batch[] = $row;
            }
        }

        if (empty($batch)) {
            return;
        }

        try {
            $result = $this->builder()->insertBatch($batch);
            if ($result !== false) {
                foreach ($batch as $row) {
                    $this->pendingCache[$row['signature']] = $row['scheduled_at'];
                }
            }
        } catch (\Exception $e) {
            throw new ActionException('Failed to defer a batch. Error: ' . $e->getMessage());
        }

    }

    /**
     * Get a batch of actions and mark them as running
     */
    public function claimBatch(int $limit = 20): array
    {
        $this->db->transStart();

        try {
            $sql = "SELECT * FROM {$this->table} WHERE status = ? AND scheduled_at <= ? ORDER BY priority ASC, scheduled_at ASC LIMIT ?";

            $lockClause = $this->getLockClause();

            if ($lockClause) {
                $sql .= ' ' . $lockClause;
            }

            $now = time();

            $actions = $this->db->query($sql, [self::STATUS_PENDING, $now, $limit])->getResultArray();

            if (empty($actions)) {
                $this->db->transComplete();
                return [];
            }

            $ids = array_column($actions, $this->primaryKey);

            // Update statuses
            $this->whereIn('id', $ids)->set(['status' => self::STATUS_RUNNING, 'updated_at' => $now])->update();

            // Update the pending cache
            foreach ($actions as $action) {
                unset($this->pendingCache[$action['signature']]);
            }
        } catch (\Exception $e) {
            $this->db->transRollback();
            throw new ActionException('Failed to claim a batch. Error: ' . $e->getMessage());
        }

        $this->db->transComplete();

        return $actions;
    }

    /**
     * Reset specific jobs from Running back to Pending.
     * Used when a worker runs out of time and needs to yield tasks back to the queue.
     */
    public function releaseBatch(array $actions): void
    {
        $this->updateStatus($actions, self::STATUS_PENDING, true, 'Action Released');
    }

    /**
     * Mark a list of actions as completed
     */
    public function completeBatch(array $actions): void
    {
        $this->updateStatus($actions, static::STATUS_COMPLETE, true, 'Action Completed');
    }

    /**
     * Mark a list of actions as completed
     */
    public function cancelBatch(array $actions): void
    {
        $this->updateStatus($actions, static::STATUS_CANCELED, true, 'Action Cancelled');
    }

    /**
     * Mark a batch as failed
     */
    public function failBatch(array $actions): void
    {
        $this->updateStatus($actions, static::STATUS_FAILED, true, 'Action Failed');
    }

    /**
     * Retry actions stuck in running status due to an error
     */
    public function retryActions(int $timeoutSeconds = 7200): void
    {
        $builder = $this->builder();

        $builder->where([
            'status'       => self::STATUS_RUNNING,
            'updated_at <' => time() - $timeoutSeconds,
        ]);

        $actions = $builder->get()->getResultArray();

        $this->updateStatus($actions, self::STATUS_PENDING, true, 'Retry action after being stuck.');
    }

    /**
     * Get the supported database lock clause
     *
     * @return string
     */
    protected function getLockClause(): string
    {
        $driver = $this->db->DBDriver;
        $version = $this->db->getVersion();

        switch ($driver) {
            case 'MySQLi':
            case 'MySQL':
                if (str_contains(strtolower($version), 'maria')) {
                    $supportSkip = version_compare($version, '10.6.0', '>=');
                } else {
                    $supportSkip = version_compare($version, '8.0.1', '>=');
                }
                break;
            case 'Postgre':
                $supportSkip = version_compare($version, '9.5', '>=');
                break;
            case 'OCI8':
                $supportSkip = true;
                break;
            default:
                return '';
        }

        return $supportSkip ? 'FOR UPDATE SKIP LOCKED' : 'FOR UPDATE';
    }

    /**
     * Prepare data before inserting in the database
     */
    protected function prepareRow(array $action): array|false
    {
        if (empty($action['action']) or empty($action['callback']) or empty($action['signature'])) {
            return false;
        }

        unset($action[$this->primaryKey]);

        if (strlen($action['action']) > 191) {
            $action['action'] = substr($action['action'], 0, 191);
        }

        if (strlen($action['callback']) > 191) {
            $action['callback'] = substr($action['callback'], 0, 191);
        }

        if (strlen($action['signature']) > 32) {
            $action['signature'] = substr($action['signature'], 0, 32);
        }

        if (!empty($action['recurring']) and strlen($action['recurring']) > 64) {
            $action['recurring'] = substr($action['recurring'], 0, 64);
        }

        if (empty($action['priority']) or !is_numeric($action['priority'])) {
            $action['priority'] = 10;
        } elseif ($action['priority'] < 1) {
            $action['priority'] = 1;
        } elseif ($action['priority'] > 255) {
            $action['priority'] = 255;
        } else {
            $action['priority'] = (int) $action['priority'];
        }

        $statuses = self::getStatuses();

        if (!isset($action['status'])) {
            $action['status'] = static::STATUS_PENDING;
        } elseif (!is_string($action['status'])) {
            $action['status'] = (string) $action['status'];
        }

        if (!isset($statuses[$action['status']])) {
            $action['status'] = static::STATUS_PENDING;
        }

        $action['created_at'] = time();
        $action['updated_at'] = null;

        if (empty($action['scheduled_at']) or !is_numeric($action['scheduled_at'])) {
            $action['scheduled_at'] = time();
        } else {
            $action['scheduled_at'] = (int) $action['scheduled_at'];
        }

        return $action;

    }

    /**
     * Preload cache with pending actions
     */
    protected function preloadPendingCache(int $interval = 60, int $timeout = 7200): void
    {
        try {
            $builder = $this->builder()->select('signature, scheduled_at');

            $time = time();

            $builder->where([
                'scheduled_at <=' => $time + $interval, // Include future actions
                'scheduled_at >=' => $time - $timeout, // Exclude stuck actions
                'status'          => self::STATUS_PENDING,
            ])->orderBy('id DESC');

            $cache = [];
            $rows = $builder->get()->getResultArray();

            if ($rows) {
                foreach ($rows as $row) {
                    $cache[$row['signature']] = $row['scheduled_at'];
                }
            }
        } catch (\Exception $e) {
            throw new ActionException('Failed to get pending cache. Error: ' . $e->getMessage());
        }

        $this->pendingCache = $cache;
    }

    /**
     * Update the action statuses
     */
    protected function updateStatus(array $actions, string $status, bool $log = true, string $message = ''): bool
    {
        if (empty($actions)) {
            return false;
        }

        $statuses = self::getStatuses();

        if (!isset($statuses[$status])) {
            throw new ActionException('Incorrect status provided: ' . $status);
        }

        try {
            $ids = array_column($actions, $this->primaryKey);

            $result = $this->whereIn('id', $ids)->set(['status' => $status, 'updated_at' => time()])->update();

            if (!empty($this->pendingCache)) {
                foreach ($actions as $action) {
                    unset($this->pendingCache[$action['signature']]);
                }
            }

            if ($result === false) {
                return false;
            }

            if ($log === false) {
                return true;
            }

            $logs = [];

            $message = sanitize_text($message);

            foreach ($actions as $action) {
                if (empty($action[$this->primaryKey]) or !is_numeric($action[$this->primaryKey])) {
                    continue;
                }

                $logs[] = [
                    'action_id'  => $action[$this->primaryKey],
                    'status'     => $status,
                    'message'    => empty($action['message']) ? $message : $action['message'],
                    'created_at' => time()
                ];
            }

            // Log the status change
            if ($logs) {
                $this->db->table($this->logsTable)->insertBatch($logs);
            }

            return true;

        } catch (\Exception $e) {
            throw new ActionException('Failed to mark batch as ' . $statuses[$status] . '. Actions: ' . implode(', ', $ids) . '. Error: ' . $e->getMessage());
        }

    }

}