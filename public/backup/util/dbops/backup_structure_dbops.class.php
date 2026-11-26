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
 * @package    moodlecore
 * @subpackage backup-dbops
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Non instantiable helper class providing DB support to the backup_structure stuff
 *
 * This class contains various static methods available for all the DB operations
 * performed by the backup_structure stuff (mainly @backup_nested_element class)
 *
 * TODO: Finish phpdocs
 */
abstract class backup_structure_dbops extends backup_dbops {

    public static function get_iterator($element, $params, $processor) {
        global $DB;

        // Check we are going to get_iterator for one backup_nested_element
        if (! $element instanceof backup_nested_element) {
            throw new base_element_struct_exception('backup_nested_element_expected');
        }

        if ($element->get_source_callback() !== null) {
            $values = self::convert_params_to_values($element->get_source_params(), $processor);
            $result = call_user_func($element->get_source_callback(), $values, $element, $processor);
            if (is_array($result)) {
                return new backup_array_iterator($result);
            }
            return $result;
        }

        // If var_array, table and sql are null, and element has no final elements it is one nested element without source
        // Just return one 1 element iterator without information
        if ($element->get_source_array() === null && $element->get_source_table() === null &&
            $element->get_source_sql() === null && count($element->get_final_elements()) == 0) {
            return new backup_array_iterator(array(0 => null));

        } else if ($element->get_source_array() !== null) { // It's one array, return array_iterator
            return new backup_array_iterator($element->get_source_array());

        } else if ($element->get_source_table() !== null) { // It's one table, return recordset iterator
            $values = self::convert_params_to_values($params, $processor);
            if (class_exists('backup_question_bank_cache', false) && backup_question_bank_cache::is_active()) {
                $cached = backup_question_bank_cache::get_table_rows(
                    $element->get_source_table(),
                    $values,
                    $element->get_source_table_sortby() ?? '',
                );
                if ($cached !== null) {
                    return new backup_array_iterator($cached);
                }
            }
            return $DB->get_recordset($element->get_source_table(), $values, $element->get_source_table_sortby());

        } else if ($element->get_source_sql() !== null) { // It's one sql, return recordset iterator
            return $DB->get_recordset_sql($element->get_source_sql(), self::convert_params_to_values($params, $processor));

        } else { // No sources, supress completely, using null iterator
            return new backup_null_iterator();
        }
    }

    public static function convert_params_to_values($params, $processor) {
        $newparams = array();
        foreach ($params as $key => $param) {
            $newvalue = null;
            // If we have a base element, get its current value, exception if not set
            if ($param instanceof base_atom) {
                if ($param->is_set()) {
                    $newvalue = $param->get_value();
                } else {
                    throw new base_element_struct_exception('valueofparamelementnotset', $param->get_name());
                }

            } else if (is_int($param) && $param < 0) { // Possibly one processor variable, let's process it
                // See @backup class for all the VAR_XXX variables available.
                // Note1: backup::VAR_PARENTID is handled by nested elements themselves
                // Note2: trying to use one non-existing var will throw exception
                $newvalue = $processor->get_var($param);

            // Else we have one raw param value, use it
            } else {
                $newvalue = $param;
            }

            $newparams[$key] = $newvalue;
        }
        return $newparams;
    }

    public static function insert_backup_ids_record($backupid, $itemname, $itemid) {
        backup_controller_dbops::add_item($backupid, $itemname, (int) $itemid);
    }

    /**
     * Adds backup id database record for all files in the given file area.
     *
     * @param string $backupid Backup ID
     * @param int $contextid Context id
     * @param string $component Component
     * @param string $filearea File area
     * @param int $itemid Item id
     * @param \core\progress\base $progress
     */
    public static function annotate_files($backupid, $contextid, $component, $filearea, $itemid,
            ?\core\progress\base $progress = null) {
        global $DB;
        $sql = 'SELECT id
                  FROM {files}
                 WHERE contextid = ?
                   AND component = ?';
        $params = array($contextid, $component);

        if (!is_null($filearea)) { // Add filearea to query and params if necessary
            $sql .= ' AND filearea = ?';
            $params[] = $filearea;
        }

        if (!is_null($itemid)) { // Add itemid to query and params if necessary
            $sql .= ' AND itemid = ?';
            $params[] = $itemid;
        }
        if ($progress) {
            $progress->start_progress('');
        }
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $record) {
            if ($progress) {
                $progress->progress();
            }
            self::insert_backup_ids_record($backupid, 'file', $record->id);
        }
        if ($progress) {
            $progress->end_progress();
        }
        $rs->close();
    }

    /**
     * Moves all the existing 'item' records to their final 'itemfinal' ones
     * for a given backup.
     *
     * @param string $backupid Backup ID
     * @param string $itemname Item name
     * @param \core\progress\base $progress Progress tracker
     */
    public static function move_annotations_to_final($backupid, $itemname, \core\progress\base $progress) {
        backup_controller_dbops::move_items_to_final($backupid, $itemname, $progress);
    }

    /**
     * Returns true/false if there are records for a given item name.
     */
    public static function annotations_exist($backupid, $itemname) {
        return backup_controller_dbops::has_items($backupid, $itemname);
    }
}
