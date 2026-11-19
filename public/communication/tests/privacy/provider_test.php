<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace core_communication\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Data provider tests for core_communication.
 *
 * @package    core_communication
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\core_communication\privacy\provider::class)]
final class provider_test extends provider_testcase {
    /**
     * Test that the communication user data is described in the metadata.
     */
    public function test_get_metadata(): void {
        $collection = new collection('core_communication');
        $collection = provider::get_metadata($collection);
        $items = $collection->get_collection();

        $this->assertCount(1, $items);

        $table = reset($items);
        $this->assertEquals('communication_user', $table->get_name());
        $this->assertEquals('privacy:metadata:communication_user', $table->get_summary());

        $fields = $table->get_privacy_fields();
        $this->assertArrayHasKey('commid', $fields);
        $this->assertArrayHasKey('userid', $fields);
        $this->assertArrayHasKey('synced', $fields);
        $this->assertArrayHasKey('deleted', $fields);
    }

    /**
     * Test getting contexts for a user with communication user mappings.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $user1 = $dg->create_user();
        $user2 = $dg->create_user();
        $user3 = $dg->create_user();
        $course1 = $dg->create_course();
        $course2 = $dg->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $commid1 = $this->create_communication_instance($context1);
        $commid2 = $this->create_communication_instance($context2);

        $this->create_user_mapping($commid1, $user1->id);
        $this->create_user_mapping($commid1, $user2->id);
        $this->create_user_mapping($commid2, $user1->id);

        $contextids = provider::get_contexts_for_userid($user1->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$context1->id, $context2->id], $contextids);

        $contextids = provider::get_contexts_for_userid($user2->id)->get_contextids();
        $this->assertEqualsCanonicalizing([$context1->id], $contextids);

        $contextids = provider::get_contexts_for_userid($user3->id)->get_contextids();
        $this->assertEmpty($contextids);
    }

    /**
     * Test exporting communication user mappings.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $user1 = $dg->create_user();
        $user2 = $dg->create_user();
        $course1 = $dg->create_course();
        $course2 = $dg->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $commid1 = $this->create_communication_instance($context1, 'Room one', 'communication_matrix');
        $commid2 = $this->create_communication_instance($context2, 'Room two', 'communication_customlink');
        $this->create_user_mapping($commid1, $user1->id, 1, 0);
        $this->create_user_mapping($commid1, $user2->id);
        $this->create_user_mapping($commid2, $user1->id, 0, 1);

        $contextlist = new approved_contextlist(
            $user1,
            'core_communication',
            [$context1->id, $context2->id]
        );
        provider::export_user_data($contextlist);

        $data = writer::with_context($context1)->get_data([]);
        $this->assertCount(1, $data->memberships);
        $membership = reset($data->memberships);
        $this->assertEquals('communication_matrix', $membership->provider);
        $this->assertEquals('Room one', $membership->roomname);
        $this->assertEquals(transform::yesno(1), $membership->synced);
        $this->assertEquals(transform::yesno(0), $membership->deleted);

        $data = writer::with_context($context2)->get_data([]);
        $this->assertCount(1, $data->memberships);
        $membership = reset($data->memberships);
        $this->assertEquals('communication_customlink', $membership->provider);
        $this->assertEquals('Room two', $membership->roomname);
        $this->assertEquals(transform::yesno(0), $membership->synced);
        $this->assertEquals(transform::yesno(1), $membership->deleted);
    }

    /**
     * Test deleting communication user mappings for one user.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $user1 = $dg->create_user();
        $user2 = $dg->create_user();
        $course1 = $dg->create_course();
        $course2 = $dg->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $commid1 = $this->create_communication_instance($context1);
        $commid2 = $this->create_communication_instance($context2);
        $this->create_user_mapping($commid1, $user1->id);
        $this->create_user_mapping($commid1, $user2->id);
        $this->create_user_mapping($commid2, $user1->id);

        provider::delete_data_for_user(new approved_contextlist(
            $user1,
            'core_communication',
            [$context1->id]
        ));

        $this->assertFalse($DB->record_exists('communication_user', [
            'commid' => $commid1,
            'userid' => $user1->id,
        ]));
        $this->assertTrue($DB->record_exists('communication_user', [
            'commid' => $commid1,
            'userid' => $user2->id,
        ]));
        $this->assertTrue($DB->record_exists('communication_user', [
            'commid' => $commid2,
            'userid' => $user1->id,
        ]));
    }

    /**
     * Test deleting all communication user mappings in a context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $user1 = $dg->create_user();
        $user2 = $dg->create_user();
        $course1 = $dg->create_course();
        $course2 = $dg->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $commid1 = $this->create_communication_instance($context1);
        $commid2 = $this->create_communication_instance($context2);
        $this->create_user_mapping($commid1, $user1->id);
        $this->create_user_mapping($commid1, $user2->id);
        $this->create_user_mapping($commid2, $user1->id);

        provider::delete_data_for_all_users_in_context($context1);

        $this->assertFalse($DB->record_exists('communication_user', ['commid' => $commid1]));
        $this->assertTrue($DB->record_exists('communication_user', [
            'commid' => $commid2,
            'userid' => $user1->id,
        ]));
    }

    /**
     * Test getting users with communication user mappings in a context.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $user1 = $dg->create_user();
        $user2 = $dg->create_user();
        $user3 = $dg->create_user();
        $course1 = $dg->create_course();
        $course2 = $dg->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $commid1 = $this->create_communication_instance($context1);
        $commid2 = $this->create_communication_instance($context2);
        $this->create_user_mapping($commid1, $user1->id);
        $this->create_user_mapping($commid1, $user2->id);
        $this->create_user_mapping($commid2, $user3->id);

        $userlist = new userlist($context1, 'core_communication');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$user1->id, $user2->id], $userlist->get_userids());
    }

    /**
     * Test deleting communication user mappings for multiple users in a context.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $this->resetAfterTest();

        $dg = $this->getDataGenerator();
        $user1 = $dg->create_user();
        $user2 = $dg->create_user();
        $user3 = $dg->create_user();
        $course1 = $dg->create_course();
        $course2 = $dg->create_course();
        $context1 = \context_course::instance($course1->id);
        $context2 = \context_course::instance($course2->id);

        $commid1 = $this->create_communication_instance($context1);
        $commid2 = $this->create_communication_instance($context2);
        $this->create_user_mapping($commid1, $user1->id);
        $this->create_user_mapping($commid1, $user2->id);
        $this->create_user_mapping($commid2, $user1->id);
        $this->create_user_mapping($commid2, $user3->id);

        provider::delete_data_for_users(new approved_userlist(
            $context1,
            'core_communication',
            [$user1->id]
        ));

        $this->assertFalse($DB->record_exists('communication_user', [
            'commid' => $commid1,
            'userid' => $user1->id,
        ]));
        $this->assertTrue($DB->record_exists('communication_user', [
            'commid' => $commid1,
            'userid' => $user2->id,
        ]));
        $this->assertTrue($DB->record_exists('communication_user', [
            'commid' => $commid2,
            'userid' => $user1->id,
        ]));
        $this->assertTrue($DB->record_exists('communication_user', [
            'commid' => $commid2,
            'userid' => $user3->id,
        ]));
    }

    /**
     * Create a communication instance for a context.
     *
     * @param \context $context The context for the communication instance.
     * @param string $roomname The room name.
     * @param string $provider The communication provider.
     * @return int The communication instance id.
     */
    private function create_communication_instance(
        \context $context,
        string $roomname = 'Test room',
        string $provider = 'communication_matrix',
    ): int {
        global $DB;

        return $DB->insert_record('communication', (object) [
            'contextid' => $context->id,
            'instanceid' => $context->instanceid,
            'component' => 'core_course',
            'instancetype' => 'coursecommunication',
            'provider' => $provider,
            'roomname' => $roomname,
            'avatarfilename' => null,
            'active' => 1,
            'avatarsynced' => 0,
        ]);
    }

    /**
     * Create a communication user mapping.
     *
     * @param int $commid The communication instance id.
     * @param int $userid The user id.
     * @param int $synced Whether the user is synced.
     * @param int $deleted Whether the user is flagged as deleted.
     */
    private function create_user_mapping(int $commid, int $userid, int $synced = 0, int $deleted = 0): void {
        global $DB;

        $DB->insert_record('communication_user', (object) [
            'commid' => $commid,
            'userid' => $userid,
            'synced' => $synced,
            'deleted' => $deleted,
        ]);
    }
}
