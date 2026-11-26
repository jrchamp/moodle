<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Request-scoped store for the backup_ids_temp data used during backup and restore.
 *
 * The store uses the Cache API with the request cache store, avoiding
 * thousands of temporary-table writes and reads during backup and restore.
 *
 * @package    core
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_ids_temp_store {
    /** @var array Store instances keyed by backup or restore id. */
    private static $instances = [];

    /** @var string The backup or restore id this store belongs to. */
    private $id;

    /** @var \cache The request cache. */
    private $cache;

    /** @var int The next record id to allocate. */
    private $nextid = 1;

    /** @var bool Whether this store has been initialised. */
    private $initialized = false;

    /** @var array itemname => itemid => true. */
    private $byitemname = [];

    /** @var array itemname => parent key => itemid => true. */
    private $byparent = [];

    /** @var array itemname => new item id key => itemid => true. */
    private $bynew = [];

    /** @var array id => [itemname, itemid]. */
    private $byid = [];

    /**
     * Constructor.
     *
     * @param string $id
     */
    private function __construct(string $id) {
        $this->id = $id;
        $this->cache = \cache::make_from_params(
            \cache_store::MODE_REQUEST,
            'core_backup',
            'backup_ids_temp',
            [],
            [
                'simplekeys' => true,
                'simpledata' => true,
            ]
        );

        $nextid = $this->cache->get('c_' . $this->id);
        if ($nextid !== false) {
            $this->nextid = (int) $nextid;
        }
    }

    /**
     * Get the store for a restore id.
     *
     * @param string $id
     * @return backup_ids_temp_store
     */
    public static function get(string $id): backup_ids_temp_store {
        if (!isset(self::$instances[$id])) {
            self::$instances[$id] = new self($id);
        }
        return self::$instances[$id];
    }

    /**
     * Reset all stores and free any cached data.
     */
    public static function reset_all(): void {
        foreach (self::$instances as $store) {
            $store->reset();
        }
        self::$instances = [];
    }

    /**
     * Initialise this store, clearing any existing data.
     */
    public function initialize(): void {
        $this->reset();
        $this->initialized = true;
    }

    /**
     * Whether this store has been initialised.
     *
     * @return bool
     */
    public function is_initialized(): bool {
        return $this->initialized;
    }

    /**
     * Reset this store.
     */
    public function reset(): void {
        foreach ($this->byid as $id => [$itemname, $itemid]) {
            $this->cache->delete($this->rowkey($itemname, $itemid));
        }
        $this->cache->delete('c_' . $this->id);
        $this->nextid = 1;
        $this->byitemname = [];
        $this->byparent = [];
        $this->bynew = [];
        $this->byid = [];
        $this->initialized = false;
    }

    /**
     * Insert a record.
     *
     * @param array|stdClass $record
     * @return int The record id.
     */
    public function insert_record($record): int {
        $record = (array) $record;

        if (empty($record['id'])) {
            $record['id'] = $this->nextid++;
        } else {
            $record['id'] = (int) $record['id'];
            $this->nextid = max($this->nextid, $record['id'] + 1);
        }
        $this->cache->set('c_' . $this->id, $this->nextid);

        $record += [
            'newitemid' => 0,
            'parentitemid' => null,
            'info' => null,
        ];

        $this->store_row($record);
        return (int) $record['id'];
    }

    /**
     * Update a record.
     *
     * @param array|stdClass $record
     * @return bool
     */
    public function update_record($record): bool {
        $record = (array) $record;

        if (!isset($record['id'])) {
            throw new coding_exception('backup_ids_temp_store::update_record requires an id');
        }

        $id = (int) $record['id'];
        if (!isset($this->byid[$id])) {
            throw new dml_missing_record_exception('backup_ids_temp');
        }

        [$itemname, $itemid] = $this->byid[$id];
        $old = $this->cache->get($this->rowkey($itemname, $itemid));
        if ($old === false) {
            throw new dml_missing_record_exception('backup_ids_temp');
        }

        $new = array_merge($old, $record);
        $this->remove_row($old);
        $this->store_row($new);
        return true;
    }

    /**
     * Get a single record.
     *
     * @param array $conditions
     * @param string $fields
     * @param int $strictness
     * @return stdClass|false
     */
    public function get_record(array $conditions, string $fields = '*', int $strictness = IGNORE_MISSING) {
        $records = $this->get_records($conditions, '', $fields, 0, 1);
        if (!$records) {
            if ($strictness === MUST_EXIST) {
                throw new dml_missing_record_exception('backup_ids_temp');
            }
            return false;
        }
        return reset($records);
    }

    /**
     * Get multiple records.
     *
     * @param array $conditions
     * @param string $sort
     * @param string $fields
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public function get_records(
        array $conditions,
        string $sort = '',
        string $fields = '*',
        int $limitfrom = 0,
        int $limitnum = 0
    ): array {
        $records = $this->fetch_records($conditions);
        if ($sort !== '') {
            $this->sort_records($records, $sort);
        }
        if ($limitfrom || $limitnum) {
            $records = array_slice($records, $limitfrom, $limitnum ?: null, true);
        }

        $keyfield = $fields === '*' ? 'id' : trim(explode(',', $fields)[0]);
        $result = [];
        foreach ($records as $record) {
            $formatted = $this->format_record($record, $fields);
            $result[$formatted->$keyfield] = $formatted;
        }
        return $result;
    }

    /**
     * Get a single field.
     *
     * @param array $conditions
     * @param string $field
     * @return mixed
     */
    public function get_field(array $conditions, string $field) {
        $record = $this->get_record($conditions);
        return $record === false ? false : $record->$field;
    }

    /**
     * Count records.
     *
     * @param array $conditions
     * @return int
     */
    public function count_records(array $conditions): int {
        return count($this->fetch_records($conditions));
    }

    /**
     * Whether a record exists.
     *
     * @param array $conditions
     * @return bool
     */
    public function record_exists(array $conditions): bool {
        return $this->count_records($conditions) > 0;
    }

    /**
     * Delete records.
     *
     * @param array $conditions
     * @return bool
     */
    public function delete_records(array $conditions): bool {
        foreach ($this->fetch_records($conditions) as $record) {
            $this->remove_row((array) $record);
        }
        return true;
    }

    /**
     * Set a field for matching records.
     *
     * @param string $field
     * @param mixed $value
     * @param array $conditions
     * @return bool
     */
    public function set_field(string $field, $value, array $conditions): bool {
        foreach ($this->fetch_records($conditions) as $record) {
            $record = (array) $record;
            $record[$field] = $value;
            $this->update_record($record);
        }
        return true;
    }

    /**
     * Build a cache key for a row.
     *
     * @param string $itemname
     * @param int $itemid
     * @return string
     */
    private function rowkey(string $itemname, int $itemid): string {
        return 'r_' . $this->id . '_' . $itemname . '_' . $itemid;
    }

    /**
     * Normalise a value used as an index key.
     *
     * @param mixed $value
     * @return string
     */
    private function indexvalue($value): string {
        return $value === null ? 'n' : (string) $value;
    }

    /**
     * Store a row and update the indexes.
     *
     * @param array $row
     */
    private function store_row(array $row): void {
        $itemname = (string) $row['itemname'];
        $itemid = (int) $row['itemid'];
        $id = (int) $row['id'];

        $this->cache->set($this->rowkey($itemname, $itemid), $row);

        $this->byitemname[$itemname][$itemid] = true;

        $parentkey = $this->indexvalue($row['parentitemid'] ?? null);
        $this->byparent[$itemname][$parentkey][$itemid] = true;

        $newkey = $this->indexvalue($row['newitemid'] ?? 0);
        $this->bynew[$itemname][$newkey][$itemid] = true;

        $this->byid[$id] = [$itemname, $itemid];
    }

    /**
     * Remove a row and its indexes.
     *
     * @param array $row
     */
    private function remove_row(array $row): void {
        $itemname = (string) $row['itemname'];
        $itemid = (int) $row['itemid'];
        $id = (int) $row['id'];

        $this->cache->delete($this->rowkey($itemname, $itemid));

        unset($this->byitemname[$itemname][$itemid]);
        if (empty($this->byitemname[$itemname])) {
            unset($this->byitemname[$itemname]);
        }

        $parentkey = $this->indexvalue($row['parentitemid'] ?? null);
        unset($this->byparent[$itemname][$parentkey][$itemid]);
        if (empty($this->byparent[$itemname][$parentkey])) {
            unset($this->byparent[$itemname][$parentkey]);
        }
        if (empty($this->byparent[$itemname])) {
            unset($this->byparent[$itemname]);
        }

        $newkey = $this->indexvalue($row['newitemid'] ?? 0);
        unset($this->bynew[$itemname][$newkey][$itemid]);
        if (empty($this->bynew[$itemname][$newkey])) {
            unset($this->bynew[$itemname][$newkey]);
        }
        if (empty($this->bynew[$itemname])) {
            unset($this->bynew[$itemname]);
        }

        unset($this->byid[$id]);
    }

    /**
     * Get candidate [itemname, itemid] pairs for the given conditions.
     *
     * @param array $conditions
     * @return array
     */
    private function get_candidate_pairs(array $conditions): array {
        if (isset($conditions['id'])) {
            $id = (int) $conditions['id'];
            return isset($this->byid[$id]) ? [$this->byid[$id]] : [];
        }

        if (!isset($conditions['itemname'])) {
            return array_values($this->byid);
        }

        $itemname = (string) $conditions['itemname'];

        if (isset($conditions['itemid'])) {
            $itemid = (int) $conditions['itemid'];
            return isset($this->byitemname[$itemname][$itemid]) ? [[$itemname, $itemid]] : [];
        }

        if (array_key_exists('parentitemid', $conditions)) {
            $key = $this->indexvalue($conditions['parentitemid']);
            $itemids = array_keys($this->byparent[$itemname][$key] ?? []);
        } else if (array_key_exists('newitemid', $conditions)) {
            $key = $this->indexvalue($conditions['newitemid']);
            $itemids = array_keys($this->bynew[$itemname][$key] ?? []);
        } else {
            $itemids = array_keys($this->byitemname[$itemname] ?? []);
        }

        $pairs = [];
        foreach ($itemids as $itemid) {
            $pairs[] = [$itemname, (int) $itemid];
        }
        return $pairs;
    }

    /**
     * Fetch records matching the conditions.
     *
     * @param array $conditions
     * @return array Records keyed by id.
     */
    private function fetch_records(array $conditions): array {
        if (isset($conditions['backupid']) && (string) $conditions['backupid'] !== $this->id) {
            return [];
        }

        $pairs = $this->get_candidate_pairs($conditions);
        if (!$pairs) {
            return [];
        }

        $keys = [];
        foreach ($pairs as [$itemname, $itemid]) {
            $keys[] = $this->rowkey($itemname, $itemid);
        }

        $rows = $this->cache->get_many($keys);
        $records = [];
        foreach ($rows as $row) {
            if ($row === false || !$this->matches($row, $conditions)) {
                continue;
            }
            $records[(int) $row['id']] = (object) $row;
        }
        return $records;
    }

    /**
     * Check whether a row matches the conditions.
     *
     * @param array $row
     * @param array $conditions
     * @return bool
     */
    private function matches(array $row, array $conditions): bool {
        foreach ($conditions as $field => $value) {
            if (!array_key_exists($field, $row)) {
                return false;
            }
            if ($value === null) {
                if ($row[$field] !== null) {
                    return false;
                }
            } else if ((string) $row[$field] !== (string) $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Sort records in place.
     *
     * @param array $records
     * @param string $sort
     */
    private function sort_records(array &$records, string $sort): void {
        $parts = array_map('trim', explode(',', $sort));
        uasort($records, function ($a, $b) use ($parts) {
            foreach ($parts as $part) {
                $bits = preg_split('/\s+/', $part);
                $field = $bits[0];
                $direction = strtoupper($bits[1] ?? 'ASC');
                $av = $a->$field ?? null;
                $bv = $b->$field ?? null;
                if ($av == $bv) {
                    continue;
                }
                $cmp = $av <=> $bv;
                return $direction === 'DESC' ? -$cmp : $cmp;
            }
            return 0;
        });
    }

    /**
     * Filter a record to the requested fields.
     *
     * @param stdClass $record
     * @param string $fields
     * @return stdClass
     */
    private function format_record(stdClass $record, string $fields): stdClass {
        if ($fields === '*') {
            return $record;
        }

        $result = new stdClass();
        foreach (array_map('trim', explode(',', $fields)) as $field) {
            if (property_exists($record, $field)) {
                $result->$field = $record->$field;
            }
        }
        return $result;
    }
}
