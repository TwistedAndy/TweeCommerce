<?php

namespace App\Models;

use CodeIgniter\Model;

class ActionModel extends Model
{
    protected $table = 'actions';
    protected $primaryKey = 'id';
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

    protected $useTimestamps = true;
    protected $dateFormat = 'int';

    // Status Constants
    public const STATUS_PENDING = 1;
    public const STATUS_RUNNING = 2;
    public const STATUS_COMPLETED = 3;
    public const STATUS_FAILED = 4;

    /**
     * ATOMIC CLAIM: High Performance & Concurrency Safe
     */
    public function claimBatch(int $limit = 20): array
    {
        $now = time();
        $this->db->transStart();

        $sql = "SELECT id, action, callback, payload, priority, recurring, scheduled_at 
                FROM {$this->table} 
                WHERE status = ? 
                AND scheduled_at <= ? 
                ORDER BY priority DESC, scheduled_at ASC 
                LIMIT ?";

        // Check if the current database engine supports locks
        $lockClause = $this->getLockClause();

        if ($lockClause) {
            $sql .= ' ' . $lockClause;
        }

        $query = $this->db->query($sql, [self::STATUS_PENDING, $now, $limit]);
        $jobs = $query->getResultArray();

        if (empty($jobs)) {
            $this->db->transComplete();
            return [];
        }

        // Update statuses
        $ids = array_column($jobs, 'id');
        $this->whereIn('id', $ids)
             ->set(['status' => self::STATUS_RUNNING, 'updated_at' => $now])
             ->update();

        $this->db->transComplete();

        return $jobs;
    }

    /**
     * COMPLETION: Move from Running -> Completed + Log
     */
    public function completeBatch(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->db->transStart();

        // 1. Update Status
        $this->whereIn('id', $ids)
             ->set(['status' => self::STATUS_COMPLETED, 'updated_at' => time()])
             ->update();

        // [REMOVED] delete from action_claims (Table does not exist)

        // 2. Logs
        $logs = [];
        foreach ($ids as $id) {
            $logs[] = [
                'action_id'  => $id,
                'status'     => self::STATUS_COMPLETED,
                'message'    => 'Action completed successfully',
                'created_at' => time()
            ];
        }
        $this->db->table('action_logs')->insertBatch($logs);

        $this->db->transComplete();
    }

    /**
     * FAILURE: Move from Running -> Failed + Log Error
     */
    public function failBatch(array $failures): void
    {
        if (empty($failures)) {
            return;
        }

        $ids = array_keys($failures);
        $this->db->transStart();

        // 1. Update Status
        $this->whereIn('id', $ids)
             ->set(['status' => self::STATUS_FAILED, 'updated_at' => time()])
             ->update();

        // [REMOVED] delete from action_claims (Table does not exist)

        // 2. Logs
        $logs = [];
        foreach ($failures as $id => $data) {
            $logs[] = [
                'action_id'  => $id,
                'status'     => self::STATUS_FAILED,
                'message'    => json_encode($data['error_log']),
                'created_at' => time()
            ];
        }
        $this->db->table('action_logs')->insertBatch($logs);

        $this->db->transComplete();
    }

    /**
     * Reset specific jobs from Running back to Pending.
     * Used when a worker runs out of time and needs to yield tasks back to the queue.
     */
    public function releaseBatch(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $this->db->transStart();

        // Reset to Pending
        $this->whereIn('id', $ids)
             ->set(['status' => self::STATUS_PENDING, 'updated_at' => time()])
             ->update();

        $this->db->transComplete();
    }

    /**
     * DEDUPLICATION: Helper remains mostly the same
     */
    public function getExistingSignatures(array $signatures, int $seconds = 300): array
    {
        if (empty($signatures)) {
            return [];
        }

        return $this->select('signature')
                    ->whereIn('signature', $signatures)
                    ->where('status <', self::STATUS_COMPLETED)
                    ->where('created_at >', time() - $seconds)
                    ->findAll();
    }

    /**
     * RECOVERY: Reset stuck jobs (Self-Healing)
     * If a worker crashes hard (segfault/OOM), the transaction rolls back automatically
     * and the job stays PENDING (Safe!).
     * However, if the worker finishes the transaction but dies during execution PHP-side,
     * the job stays RUNNING forever. This fixes that.
     */
    public function retryStaleJobs(int $timeoutSeconds = 3600): int
    {
        $limit = time() - $timeoutSeconds;
        $this->where('status', self::STATUS_RUNNING)
             ->where('updated_at <', $limit)
             ->set(['status' => self::STATUS_PENDING, 'updated_at' => time()])
             ->update();
        return $this->db->affectedRows();
    }

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
}