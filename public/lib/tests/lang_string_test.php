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

namespace core;

use advanced_testcase;

/**
 * Tests for the lang_string serialisation magic methods.
 *
 * @package    core
 * @category   test
 * @copyright  2026 Daniel Ziegenberg <daniel@ziegenberg.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(lang_string::class)]
final class lang_string_test extends advanced_testcase {
    /**
     * Test that a lang_string round-trip preserves its resolved string and language.
     */
    public function test_serialise_unserialise_roundtrip_resolves_and_preserves_string_and_lang(): void {
        $this->resetAfterTest();

        $ls = new lang_string('yes', 'core', null, 'en');

        // Serialising resolves the string and forces it on the source object.
        $copy = fullclone($ls);

        $string = new \ReflectionProperty(lang_string::class, 'string');
        $forcedstring = new \ReflectionProperty(lang_string::class, 'forcedstring');
        $lang = new \ReflectionProperty(lang_string::class, 'lang');

        $this->assertSame(get_string('yes', 'core'), $string->getValue($ls));
        $this->assertTrue($forcedstring->getValue($ls));

        // The rebuilt object preserves that state and stays printable.
        $this->assertInstanceOf(lang_string::class, $copy);
        $this->assertSame(get_string('yes', 'core'), (string) $copy);
        $this->assertSame($string->getValue($ls), $string->getValue($copy));
        $this->assertTrue($forcedstring->getValue($copy));
        $this->assertSame('en', $lang->getValue($copy));
    }

    /**
     * Test that a legacy __sleep() payload still deserialises into a lang_string (MDL-89053).
     */
    public function test_unserialise_tolerates_legacy_sleep_blob(): void {
        $this->resetAfterTest();

        $string = get_string('yes', 'core');

        $copy = unserialize($this->legacy_sleep_blob(lang_string::class, [
            "\0*\0forcedstring" => true,
            "\0*\0string" => $string,
            "\0*\0lang" => 'en',
        ]));

        $this->assertInstanceOf(lang_string::class, $copy);
        $this->assertSame($string, (string) $copy);
    }

    /**
     * Build a payload in the old __sleep() wire format.
     *
     * @param string $class fully qualified class name to instantiate
     * @param array $props map of serialized key name (e.g. "\0*\0name") to value
     * @return string the serialized blob
     */
    private static function legacy_sleep_blob(string $class, array $props): string {
        $blob = 'O:' . strlen($class) . ':"' . $class . '":' . count($props) . ':{';
        foreach ($props as $key => $value) {
            $blob .= 's:' . strlen($key) . ':"' . $key . '";' . serialize($value);
        }
        return $blob . '}';
    }
}
