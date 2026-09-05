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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core\navigation;

global $CFG;
require_once($CFG->dirroot . '/lib/classes/navigation/navigation_node.php');

/**
 * Navigation node subclass carrying protected/private state, used to verify
 * that navigation_node::__unserialize() restores non-public properties.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class serialisable_navigation_node extends navigation_node {
    /** @var string protected state */
    protected $protecteddata = 'default-protected';
    /** @var string private state */
    private $privatedata = 'default-private';

    public function set_protecteddata(string $value): void {
        $this->protecteddata = $value;
    }

    public function get_protecteddata(): string {
        return $this->protecteddata;
    }

    public function set_privatedata(string $value): void {
        $this->privatedata = $value;
    }

    public function get_privatedata(): string {
        return $this->privatedata;
    }
}
