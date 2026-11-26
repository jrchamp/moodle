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
 * Request-scoped store for the backup_files_temp data used during restore.
 *
 * The store uses the Cache API with the request cache store, avoiding
 * temporary-table writes and reads during restore.
 *
 * @package    core
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_files_temp_store {
    /** @var array Store instances keyed by restore id. */
    private static $instances = [];

    /** @var string The restore id this store belongs to. */
    private $restoreid;

    /** @var \cache The request cache. */
    private $cache;

    /** @var int The next record id to allocate. */
    private $nextid = 1;

    /** @var array id => [contextid, component, filearea, itemid]. */
    private $byid = [];

    /** @var array */
    private $byarea = [];

    /**
     * Constructor.
     *
     * @param string $restoreid
     */
    private function __construct(string $restoreid) {
        $this->restoreid = $restoreid;
        $this->cache = \cache::make_from_params(
            \cache_store::MODE_REQUEST,
            'core_backup',
            'backup_files_temp',
            [],
            [
                'simplekeys' => true,
                'simpledata' => true,
            ]
        );

        $nextid = $this->cache->get('fc_' . $this->restoreid);
        if ($nextid !== false) {
            $this->nextid = (int) $nextid;
        }
    }

    /**
     * Get the store for a restore id.
     *
     * @param string $restoreid
     * @return backup_files_temp_store
     */
    public static function get(string $restoreid): backup_files_temp_store {
        if (!isset(self::$instances[$restoreid])) {
            self::$instances[$restoreid] = new self($restoreid);
        }
        return self::$instances[$restoreid];
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
     * Reset this store.
     */
    public function reset(): void {
        $this->cache->purge();
        $this->nextid = 1;
        $this->byid = [];
        $this->byarea = [];
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
        $this->cache->set('fc_' . $this->restoreid, $this->nextid);

        $record += [
            'newcontextid' => null,
            'newitemid' => null,
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
            throw new coding_exception('backup_files_temp_store::update_record requires an id');
        }

        $id = (int) $record['id'];
        if (!isset($this->byid[$id])) {
            throw new dml_missing_record_exception('backup_files_temp');
        }

        $old = $this->cache->get($this->rowkey($id));
        if ($old === false) {
            throw new dml_missing_record_exception('backup_files_temp');
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
                throw new dml_missing_record_exception('backup_files_temp');
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
     * @param int $id
     * @return string
     */
    private function rowkey(int $id): string {
        return 'f_' . $this->restoreid . '_' . $id;
    }

    /**
     * Store a row and update the indexes.
     *
     * @param array $row
     */
    private function store_row(array $row): void {
        $id = (int) $row['id'];
        $contextid = (int) $row['contextid'];
        $component = (string) $row['component'];
        $filearea = (string) $row['filearea'];
        $itemid = (int) $row['itemid'];

        $this->cache->set($this->rowkey($id), $row);

        $this->byid[$id] = [$contextid, $component, $filearea, $itemid];
        $this->byarea[$contextid][$component][$filearea][$itemid][$id] = true;
    }

    /**
     * Remove a row and its indexes.
     *
     * @param array $row
     */
    private function remove_row(array $row): void {
        $id = (int) $row['id'];
        $contextid = (int) $row['contextid'];
        $component = (string) $row['component'];
        $filearea = (string) $row['filearea'];
        $itemid = (int) $row['itemid'];

        $this->cache->delete($this->rowkey($id));

        unset($this->byarea[$contextid][$component][$filearea][$itemid][$id]);
        if (empty($this->byarea[$contextid][$component][$filearea][$itemid])) {
            unset($this->byarea[$contextid][$component][$filearea][$itemid]);
        }
        if (empty($this->byarea[$contextid][$component][$filearea])) {
            unset($this->byarea[$contextid][$component][$filearea]);
        }
        if (empty($this->byarea[$contextid][$component])) {
            unset($this->byarea[$contextid][$component]);
        }
        if (empty($this->byarea[$contextid])) {
            unset($this->byarea[$contextid]);
        }

        unset($this->byid[$id]);
    }

    /**
     * Get candidate record ids for the given conditions.
     *
     * @param array $conditions
     * @return int[]
     */
    private function get_candidate_ids(array $conditions): array {
        if (isset($conditions['id'])) {
            $id = (int) $conditions['id'];
            return isset($this->byid[$id]) ? [$id] : [];
        }

        if (isset($conditions['contextid']) && isset($conditions['component']) && isset($conditions['filearea'])) {
            $contextid = (int) $conditions['contextid'];
            $component = (string) $conditions['component'];
            $filearea = (string) $conditions['filearea'];
            $area = $this->byarea[$contextid][$component][$filearea] ?? [];

            if (isset($conditions['itemid'])) {
                $itemid = (int) $conditions['itemid'];
                return array_keys($area[$itemid] ?? []);
            }

            $ids = [];
            foreach ($area as $itemids) {
                foreach ($itemids as $id => $unused) {
                    $ids[] = (int) $id;
                }
            }
            return $ids;
        }

        return array_keys($this->byid);
    }

    /**
     * Fetch records matching the conditions.
     *
     * @param array $conditions
     * @return array Records keyed by id.
     */
    private function fetch_records(array $conditions): array {
        if (isset($conditions['backupid']) && (string) $conditions['backupid'] !== $this->restoreid) {
            return [];
        }

        $ids = $this->get_candidate_ids($conditions);
        if (!$ids) {
            return [];
        }

        $keys = [];
        foreach ($ids as $id) {
            $keys[] = $this->rowkey($id);
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
