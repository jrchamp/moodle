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

/**
 * Tests for the dataformat_pdf writer
 *
 * @package    dataformat_pdf
 * @copyright  2020 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace dataformat_pdf;

use core\dataformat;
use context_system;
use html_writer;
use moodle_url;

/**
 * Writer tests
 *
 * @package    dataformat_pdf
 * @copyright  2020 Paul Holden <paulh@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \dataformat_pdf\writer
 */
final class writer_test extends \advanced_testcase {
    /**
     * Get the TCPDF instance from the writer via reflection.
     *
     * @param writer $writer
     * @return \pdf
     */
    private function get_pdf(writer $writer): \pdf {
        $prop = new \ReflectionProperty(writer::class, 'pdf');
        $prop->setAccessible(true);
        return $prop->getValue($writer);
    }

    /**
     * Get the pages array from a TCPDF instance via reflection.
     *
     * @param \pdf $pdf
     * @return string[]
     */
    private function get_pdf_pages(\pdf $pdf): array {
        $prop = new \ReflectionProperty(\pdf::class, 'pages');
        $prop->setAccessible(true);
        return $prop->getValue($pdf);
    }

    /**
     * Test that every page has headings and rendering is timely.
     */
    public function test_headings_performance(): void {
        $this->resetAfterTest(true);

        $writer = new writer();
        $writer->set_filepath(make_request_directory() . '/test.pdf');
        $writer->start_output_to_file();

        $pdf = $this->get_pdf($writer);
        // Disable compression so we can read page content directly.
        $pdf->setCompression(false);

        $start = hrtime(true);
        $columns = ['First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth', 'Tenth'];
        $writer->start_sheet($columns);
        for ($rownum = 0; $rownum < 200; $rownum++) {
            $rowlabel = $rownum + 1;
            $writer->write_record(
                [
                    "Cell A{$rowlabel}",
                    "Cell B{$rowlabel}",
                    "Cell C{$rowlabel}",
                    "Cell D{$rowlabel}",
                    "Cell E{$rowlabel}",
                    "Cell F{$rowlabel}",
                    "Cell G{$rowlabel}",
                    "Cell H{$rowlabel}",
                    "Cell I{$rowlabel}",
                    "Cell J{$rowlabel}",
                ],
                $rownum
            );
        }
        $writer->close_sheet($columns);
        $secondselapsed = (hrtime(true) - $start) / 1e+9;

        $pagecount = $pdf->getNumPages();
        $this->assertGreaterThan(1, $pagecount);

        $pages = $this->get_pdf_pages($pdf);
        $this->assertCount($pagecount, $pages);

        foreach ($pages as $pagenum => $pagecontent) {
            $this->assertTrue(
                str_contains($pagecontent, "\x00F\x00i\x00r\x00s\x00t"),
                'Page ' . $pagenum . ' should contain the heading row',
            );
        }

        $writer->close_output_to_file();

        $this->assertLessThan(5, $secondselapsed, 'Generating a 10x200 PDF should be far less than 5 seconds');
    }

    /**
     * Test writing data whose content contains an image with pluginfile.php source
     */
    public function test_write_data_with_pluginfile_image(): void {
        global $CFG;

        $this->resetAfterTest(true);

        $imagefixture = "{$CFG->dirroot}/lib/filestorage/tests/fixtures/testimage.jpg";
        $image = get_file_storage()->create_file_from_pathname([
            'contextid' => context_system::instance()->id,
            'component' => 'dataformat_pdf',
            'filearea'  => 'test',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => basename($imagefixture),

        ], $imagefixture);

        $imageurl = moodle_url::make_pluginfile_url($image->get_contextid(), $image->get_component(), $image->get_filearea(),
            $image->get_itemid(), $image->get_filepath(), $image->get_filename());

        // Insert out test image into the data so it is exported.
        $columns = ['animal', 'image'];
        $row = ['cat', html_writer::img($imageurl->out(), 'My image')];

        // Export to file. Assert that the exported file exists.
        $exportfile = dataformat::write_data('My export', 'pdf', $columns, [$row]);
        $this->assertFileExists($exportfile);

        // The exported file should be a reasonable size (~275kb).
        $this->assertGreaterThan(270000, filesize($exportfile));
    }
}
