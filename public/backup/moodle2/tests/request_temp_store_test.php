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

namespace core;

use backup;
use backup_controller;
use restore_controller;
use restore_dbops;

/**
 * Integration test for restoring using the request-cache-backed backup_ids_temp store.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_dbops::class)]
final class request_temp_store_test extends \advanced_testcase {
    /**
     * Set up the test.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
    }

    /**
     * Restore a course with Cloze questions while the request-cache store is enabled.
     */
    public function test_restore_with_request_temp_store(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'fullname' => 'Request temp store course',
            'shortname' => 'requesttempstore',
        ]);

        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $category = question_get_default_category(\context_module::instance($qbank->cmid)->id, true);
        $questiongenerator = $generator->get_plugin_generator('core_question');

        // Add a file to the course summary so the restore exercises file mappings.
        $coursecontext = \context_course::instance($course->id);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $coursecontext->id,
            'component' => 'course',
            'filearea' => 'summary',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'summary.txt',
        ], 'Course summary file');

        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $questiontext = 'Complete: {1:SHORTANSWER:=one} {2:SHORTANSWER:=two} {3:SHORTANSWER:=three}';
        for ($i = 1; $i <= 3; $i++) {
            $question = $questiongenerator->create_question('multianswer', null, [
                'category' => $category->id,
                'name' => "Cloze question {$i}",
                'questiontext' => ['text' => $questiontext, 'format' => FORMAT_HTML],
            ]);
            quiz_add_quiz_question($question->id, $quiz);
        }

        // Backup.
        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $course->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
        );
        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $results = $bc->get_results();
        $file = $results['backup_destination'];
        $bc->destroy();

        if (!file_exists($backupbasepath . '/moodle_backup.xml')) {
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $backupbasepath);
        }

        // Restore.
        $newcourseid = restore_dbops::create_new_course(
            $course->fullname . ' restored',
            $course->shortname . 'restored',
            $course->category,
        );
        $rc = new restore_controller(
            $backupid,
            $newcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE,
        );
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        // Verify the restored quiz and questions.
        $quizzes = $DB->get_records('quiz', ['course' => $newcourseid]);
        $this->assertCount(1, $quizzes);
        $restoredquiz = reset($quizzes);
        $this->assertEquals(3, $DB->count_records('quiz_slots', ['quizid' => $restoredquiz->id]));

        $qbankcm = $DB->get_record_sql("
            SELECT cm.id
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
             WHERE cm.course = ? AND m.name = 'qbank'", [$newcourseid]);
        $this->assertNotEmpty($qbankcm);
        $qbankcontext = \context_module::instance($qbankcm->id);
        $questioncount = $DB->count_records_sql("
            SELECT COUNT(q.id)
              FROM {question} q
              JOIN {question_versions} qv ON qv.questionid = q.id
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
             WHERE qc.contextid = ? AND q.qtype = ?", [$qbankcontext->id, 'multianswer']);
        $this->assertEquals(3, $questioncount);

        // Verify the course summary file was restored.
        $newcoursecontext = \context_course::instance($newcourseid);
        $files = $fs->get_area_files($newcoursecontext->id, 'course', 'summary', 0, 'filename', false);
        $this->assertCount(1, $files);
        $this->assertEquals('summary.txt', reset($files)->get_filename());
    }
}
