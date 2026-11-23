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

namespace mod_data;

/**
 * Tests for data_portfolio_caller serialisation via portfolio_exporter.
 *
 * @package    mod_data
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\data_portfolio_caller::class)]
final class portfolio_caller_test extends \advanced_testcase {
    /**
     * Load the portfolio classes used by these tests.
     */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        global $CFG;
        require_once($CFG->dirroot . '/lib/portfolio/caller.php');
        require_once($CFG->dirroot . '/lib/portfolio/exporter.php');
        require_once($CFG->dirroot . '/lib/portfolio/plugin.php');
        require_once($CFG->dirroot . '/mod/data/locallib.php');
    }

    /**
     * Create a database activity with one text field.
     *
     * @return array [$course, $cm, $data, $field, $generator]
     */
    private function create_database_data(): array {
        $course = $this->getDataGenerator()->create_course();
        $data = $this->getDataGenerator()->create_module('data', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('data', $data->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_data');
        $field = $generator->create_field((object) [
            'name' => 'Name',
            'type' => 'text',
            'required' => 1,
        ], $data);

        return [$course, $cm, $data, $field, $generator];
    }

    /**
     * Build an exporter for the given caller, the same way portfolio/add.php does.
     *
     * @param \data_portfolio_caller $caller
     * @return \portfolio_exporter
     */
    private function create_exporter(\data_portfolio_caller $caller): \portfolio_exporter {
        global $USER;

        $instance = \portfolio_plugin_base::create_instance('download', 'Download', []);
        $exporter = new \portfolio_exporter($instance, $caller, 'mod_data');
        $exporter->set('user', $USER);

        return $exporter;
    }

    /**
     * Read a non-public property from the caller.
     *
     * @param object $object
     * @param string $property
     * @return mixed
     */
    private static function get_property(object $object, string $property): mixed {
        $reflection = new \ReflectionProperty($object, $property);
        return $reflection->getValue($object);
    }

    /**
     * Test that resuming a single-entry export restores the exporter link and saved state.
     */
    public function test_resume_single_entry_export(): void {
        global $USER, $DB;

        $this->resetAfterTest();
        $exporteruser = $this->getDataGenerator()->create_user();
        $this->setUser($exporteruser);

        [$course, $cm, $data, $field, $generator] = $this->create_database_data();
        $entryid = $generator->create_entry($data, [$field->field->id => 'Alice']);

        $caller = new \data_portfolio_caller(['id' => $cm->id, 'recordid' => $entryid]);
        $caller->set('user', $USER);
        $caller->load_data();
        // Populate the course property, as the real export flow does.
        $caller->get('course');

        $exporter = $this->create_exporter($caller);
        $exporter->save();
        $exportid = $exporter->get('id');

        // Remove the record so a resume that reloads data would fail.
        $DB->delete_records('data_content', ['recordid' => $entryid]);
        $DB->delete_records('data_records', ['id' => $entryid]);

        // Resume as a different user, like cron does.
        $resuminguser = $this->getDataGenerator()->create_user();
        $this->setUser($resuminguser);

        $resumed = \portfolio_exporter::rewaken_object($exportid);
        $resumedcaller = $resumed->caller();

        $this->assertInstanceOf(\data_portfolio_caller::class, $resumedcaller);
        $this->assertSame($resumed, $resumedcaller->get('exporter'));
        $this->assertSame($exporteruser->id, $resumedcaller->get('user')->id);
        $this->assertSame($course->id, $resumedcaller->get('course')->id);
        $this->assertSame($entryid, $resumedcaller->get('recordid'));
        $this->assertCount(1, self::get_property($resumedcaller, 'records'));
        $this->assertStringContainsString('/mod/data/view.php?id=' . $cm->id, $resumedcaller->get_return_url());
    }

    /**
     * Test that resuming a whole-database export keeps its saved state and export config.
     */
    public function test_resume_whole_database_export(): void {
        global $USER;

        $this->resetAfterTest();
        $exporteruser = $this->getDataGenerator()->create_user();
        $otheruser = $this->getDataGenerator()->create_user();
        $this->setUser($exporteruser);

        [$course, $cm, $data, $field, $generator] = $this->create_database_data();
        // A teacher can export all entries, so the "mine only" option is offered.
        $this->getDataGenerator()->enrol_user($exporteruser->id, $course->id, 'editingteacher');
        $generator->create_entry($data, [$field->field->id => 'Alice']);
        $generator->create_entry($data, [$field->field->id => 'Bob'], 0, [], null, $otheruser->id);

        $caller = new \data_portfolio_caller(['id' => $cm->id]);
        $caller->set('user', $USER);
        $caller->load_data();
        $caller->get('course');

        // The user can export all entries, and has chosen to export only their own.
        $this->assertTrue($caller->has_export_config());
        $caller->set_export_config(['mineonly' => true]);

        $exporter = $this->create_exporter($caller);
        $exporter->save();
        $exportid = $exporter->get('id');

        // Resume as a different user, like cron does.
        $resuminguser = $this->getDataGenerator()->create_user();
        $this->setUser($resuminguser);

        $resumed = \portfolio_exporter::rewaken_object($exportid);
        $resumedcaller = $resumed->caller();

        $this->assertInstanceOf(\data_portfolio_caller::class, $resumedcaller);
        $this->assertSame($resumed, $resumedcaller->get('exporter'));
        $this->assertSame($exporteruser->id, $resumedcaller->get('user')->id);
        $this->assertSame($course->id, $resumedcaller->get('course')->id);
        $this->assertTrue($resumedcaller->get_export_config('mineonly'));

        // Both entries and the "mine only" choice were captured before the export.
        // A reload would only find the resuming user's entries (none).
        $this->assertCount(2, self::get_property($resumedcaller, 'records'));
        $this->assertSame(1, self::get_property($resumedcaller, 'minecount'));

        // Permission checking still takes the "mine only" branch.
        $this->setUser($exporteruser);
        $this->assertTrue($resumedcaller->check_permissions());
    }
}
