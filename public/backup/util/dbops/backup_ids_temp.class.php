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
 * Helper for the backup_ids_temp temporary storage.
 *
 * All access to the backup ids temporary storage should go through this class
 * so that callers do not need to know how it is stored.
 *
 * @package    core
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_ids_temp {
    /**
     * Get the store for the given conditions.
     *
     * @param array $conditions
     * @return backup_ids_temp_store
     */
    private static function get_store(array $conditions): backup_ids_temp_store {
        if (!isset($conditions['backupid'])) {
            throw new coding_exception('backup_ids_temp requires a backupid in the conditions');
        }
        return backup_ids_temp_store::get((string) $conditions['backupid']);
    }

    /**
     * Get a generic record.
     *
     * @param array $conditions
     * @param string $fields
     * @param int $strictness
     * @return stdClass|false
     */
    public static function get_record(array $conditions, string $fields = '*', int $strictness = IGNORE_MISSING) {
        return self::get_store($conditions)->get_record($conditions, $fields, $strictness);
    }

    /**
     * Get generic records.
     *
     * @param array $conditions
     * @param string $sort
     * @param string $fields
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function get_records(
        array $conditions,
        string $sort = '',
        string $fields = '*',
        int $limitfrom = 0,
        int $limitnum = 0,
    ): array {
        return self::get_store($conditions)->get_records($conditions, $sort, $fields, $limitfrom, $limitnum);
    }

    /**
     * Get a single field.
     *
     * @param string $field
     * @param array $conditions
     * @return mixed
     */
    public static function get_field(string $field, array $conditions) {
        return self::get_store($conditions)->get_field($conditions, $field);
    }

    /**
     * Count records.
     *
     * @param array $conditions
     * @return int
     */
    public static function count_records(array $conditions): int {
        return self::get_store($conditions)->count_records($conditions);
    }

    /**
     * Whether a record exists.
     *
     * @param array $conditions
     * @return bool
     */
    public static function record_exists(array $conditions): bool {
        return self::get_store($conditions)->record_exists($conditions);
    }

    /**
     * Delete records.
     *
     * @param array $conditions
     * @return bool
     */
    public static function delete_records(array $conditions): bool {
        return self::get_store($conditions)->delete_records($conditions);
    }

    /**
     * Add an item to the backup.
     *
     * @param string $backupid
     * @param string $itemname
     * @param int $itemid
     */
    public static function add_item(string $backupid, string $itemname, int $itemid): void {
        // Scales are stored with a negative id.
        if ($itemname === 'scale') {
            $itemid = -$itemid;
        }
        if ($itemid <= 0) {
            return;
        }
        $conditions = [
            'backupid' => $backupid,
            'itemname' => $itemname,
            'itemid' => $itemid,
        ];
        $store = backup_ids_temp_store::get($backupid);
        if (!$store->record_exists($conditions)) {
            $store->insert_record($conditions);
        }
    }

    /**
     * Whether an item exists.
     *
     * @param string $backupid
     * @param string $itemname
     * @param int $itemid
     * @return bool
     */
    public static function item_exists(string $backupid, string $itemname, int $itemid): bool {
        return self::record_exists([
            'backupid' => $backupid,
            'itemname' => $itemname,
            'itemid' => $itemid,
        ]);
    }

    /**
     * Get items for an item name.
     *
     * @param string $backupid
     * @param string $itemname
     * @param string $fields
     * @param string $sort
     * @return array
     */
    public static function get_items(
        string $backupid,
        string $itemname,
        string $fields = '*',
        string $sort = '',
    ): array {
        return self::get_records([
            'backupid' => $backupid,
            'itemname' => $itemname,
        ], $sort, $fields);
    }

    /**
     * Get the item ids for an item name.
     *
     * @param string $backupid
     * @param string $itemname
     * @return int[]
     */
    public static function get_item_ids(string $backupid, string $itemname): array {
        $records = self::get_items($backupid, $itemname, 'itemid');
        return array_map('intval', array_keys($records));
    }

    /**
     * Move items from "item" to "itemfinal".
     *
     * @param string $backupid
     * @param string $itemname
     * @param \core\progress\base $progress
     */
    public static function move_to_final(string $backupid, string $itemname, \core\progress\base $progress): void {
        $store = backup_ids_temp_store::get($backupid);
        $progress->start_progress('move_items_to_final');
        $items = $store->get_records(['backupid' => $backupid, 'itemname' => $itemname]);
        $progress->progress();
        foreach ($items as $item) {
            $finalconditions = [
                'backupid' => $backupid,
                'itemname' => $itemname . 'final',
                'itemid' => $item->itemid,
            ];
            if (!$store->record_exists($finalconditions)) {
                $store->set_field('itemname', $itemname . 'final', ['id' => $item->id]);
            }
            $progress->progress();
        }
        $store->delete_records(['backupid' => $backupid, 'itemname' => $itemname]);
        $progress->end_progress();
    }

    /**
     * Delete items for an item name.
     *
     * @param string $backupid
     * @param string $itemname
     */
    public static function delete_items(string $backupid, string $itemname): void {
        self::delete_records([
            'backupid' => $backupid,
            'itemname' => $itemname,
        ]);
    }

    /**
     * Copy items for an item name to another temporary table.
     *
     * @param string $backupid
     * @param string $itemname
     * @param string $tablename
     */
    public static function copy_items_to_table(string $backupid, string $itemname, string $tablename): void {
        global $DB;

        $ids = self::get_item_ids($backupid, $itemname);
        if (!$ids) {
            return;
        }
        $records = [];
        foreach ($ids as $id) {
            $records[] = [
                'backupid' => $backupid,
                'itemid' => $id,
            ];
        }
        $DB->insert_records($tablename, $records);
    }

    /**
     * Create the temporary storage.
     *
     * @param string $backupid
     */
    public static function create_temp_table(string $backupid): void {
        backup_ids_temp_store::get($backupid)->initialize();
    }

    /**
     * Drop the temporary storage.
     *
     * @param string $backupid
     */
    public static function drop_temp_table(string $backupid): void {
        backup_ids_temp_store::get($backupid)->reset();
    }
}
