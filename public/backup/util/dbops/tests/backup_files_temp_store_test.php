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
 * Tests for the request-cache-backed backup_files_temp store.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\backup_files_temp_store::class)]
final class backup_files_temp_store_test extends \advanced_testcase {
    /**
     * Includes the class under test.
     */
    public static function setUpBeforeClass(): void {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/dbops/backup_files_temp_store.class.php');
        parent::setUpBeforeClass();
    }

    /** @var string Test restore id. */
    private const RESTOREID = 'testrestore123';

    /**
     * Set up the test.
     */
    protected function setUp(): void {
        parent::setUp();
        \backup_files_temp_store::reset_all();
    }

    /**
     * Tear down the test.
     */
    protected function tearDown(): void {
        \backup_files_temp_store::reset_all();
        parent::tearDown();
    }

    /**
     * Test the basic store operations and indexes.
     */
    public function test_store_operations(): void {
        $store = \backup_files_temp_store::get(self::RESTOREID);

        $id1 = $store->insert_record([
            'backupid' => self::RESTOREID,
            'contextid' => 100,
            'component' => 'course',
            'filearea' => 'summary',
            'itemid' => 0,
            'info' => 'first',
        ]);
        $id2 = $store->insert_record([
            'backupid' => self::RESTOREID,
            'contextid' => 100,
            'component' => 'course',
            'filearea' => 'summary',
            'itemid' => 1,
            'info' => 'second',
        ]);
        $id3 = $store->insert_record([
            'backupid' => self::RESTOREID,
            'contextid' => 200,
            'component' => 'mod_forum',
            'filearea' => 'attachment',
            'itemid' => 5,
            'info' => 'third',
        ]);

        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan($id1, $id2);
        $this->assertGreaterThan($id2, $id3);

        // Get by id.
        $record = $store->get_record([
            'backupid' => self::RESTOREID,
            'id' => $id1,
        ]);
        $this->assertEquals('first', $record->info);

        // Get by composite key.
        $record = $store->get_record([
            'backupid' => self::RESTOREID,
            'contextid' => 100,
            'component' => 'course',
            'filearea' => 'summary',
            'itemid' => 1,
        ]);
        $this->assertEquals('second', $record->info);

        // Get all records in an area.
        $records = $store->get_records([
            'backupid' => self::RESTOREID,
            'contextid' => 100,
            'component' => 'course',
            'filearea' => 'summary',
        ]);
        $this->assertCount(2, $records);

        // Count and existence.
        $this->assertEquals(1, $store->count_records([
            'backupid' => self::RESTOREID,
            'contextid' => 200,
            'component' => 'mod_forum',
            'filearea' => 'attachment',
            'itemid' => 5,
        ]));
        $this->assertTrue($store->record_exists([
            'backupid' => self::RESTOREID,
            'id' => $id2,
        ]));
        $this->assertFalse($store->record_exists([
            'backupid' => self::RESTOREID,
            'id' => 999,
        ]));

        // Get selected fields.
        $rs = $store->get_records([
            'backupid' => self::RESTOREID,
            'contextid' => 100,
            'component' => 'course',
            'filearea' => 'summary',
        ], '', 'info, newcontextid, newitemid');
        $infos = [];
        foreach ($rs as $record) {
            $infos[] = $record->info;
        }
        sort($infos);
        $this->assertSame(['first', 'second'], $infos);
    }

    /**
     * Test updates, deletes and reset.
     */
    public function test_store_mutations(): void {
        $store = \backup_files_temp_store::get(self::RESTOREID);

        $id = $store->insert_record([
            'backupid' => self::RESTOREID,
            'contextid' => 100,
            'component' => 'course',
            'filearea' => 'summary',
            'itemid' => 0,
            'info' => 'first',
        ]);
        $store->insert_record([
            'backupid' => self::RESTOREID,
            'contextid' => 200,
            'component' => 'mod_forum',
            'filearea' => 'attachment',
            'itemid' => 5,
            'info' => 'third',
        ]);

        // Update the new context and item.
        $store->update_record([
            'id' => $id,
            'newcontextid' => 300,
            'newitemid' => 42,
        ], true);
        $record = $store->get_record(['backupid' => self::RESTOREID, 'id' => $id]);
        $this->assertEquals(300, $record->newcontextid);
        $this->assertEquals(42, $record->newitemid);

        // Set a field.
        $store->set_field('info', 'changed', [
            'backupid' => self::RESTOREID,
            'contextid' => 200,
            'component' => 'mod_forum',
            'filearea' => 'attachment',
        ]);
        $this->assertEquals('changed', $store->get_field([
            'backupid' => self::RESTOREID,
            'contextid' => 200,
            'component' => 'mod_forum',
            'filearea' => 'attachment',
        ], 'info'));

        // Delete a record.
        $store->delete_records(['backupid' => self::RESTOREID, 'id' => $id]);
        $this->assertFalse($store->record_exists(['backupid' => self::RESTOREID, 'id' => $id]));

        // Reset the store.
        \backup_files_temp_store::reset_all();
        $store = \backup_files_temp_store::get(self::RESTOREID);
        $this->assertEquals(0, $store->count_records(['backupid' => self::RESTOREID]));
    }
}
