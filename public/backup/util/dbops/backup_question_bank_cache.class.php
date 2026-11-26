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
 * Request-scoped cache of question bank data used during backup.
 *
 * The backup structure processes question data parent-by-parent, which
 * results in several queries per question. This cache preloads all the
 * related rows for the questions in the backup in batches, and the backup
 * structure then reads from memory.
 *
 * @package    core
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_question_bank_cache {
    /** @var string|null The backup id this cache belongs to. */
    private static $backupid = null;

    /** @var int[] Entry ids included in the backup. */
    private static $entryids = [];

    /** @var array Category id => entry records. */
    private static $entriesbycategory = [];

    /** @var stdClass[]|null Question categories included in the backup. */
    private static $categories = null;

    /** @var int[] Question ids included in the backup. */
    private static $questionids = [];

    /** @var array Version id => question id. */
    private static $versiontoquestionid = [];

    /** @var array Version id => question record. */
    private static $versiontoquestion = [];

    /** @var array Entry id => version records. */
    private static $versionsbyentry = [];

    /** @var array Table => parent value => rows. */
    private static $tablerows = [];

    /** @var array Tables that have been loaded. */
    private static $loadedtables = [];

    /** @var array|null Question id => hint records. */
    private static $hintsbyquestion = null;

    /** @var array|null Question id => tag records. */
    private static $tagsbyquestion = null;

    /** @var array|null Question id => comment records. */
    private static $commentsbyquestion = null;

    /** @var array|null Question id => custom field records. */
    private static $customfieldsbyquestion = null;

    /**
     * Start caching question bank data for a backup.
     *
     * @param string $backupid
     */
    public static function start(string $backupid): void {
        global $DB;

        self::stop();
        self::$backupid = $backupid;

        $entryids = self::load_entry_ids($backupid);
        if (!$entryids) {
            return;
        }
        self::$entryids = $entryids;

        $entries = $DB->get_records_list('question_bank_entries', 'id', $entryids, '', '*');
        foreach ($entries as $entry) {
            self::$entriesbycategory[(int) $entry->questioncategoryid][] = $entry;
        }

        $versions = self::load_versions($entryids);
        if (!$versions) {
            return;
        }
        self::$versionsbyentry = $versions;

        $questionids = array_values(self::$versiontoquestionid);
        if (!$questionids) {
            return;
        }
        self::$questionids = $questionids;

        $questions = self::load_questions($questionids);
        if (!$questions) {
            return;
        }
        foreach (self::$versiontoquestionid as $versionid => $questionid) {
            if (isset($questions[$questionid])) {
                self::$versiontoquestion[$versionid] = $questions[$questionid];
            }
        }
    }

    /**
     * Stop caching and free all data.
     */
    public static function stop(): void {
        self::$backupid = null;
        self::$entryids = [];
        self::$entriesbycategory = [];
        self::$categories = null;
        self::$questionids = [];
        self::$versiontoquestionid = [];
        self::$versiontoquestion = [];
        self::$versionsbyentry = [];
        self::$tablerows = [];
        self::$loadedtables = [];
        self::$hintsbyquestion = null;
        self::$tagsbyquestion = null;
        self::$commentsbyquestion = null;
        self::$customfieldsbyquestion = null;
    }

    /**
     * Whether the cache is active.
     *
     * @return bool
     */
    public static function is_active(): bool {
        return self::$backupid !== null;
    }

    /**
     * Get cached rows for a source table.
     *
     * @param string $table
     * @param array $values Resolved source params.
     * @param string $sortby
     * @return stdClass[]|null Null if the table is not handled by this cache.
     */
    public static function get_table_rows(string $table, array $values, string $sortby = ''): ?array {
        if (!self::is_active() || !$values) {
            return null;
        }
        if (!str_starts_with($table, 'question_') && !str_starts_with($table, 'qtype_')) {
            return null;
        }

        $parentfield = array_key_first($values);
        if (!in_array($parentfield, ['question', 'questionid', 'questionbankentryid'], true)) {
            return null;
        }

        if (!isset(self::$loadedtables[$table])) {
            self::load_table($table, $parentfield, $sortby);
        }

        $parentvalue = (string) $values[$parentfield];
        $rows = self::$tablerows[$table][$parentvalue] ?? [];

        if (count($values) > 1) {
            $rows = array_values(array_filter($rows, static function ($row) use ($values, $parentfield) {
                foreach ($values as $field => $value) {
                    if ($field === $parentfield) {
                        continue;
                    }
                    if (!property_exists($row, $field) || (string) $row->$field !== (string) $value) {
                        return false;
                    }
                }
                return true;
            }));
        }

        return array_values($rows);
    }

    /**
     * Get the question categories included in the backup.
     *
     * @return stdClass[]
     */
    public static function get_question_categories(): array {
        if (self::$categories === null) {
            self::load_question_categories();
        }
        return self::$categories;
    }

    /**
     * Get question bank entries for a category.
     *
     * @param int $categoryid
     * @return stdClass[]
     */
    public static function get_entries_for_category(int $categoryid): array {
        return self::$entriesbycategory[$categoryid] ?? [];
    }

    /**
     * Get the question record for a version.
     *
     * @param int $versionid
     * @return stdClass[]
     */
    public static function get_question_by_version(int $versionid): array {
        return isset(self::$versiontoquestion[$versionid]) ? [self::$versiontoquestion[$versionid]] : [];
    }

    /**
     * Get hints for a question.
     *
     * @param int $questionid
     * @return stdClass[]
     */
    public static function get_hints_for_question(int $questionid): array {
        if (self::$hintsbyquestion === null) {
            self::load_hints();
        }
        return self::$hintsbyquestion[$questionid] ?? [];
    }

    /**
     * Get tags for a question.
     *
     * @param int $questionid
     * @return stdClass[]
     */
    public static function get_tags_for_question(int $questionid): array {
        if (self::$tagsbyquestion === null) {
            self::load_tags();
        }
        return self::$tagsbyquestion[$questionid] ?? [];
    }

    /**
     * Get comments for a question.
     *
     * @param int $questionid
     * @return stdClass[]
     */
    public static function get_comments_for_question(int $questionid): array {
        if (self::$commentsbyquestion === null) {
            self::load_comments();
        }
        return self::$commentsbyquestion[$questionid] ?? [];
    }

    /**
     * Get custom fields for a question.
     *
     * @param int $questionid
     * @return stdClass[]
     */
    public static function get_customfields_for_question(int $questionid): array {
        if (self::$customfieldsbyquestion === null) {
            self::load_customfields();
        }
        return self::$customfieldsbyquestion[$questionid] ?? [];
    }

    /**
     * Load the question categories included in the backup.
     */
    private static function load_question_categories(): void {
        global $DB;

        $categoryids = backup_controller_dbops::get_item_ids(self::$backupid, 'question_categoryfinal');

        if (!$categoryids) {
            self::$categories = [];
            return;
        }

        $categories = $DB->get_records_list('question_categories', 'id', $categoryids, '', '*');
        $contextids = [];
        foreach ($categories as $category) {
            $contextids[] = (int) $category->contextid;
        }

        $contexts = $contextids
            ? $DB->get_records_list('context', 'id', array_unique($contextids), '', 'id, contextlevel, instanceid')
            : [];

        foreach ($categories as $category) {
            $context = $contexts[$category->contextid] ?? null;
            $category->contextlevel = $context->contextlevel ?? null;
            $category->contextinstanceid = $context->instanceid ?? null;
        }

        self::$categories = array_values($categories);
    }

    /**
     * Load the entry ids included in the backup.
     *
     * @param string $backupid
     * @return int[]
     */
    private static function load_entry_ids(string $backupid): array {
        global $DB;

        $ids = $DB->get_fieldset_sql("
            SELECT qbe.id
              FROM {question_bank_entries} qbe
              JOIN {question_category_complete_temp} qcc ON qcc.itemid = qbe.questioncategoryid
             WHERE qcc.backupid = ?", [$backupid]);

        if ($DB->record_exists('question_category_partial_temp', ['backupid' => $backupid])) {
            $itemids = backup_controller_dbops::get_item_ids($backupid, 'question_bank_entry');
            if ($itemids) {
                [$insql1, $params1] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'qbe');
                [$insql2, $params2] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, 'pqbe');
                $params = $params1 + $params2;
                $params['backupid'] = $backupid;
                $partialids = $DB->get_fieldset_sql(
                    "SELECT qbe.id
                       FROM {question_bank_entries} qbe
                       JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                       JOIN {question} q ON q.id = qv.questionid
                  LEFT JOIN {question_versions} parentqv ON parentqv.questionid = q.parent
                       JOIN {question_category_partial_temp} qcp ON qcp.itemid = qbe.questioncategoryid
                      WHERE qcp.backupid = :backupid
                        AND (qbe.id {$insql1} OR parentqv.questionbankentryid {$insql2})",
                    $params
                );
                $ids = array_merge($ids, $partialids);
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Load versions for the given entries.
     *
     * @param int[] $entryids
     * @return array Entry id => versions.
     */
    private static function load_versions(array $entryids): array {
        global $DB;

        $versions = $DB->get_records_list(
            'question_versions',
            'questionbankentryid',
            $entryids,
            'version ASC',
            'id, questionid, questionbankentryid, version, status'
        );

        $grouped = [];
        foreach ($versions as $version) {
            $entryid = (int) $version->questionbankentryid;
            $versionid = (int) $version->id;
            $grouped[$entryid][] = $version;
            self::$versiontoquestionid[$versionid] = (int) $version->questionid;
        }

        self::$tablerows['question_versions'] = [];
        foreach ($grouped as $entryid => $entryversions) {
            self::$tablerows['question_versions'][(string) $entryid] = $entryversions;
        }
        self::$loadedtables['question_versions'] = true;

        return $grouped;
    }

    /**
     * Load questions by id.
     *
     * @param int[] $questionids
     * @return array Question id => question.
     */
    private static function load_questions(array $questionids): array {
        global $DB;

        return $DB->get_records_list('question', 'id', $questionids, '', '*');
    }

    /**
     * Load rows for a generic source table.
     *
     * @param string $table
     * @param string $parentfield
     * @param string $sortby
     */
    private static function load_table(string $table, string $parentfield, string $sortby): void {
        global $DB;

        self::$loadedtables[$table] = true;
        self::$tablerows[$table] = [];

        if ($parentfield === 'questionbankentryid') {
            $ids = self::$entryids;
        } else {
            $ids = self::$questionids;
        }
        if (!$ids) {
            return;
        }

        $sort = '';
        if ($sortby !== '' && preg_match('/^[a-zA-Z0-9_, ]+$/', $sortby)) {
            $sort = ' ORDER BY ' . $sortby;
        }

        foreach (array_chunk($ids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $rows = $DB->get_records_sql("SELECT * FROM {" . $table . "} WHERE " . $parentfield .
                " {$insql}{$sort}", $params);
            foreach ($rows as $row) {
                $parentvalue = (string) $row->$parentfield;
                self::$tablerows[$table][$parentvalue][] = $row;
            }
        }
    }

    /**
     * Load hints for all questions.
     */
    private static function load_hints(): void {
        global $DB;

        self::$hintsbyquestion = [];
        foreach (array_chunk(self::$questionids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $rows = $DB->get_records_sql("
                    SELECT *
                      FROM {question_hints}
                     WHERE questionid {$insql}
                  ORDER BY id", $params);
            foreach ($rows as $row) {
                self::$hintsbyquestion[(int) $row->questionid][] = $row;
            }
        }
    }

    /**
     * Load tags for all questions.
     */
    private static function load_tags(): void {
        global $DB;

        self::$tagsbyquestion = [];
        foreach (array_chunk(self::$questionids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $rs = $DB->get_recordset_sql("
                    SELECT t.id, ti.contextid, t.name, t.rawname, ti.itemid
                      FROM {tag} t
                      JOIN {tag_instance} ti ON ti.tagid = t.id
                     WHERE ti.itemid {$insql}
                       AND ti.itemtype = 'question'
                       AND ti.component = 'core_question'", $params);
            foreach ($rs as $row) {
                self::$tagsbyquestion[(int) $row->itemid][] = $row;
            }
            $rs->close();
        }
    }

    /**
     * Load comments for all questions.
     */
    private static function load_comments(): void {
        global $DB;

        self::$commentsbyquestion = [];
        $contextid = context_system::instance()->id;
        foreach (array_chunk(self::$questionids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $params['contextid'] = $contextid;
            $rows = $DB->get_records_sql("
                    SELECT c.*
                      FROM {comments} c
                     WHERE c.contextid = :contextid
                       AND c.component = 'qbank_comment'
                       AND c.commentarea = 'question'
                       AND c.itemid {$insql}", $params);
            foreach ($rows as $row) {
                self::$commentsbyquestion[(int) $row->itemid][] = $row;
            }
        }
    }

    /**
     * Load custom fields for all questions.
     */
    private static function load_customfields(): void {
        global $DB;

        self::$customfieldsbyquestion = [];
        foreach (array_chunk(self::$questionids, 1000) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'id');
            $rows = $DB->get_records_sql("
                    SELECT cfd.id, cff.shortname, cff.type, cfd.value, cfd.valueformat, cfd.valuetrust,
                           cfd.instanceid
                      FROM {customfield_data} cfd
                      JOIN {customfield_field} cff ON cff.id = cfd.fieldid
                      JOIN {customfield_category} cfc ON cfc.id = cff.categoryid
                     WHERE cfc.component = 'qbank_customfields'
                       AND cfc.area = 'question'
                       AND cfd.instanceid {$insql}", $params);
            foreach ($rows as $row) {
                self::$customfieldsbyquestion[(int) $row->instanceid][] = $row;
            }
        }
    }
}
