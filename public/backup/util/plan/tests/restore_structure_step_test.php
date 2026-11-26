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

/**
 * Tests for the insert queue of restore structure steps.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_structure_step::class)]
final class restore_structure_step_test extends \advanced_testcase {
    /**
     * Includes the fixtures used by the test.
     */
    public static function setUpBeforeClass(): void {
        require_once(__DIR__ . '/fixtures/plan_fixtures.php');
        parent::setUpBeforeClass();
    }

    /**
     * Reset the in-memory backup ids stores between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        \backup_ids_temp_store::reset_all();
    }

    /**
     * Reset the in-memory backup ids stores between tests.
     */
    protected function tearDown(): void {
        \backup_ids_temp_store::reset_all();
        parent::tearDown();
    }

    /**
     * Create a restore structure step with a fixed restore id.
     *
     * @param string $restoreid
     * @return \mock_restore_structure_step
     */
    private function get_step(string $restoreid = 'restore_structure_step_test'): \mock_restore_structure_step {
        $task = new \mock_restore_task_basepath('testtask');
        $task->set_restoreid($restoreid);
        return new \mock_restore_structure_step('teststep', null, $task);
    }

    /**
     * Test that queued records are written and that their mappings follow the queue order.
     */
    public function test_queue_insert_records_stores_mappings_in_queue_order(): void {
        global $DB;

        $this->resetAfterTest();
        $step = $this->get_step();
        $questionid = 1000;

        // The first and third records have a different structure than the second one, so they are written in
        // separate bulk inserts and the new ids no longer follow the queue order.
        $step->queue_insert_record('question_hints', [
            'questionid' => $questionid,
            'hint' => 'First hint',
            'hintformat' => FORMAT_HTML,
        ], 'question_hint', 11, 'questionid');
        $step->queue_insert_record('question_hints', [
            'questionid' => $questionid,
            'hint' => 'Second hint',
            'hintformat' => FORMAT_HTML,
            'options' => 'x',
        ], 'question_hint', 12, 'questionid');
        $step->queue_insert_record('question_hints', [
            'questionid' => $questionid,
            'hint' => 'Third hint',
            'hintformat' => FORMAT_HTML,
        ], 'question_hint', 13, 'questionid');
        $step->flush_insert_queue();

        $hints = $DB->get_records('question_hints', ['questionid' => $questionid], 'id');
        $this->assertCount(3, $hints);
        $hintids = array_keys($hints);
        $this->assertSame('First hint', $hints[$hintids[0]]->hint);
        $this->assertSame('Third hint', $hints[$hintids[1]]->hint);
        $this->assertSame('Second hint', $hints[$hintids[2]]->hint);

        $this->assertEquals($hintids[0], $step->get_mappingid('question_hint', 11));
        $this->assertEquals($hintids[1], $step->get_mappingid('question_hint', 13));
        $this->assertEquals($hintids[2], $step->get_mappingid('question_hint', 12));
    }

    /**
     * Test that reading the mapping of a queued record writes the queue first.
     */
    public function test_get_mapping_writes_queued_records(): void {
        global $DB;

        $this->resetAfterTest();
        $step = $this->get_step();

        $step->queue_insert_record('question_hints', [
            'questionid' => 1001,
            'hint' => 'Queued hint',
            'hintformat' => FORMAT_HTML,
        ], 'question_hint', 21, 'questionid');

        // Nothing has been written yet.
        $this->assertFalse($DB->record_exists('question_hints', ['questionid' => 1001]));

        // Reading a mapping of a queued record writes the queue before reading it.
        $newid = $step->get_mappingid('question_hint', 21);
        $this->assertNotEmpty($newid);
        $this->assertTrue($DB->record_exists('question_hints', ['id' => $newid, 'questionid' => 1001]));
    }

    /**
     * Test that non-consecutive records of the same table are written with one bulk insert.
     */
    public function test_flush_insert_queue_batches_non_consecutive_records(): void {
        global $DB;

        $this->resetAfterTest();
        $step = $this->get_step();

        $step->queue_insert_record('question_hints', [
            'questionid' => 1002,
            'hint' => 'First hint',
            'hintformat' => FORMAT_HTML,
        ]);
        $step->queue_insert_record('question_datasets', [
            'question' => 1002,
            'datasetdefinition' => 1002,
        ]);
        $step->queue_insert_record('question_hints', [
            'questionid' => 1002,
            'hint' => 'Second hint',
            'hintformat' => FORMAT_HTML,
        ]);

        $writes = $DB->perf_get_writes();
        $step->flush_insert_queue();

        // The two hints are written with one bulk insert, even though they are not consecutive in the queue.
        $this->assertSame($writes + 2, $DB->perf_get_writes());
        $this->assertSame(2, $DB->count_records('question_hints', ['questionid' => 1002]));
        $this->assertSame(1, $DB->count_records('question_datasets', ['question' => 1002]));
    }
}
