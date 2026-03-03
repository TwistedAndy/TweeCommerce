<?php

namespace App\Core\Meta;

use App\Core\Entity\EntityModel;
use App\Core\Entity\EntityRegistry;
use App\Core\Entity\EntityInterface;
use \CodeIgniter\Pager\PagerInterface;
use \CodeIgniter\Validation\ValidationInterface;

class MetaModel extends EntityModel
{
    protected string $metaKeyColumn    = 'meta_key';
    protected string $metaValueColumn  = 'meta_value';
    protected string $metaEntityColumn = 'entity_id';

    public function __construct(string $alias, EntityRegistry $registry, ValidationInterface $validator, PagerInterface $pager)
    {
        parent::__construct($alias, $registry, $validator, $pager);

        $config = $registry->getConfig($alias);

        if (!empty($config['key_column'])) {
            $this->metaKeyColumn = $config['key_column'];
        }

        if (!empty($config['value_column'])) {
            $this->metaValueColumn = $config['value_column'];
        }

        if (!empty($config['entity_column'])) {
            $this->metaEntityColumn = $config['entity_column'];
        }
    }

    public function find(int|string $id): ?EntityInterface
    {
        $entity = $this->getFromCache($id);

        if ($entity !== null) {
            return $entity;
        }

        $rows = $this->builder()->where($this->metaEntityColumn, $id)->get()->getResultArray();

        $this->newQuery();

        return $this->hydrateRows($rows, $id);
    }

    public function findMany(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $rows = $this->builder()->whereIn($this->metaEntityColumn, $ids)->get()->getResultArray();

        $this->newQuery();

        $grouped = [];

        foreach ($rows as $row) {
            if (!empty($row[$this->metaEntityColumn])) {
                $key = $row[$this->metaEntityColumn];

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [];
                }

                $grouped[$key][] = $row;
            }
        }

        $entities = [];

        foreach ($ids as $id) {
            $entities[] = $this->hydrateRows($grouped[$id] ?? [], $id);
        }

        return $entities;
    }

    public function findAll(): array
    {
        $rows = $this->builder()->get()->getResultArray();

        $this->newQuery();

        $grouped = [];

        foreach ($rows as $row) {
            if (!empty($row[$this->metaEntityColumn])) {
                $grouped[$row[$this->metaEntityColumn]][] = $row;
            }
        }

        $entities = [];

        foreach ($grouped as $id => $groupRows) {
            $entities[] = $this->hydrateRows($groupRows, $id);
        }

        return $entities;
    }

    public function first(): ?EntityInterface
    {
        // Find the first matching entity ID based on current builder conditions
        $firstRow = $this->builder()->select($this->metaEntityColumn)->limit(1)->get()->getRowArray();

        $this->newQuery();

        if (empty($firstRow) or empty($firstRow[$this->metaEntityColumn])) {
            return null;
        }

        // Delegate to find(), which handles the Identity Map cache and full hydration natively!
        return $this->find($firstRow[$this->metaEntityColumn]);
    }

    public function save(EntityInterface $entity): bool
    {
        $changes = $entity->getChanges();

        if (empty($changes)) {
            return true;
        }

        $original = $entity->getOriginal();
        $oldId    = $original[$this->primaryKey] ?? null;
        $newId    = $entity->getAttribute($this->primaryKey);

        $primaryKeyChanged = array_key_exists($this->primaryKey, $changes);

        // If the primary key was changed to null/empty, wipe all existing metadata
        if ($primaryKeyChanged and empty($newId)) {
            if (!empty($oldId)) {
                $this->db->table($this->table)
                    ->where($this->metaEntityColumn, $oldId)
                    ->delete();

                $this->removeFromCache($oldId);
            }

            $entity->flushChanges();

            return true;
        }

        if (empty($newId)) {
            return false;
        }

        $this->db->transStart();

        // If the parent ID changed, migrate existing records to the new ID first
        if ($primaryKeyChanged and !empty($oldId)) {
            $this->db->table($this->table)
                ->where($this->metaEntityColumn, $oldId)
                ->update([$this->metaEntityColumn => $newId]);

            $this->removeFromCache($oldId);
        }

        $inserts = [];
        $updates = [];
        $deletes = [];

        unset($changes[$this->primaryKey]);

        foreach ($changes as $key => $value) {

            if ($value === null) {
                $deletes[] = $key;
                continue;
            }

            if (is_bool($value)) {
                $storageValue = $value ? '1' : '';
            } else {
                $storageValue = (string) $value;
            }

            $dataRow = [
                $this->metaEntityColumn => $newId,
                $this->metaKeyColumn    => $key,
                $this->metaValueColumn  => $storageValue
            ];

            // If the key was in the original attributes, the row already exists in the DB
            if (array_key_exists($key, $original)) {
                $updates[] = $dataRow;
            } else {
                $inserts[] = $dataRow;
            }
        }

        // Execute DELETES (for keys set to null)
        if (!empty($deletes)) {
            $this->db->table($this->table)
                ->where($this->metaEntityColumn, $newId)
                ->whereIn($this->metaKeyColumn, $deletes)
                ->delete();
        }

        // Execute UPDATES
        if (!empty($updates)) {
            // CI4 updateBatch natively respects the preceding where() clause
            $this->db->table($this->table)
                ->where($this->metaEntityColumn, $newId)
                ->updateBatch($updates, $this->metaKeyColumn);
        }

        // Execute INSERTS (for brand new keys)
        if (!empty($inserts)) {
            $this->db->table($this->table)->insertBatch($inserts);
        }

        $this->db->transComplete();

        $entity->flushChanges();

        // Update the Identity Map to reflect the new ID
        $this->addToCache($newId, $entity);

        return $this->db->transStatus();
    }

    public function insert(EntityInterface $entity): bool
    {
        return $this->save($entity);
    }

    public function update(int|string $id, EntityInterface $entity): bool
    {
        return $this->save($entity);
    }

    public function delete(int|string|array $ids, bool $purge = false): bool
    {
        if (empty($ids)) {
            return true;
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $this->db->table($this->table)
            ->whereIn($this->metaEntityColumn, $ids)
            ->delete();

        $this->removeFromCache($ids);

        return true;
    }

    public function hydrateRows(array $rows, int|string|null $entityId = null): EntityInterface
    {
        $id = $entityId ?? ($rows[0][$this->metaEntityColumn] ?? null);

        if (empty($id)) {
            return new $this->entityClass([$this->primaryKey => null], $this->entityAlias, $this->entityFields);
        }

        $data = [];

        foreach ($rows as $row) {
            if (!empty($row[$this->metaKeyColumn])) {
                $data[$row[$this->metaKeyColumn]] = $row[$this->metaValueColumn];
            }
        }

        $data[$this->primaryKey] = $id;

        if ($cached = $this->getFromCache($id)) {
            $cached->setAttributes($data);
            $cached->flushChanges();
            return $cached;
        }

        $entity = new $this->entityClass($data, $this->entityAlias, $this->entityFields);

        $this->addToCache($id, $entity);

        return $entity;
    }

}