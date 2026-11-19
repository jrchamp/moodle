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

namespace core_communication\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem for core_communication.
 *
 * @package    core_communication
 * @copyright  2023 Huong Nguyen <huongnv13@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\subsystem\provider {
    /**
     * Get the privacy metadata for core_communication.
     *
     * @param collection $collection The collection to add the metadata to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('communication_user', [
            'commid' => 'privacy:metadata:communication_user:commid',
            'userid' => 'privacy:metadata:communication_user:userid',
            'synced' => 'privacy:metadata:communication_user:synced',
            'deleted' => 'privacy:metadata:communication_user:deleted',
        ], 'privacy:metadata:communication_user');

        return $collection;
    }

    /**
     * Get the contexts which contain communication user mappings for the specified user.
     *
     * @param int $userid The user to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT DISTINCT c.contextid
                  FROM {communication_user} cu
                  JOIN {communication} c ON c.id = cu.commid
                 WHERE cu.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        return $contextlist;
    }

    /**
     * Export the communication user mappings for the specified user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $sql = "SELECT cu.id, cu.synced, cu.deleted, c.provider, c.roomname
                      FROM {communication_user} cu
                      JOIN {communication} c ON c.id = cu.commid
                     WHERE cu.userid = :userid
                       AND c.contextid = :contextid";
            $records = $DB->get_records_sql($sql, [
                'userid' => $userid,
                'contextid' => $context->id,
            ]);

            if (!$records) {
                continue;
            }

            $memberships = [];
            foreach ($records as $record) {
                $memberships[] = (object) [
                    'provider' => $record->provider,
                    'roomname' => format_string($record->roomname ?? '', true, ['context' => $context]),
                    'synced' => transform::yesno($record->synced),
                    'deleted' => transform::yesno($record->deleted),
                ];
            }

            writer::with_context($context)->export_data([], (object) [
                'memberships' => $memberships,
            ]);
        }
    }

    /**
     * Delete all communication user mappings for all users in the specified context.
     *
     * @param \context $context The context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $commids = $DB->get_fieldset_select(
            'communication',
            'id',
            'contextid = ?',
            [$context->id]
        );

        if (!$commids) {
            return;
        }

        [$commidsql, $params] = $DB->get_in_or_equal($commids, SQL_PARAMS_NAMED, 'comm');
        $DB->delete_records_select('communication_user', "commid {$commidsql}", $params);
    }

    /**
     * Delete the communication user mappings for the specified user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $contextids = $contextlist->get_contextids();
        if (!$contextids) {
            return;
        }

        [$contextsql, $contextparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $commids = $DB->get_fieldset_select(
            'communication',
            'id',
            "contextid {$contextsql}",
            $contextparams
        );

        if (!$commids) {
            return;
        }

        [$commidsql, $params] = $DB->get_in_or_equal($commids, SQL_PARAMS_NAMED, 'comm');
        $params['userid'] = $contextlist->get_user()->id;
        $DB->delete_records_select(
            'communication_user',
            "commid {$commidsql} AND userid = :userid",
            $params
        );
    }

    /**
     * Get the users with communication user mappings in the specified context.
     *
     * @param userlist $userlist The userlist to add the users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        $sql = "SELECT DISTINCT cu.userid
                  FROM {communication_user} cu
                  JOIN {communication} c ON c.id = cu.commid
                 WHERE c.contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Delete the communication user mappings for the approved users in the specified context.
     *
     * @param approved_userlist $userlist The approved context and users to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        $commids = $DB->get_fieldset_select(
            'communication',
            'id',
            'contextid = ?',
            [$userlist->get_context()->id]
        );

        if (!$commids) {
            return;
        }

        [$commidsql, $params] = $DB->get_in_or_equal($commids, SQL_PARAMS_NAMED, 'comm');
        [$useridsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'user');
        $params += $userparams;
        $DB->delete_records_select(
            'communication_user',
            "commid {$commidsql} AND userid {$useridsql}",
            $params
        );
    }
}
