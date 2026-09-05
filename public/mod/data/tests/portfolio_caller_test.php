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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/lib/portfolio/caller.php');
require_once($CFG->dirroot . '/mod/data/locallib.php');

/**
 * Tests for the data_portfolio_caller class, covering the serialise/unserialise
 * round-trip it goes through when a database export is stored by the exporter
 * (portfolio_exporter::save()) and resumed later (portfolio_exporter::rewaken_object()).
 *
 * @package    mod_data
 * @category   test
 * @covers     \data_portfolio_caller
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class portfolio_caller_test extends \advanced_testcase {

    /**
     * Create a database activity together with one text field and one entry.
     *
     * @return array [$cm, $field, $entry, $generator]
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
        // Entry contents are keyed by the field id (see the create_entry() docblock).
        $entry = $generator->create_entry($data, [$field->field->id => 'Alice']);

        return [$cm, $field, $entry, $generator];
    }

    /**
     * Capture the complete instance state (every declared property, including
     * inherited ones) of a caller as a name → value map.
     *
     * This is deliberately property-name-agnostic: it automatically follows any
     * future renaming of the caller's properties, and it covers the whole
     * hierarchy rather than a hand-picked subset of known names.
     *
     * @param \data_portfolio_caller $caller
     * @return array
     */
    private static function snapshot_state(\data_portfolio_caller $caller): array {
        $state = [];
        $ref = new \ReflectionObject($caller);
        foreach ($ref->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }
            $state[$property->getName()] = $property->getValue($caller);
        }
        return $state;
    }

    /**
     * Test that a serialise/unserialise round-trip of a single-entry export
     * caller preserves all of the loaded export state (MDL-89053).
     */
    public function test_serialise_unserialise_roundtrip_preserves_single_entry_state(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$cm, $field, $entry] = $this->create_database_data();

        // create_entry() returns the record id (an int), which is the recordid
        // the portfolio caller is given for a single-entry export.
        $caller = new \data_portfolio_caller(['id' => $cm->id, 'recordid' => $entry]);
        $caller->load_data();

        $before = self::snapshot_state($caller);

        // This mirrors the portfolio_exporter flow: the caller is serialised
        // into portfolio_tempdata and unserialised when the export is resumed.
        $copy = unserialize(serialize($caller));

        $this->assertInstanceOf(\data_portfolio_caller::class, $copy);

        // Every declared property (own and inherited, regardless of name) must
        // survive the round-trip.
        $this->assertEquals($before, self::snapshot_state($copy));

        // The resumed caller still fulfils its public contract.
        $this->assertStringContainsString('/mod/data/view.php?id=' . $cm->id, $copy->get_return_url());
        $this->assertStringContainsString(\data_portfolio_caller::display_name(), $copy->heading_summary());
        $this->assertTrue($copy->check_permissions());
    }

    /**
     * Test that a serialise/unserialise round-trip of a whole-database export
     * caller preserves the loaded records, the owning course module and the
     * number of entries owned by the user (MDL-89053).
     */
    public function test_serialise_unserialise_roundtrip_preserves_whole_database_state(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$cm, $field, $entry] = $this->create_database_data();

        $caller = new \data_portfolio_caller(['id' => $cm->id]);
        $caller->load_data();

        $before = self::snapshot_state($caller);

        $copy = unserialize(serialize($caller));

        $this->assertInstanceOf(\data_portfolio_caller::class, $copy);

        // Every declared property (own and inherited, regardless of name) must
        // survive the round-trip.
        $this->assertEquals($before, self::snapshot_state($copy));

        // The resumed caller still fulfils its public contract.
        $this->assertStringContainsString('/mod/data/view.php?id=' . $cm->id, $copy->get_return_url());
        $this->assertStringContainsString(\data_portfolio_caller::display_name(), $copy->heading_summary());
        $this->assertTrue($copy->check_permissions());
    }
}
