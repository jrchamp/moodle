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
 * Tests for the request-cache-backed backup_ids_temp store.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_ids_temp_store::class)]
final class backup_ids_temp_store_test extends \advanced_testcase {
    /**
     * Includes the class under test.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/dbops/backup_ids_temp_store.class.php');
        parent::setUpBeforeClass();
    }

    /** @var string Test restore id. */
    private const RESTOREID = 'testrestore123';

    /**
     * Set up the test.
     */
    protected function setUp(): void {
        parent::setUp();
        \backup_ids_temp_store::reset_all();
    }

    /**
     * Tear down the test.
     */
    protected function tearDown(): void {
        \backup_ids_temp_store::reset_all();
        parent::tearDown();
    }

    /**
     * Test the basic store operations and indexes.
     */
    public function test_store_operations(): void {
        $store = \backup_ids_temp_store::get(self::RESTOREID);

        $id1 = $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 1,
            'newitemid' => 10,
            'parentitemid' => 100,
            'info' => 'a',
        ]);
        $id2 = $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 2,
            'newitemid' => 20,
            'parentitemid' => 100,
            'info' => 'b',
        ]);
        $id3 = $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 3,
            'newitemid' => 30,
            'parentitemid' => 200,
            'info' => 'c',
        ]);
        $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question_category',
            'itemid' => 100,
            'newitemid' => 1000,
            'parentitemid' => null,
            'info' => 'category',
        ]);

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan($id1, $id2);
        $this->assertGreaterThan($id2, $id3);

        // Get by primary key.
        $record = $store->get_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 2,
        ]);
        $this->assertEquals('b', $record->info);
        $this->assertEquals(20, $record->newitemid);

        // Get all questions.
        $questions = $store->get_records(['backupid' => self::RESTOREID, 'itemname' => 'question']);
        $this->assertCount(3, $questions);

        // Get by parent item id.
        $questions = $store->get_records([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'parentitemid' => 100,
        ]);
        $this->assertCount(2, $questions);

        // Get by new item id.
        $questions = $store->get_records([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'newitemid' => 20,
        ]);
        $this->assertCount(1, $questions);
        $this->assertEquals(2, reset($questions)->itemid);

        // Sort and select fields.
        $questions = $store->get_records(
            ['backupid' => self::RESTOREID, 'itemname' => 'question'],
            'itemid DESC',
            'itemid, info'
        );
        $this->assertSame([3, 2, 1], array_keys($questions));
        $this->assertEquals('c', $questions[3]->info);

        // Counts and existence.
        $this->assertEquals(3, $store->count_records(['backupid' => self::RESTOREID, 'itemname' => 'question']));
        $this->assertTrue($store->record_exists([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 1,
        ]));
        $this->assertFalse($store->record_exists([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 99,
        ]));

        // Get field.
        $this->assertEquals('a', $store->get_field([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 1,
        ], 'info'));

        // Get selected fields.
        $rs = $store->get_records(['backupid' => self::RESTOREID, 'itemname' => 'question'], '', 'itemid');
        $itemids = [];
        foreach ($rs as $record) {
            $itemids[] = (int) $record->itemid;
        }
        sort($itemids);
        $this->assertSame([1, 2, 3], $itemids);
    }

    /**
     * Test updates, deletes and reset.
     */
    public function test_store_mutations(): void {
        $store = \backup_ids_temp_store::get(self::RESTOREID);

        $id = $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 1,
            'newitemid' => 10,
            'parentitemid' => 100,
            'info' => 'a',
        ]);
        $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 2,
            'newitemid' => 20,
            'parentitemid' => 100,
            'info' => 'b',
        ]);
        $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question_category',
            'itemid' => 100,
            'newitemid' => 1000,
            'parentitemid' => null,
            'info' => 'category',
        ]);

        // Update an indexed field and verify the new index.
        $store->update_record([
            'id' => $id,
            'newitemid' => 99,
            'info' => 'updated',
        ]);
        $record = $store->get_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'newitemid' => 99,
        ]);
        $this->assertEquals(1, $record->itemid);
        $this->assertEquals('updated', $record->info);
        $this->assertFalse($store->record_exists([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'newitemid' => 10,
        ]));

        // Set a field.
        $store->set_field('info', 'changed', [
            'backupid' => self::RESTOREID,
            'itemname' => 'question_category',
        ]);
        $this->assertEquals('changed', $store->get_field([
            'backupid' => self::RESTOREID,
            'itemname' => 'question_category',
        ], 'info'));

        // Delete by parent item id.
        $store->delete_records([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'parentitemid' => 100,
        ]);
        $this->assertEquals(0, $store->count_records([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
        ]));

        // Reset the store.
        \backup_ids_temp_store::reset_all();
        $store = \backup_ids_temp_store::get(self::RESTOREID);
        $this->assertEquals(0, $store->count_records(['backupid' => self::RESTOREID]));
    }

    /**
     * Test initialisation and that resetting one store does not affect another.
     */
    public function test_initialization_and_scoped_reset(): void {
        $store = \backup_ids_temp_store::get(self::RESTOREID);
        $otherstore = \backup_ids_temp_store::get('otherstore');

        $this->assertFalse($store->is_initialized());
        $store->initialize();
        $this->assertTrue($store->is_initialized());

        $store->insert_record([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
            'itemid' => 1,
        ]);
        $otherstore->insert_record([
            'backupid' => 'otherstore',
            'itemname' => 'question',
            'itemid' => 2,
        ]);

        $store->reset();

        $this->assertFalse($store->is_initialized());
        $this->assertEquals(0, $store->count_records([
            'backupid' => self::RESTOREID,
            'itemname' => 'question',
        ]));
        $this->assertEquals(1, $otherstore->count_records([
            'backupid' => 'otherstore',
            'itemname' => 'question',
        ]));
    }
}
