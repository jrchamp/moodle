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

namespace core_communication\tests\fixtures;

use core_communication\communication_provider;
use core_communication\processor;
use core_communication\room_user_provider;

/**
 * Test provider which only implements room user features.
 *
 * This provider deliberately does not implement user_provider or room_chat_provider so that tests can verify that
 * room user features do not depend on either of those interfaces.
 *
 * @package    core_communication
 * @category   test
 * @copyright  2026 Jonathan Champ
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class room_user_only_provider implements communication_provider, room_user_provider {
    /**
     * Load the communication provider for the communication API.
     *
     * @param processor $communication The communication processor object.
     * @return room_user_only_provider
     */
    public static function load_for_instance(processor $communication): self {
        return new self();
    }

    /**
     * Check if the provider is configured.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return true;
    }

    /**
     * Add members to communication room.
     *
     * @param array $userids The user ids to be added
     */
    public function add_members_to_room(array $userids): void {
    }

    /**
     * Update room membership for the communication room.
     *
     * @param array $userids The user ids to be updated
     */
    public function update_room_membership(array $userids): void {
    }

    /**
     * Remove members from room.
     *
     * @param array $userids The user ids to be removed
     */
    public function remove_members_from_room(array $userids): void {
    }
}
