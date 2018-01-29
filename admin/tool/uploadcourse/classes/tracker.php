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
 * Output tracker.
 *
 * @package    tool_uploadcourse
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/weblib.php');

/**
 * Class output tracker.
 *
 * @package    tool_uploadcourse
 * @copyright  2013 Frédéric Massart, 2017 Konrad Lorinczi (implemented automatic category creation from CSV)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploadcourse_tracker {

    /**
     * Constant to output nothing.
     */
    const NO_OUTPUT = 0;

    /**
     * Constant to output HTML.
     */
    const OUTPUT_HTML = 1;

    /**
     * Constant to output plain text.
     */
    const OUTPUT_PLAIN = 2;

    /**
     * @var array columns to display.
     */
    protected $columns = array('line', 'result', 'id', 'shortname', 'fullname', 'idnumber', 'status');

    /**
     * @var int row number.
     */
    protected $rownb = 0;

    /**
     * @var int chosen output mode.
     */
    protected $outputmode;

    /**
     * @var object output buffer.
     */
    protected $buffer;

    /**
     * Constructor.
     *
     * @param int $outputmode desired output mode.
     */
    public function __construct($outputmode = self::NO_OUTPUT) {
        $this->outputmode = $outputmode;
        if ($this->outputmode == self::OUTPUT_PLAIN) {
            $this->buffer = new progress_trace_buffer(new text_progress_trace());
        }
    }

    /**
     * Finish the output.
     *
     * @return void
     */
    public function finish() {
        if ($this->outputmode == self::NO_OUTPUT) {
            return;
        }

        if ($this->outputmode == self::OUTPUT_HTML) {
            echo html_writer::end_tag('table');
        }
    }

    /**
     * Output the results.
     *
     * @param int $total total courses.
     * @param int $cat_created count of categories created.
     * @param int $created count of courses created.
     * @param int $updated count of courses updated.
     * @param int $deleted count of courses deleted.
     * @param int $errors count of errors.
     * @return void
     */
    public function results($total, $cat_created, $created, $updated, $deleted, $errors) {
        if ($this->outputmode == self::NO_OUTPUT) {
            return;
        }

        $message = array(
            get_string('categoriescreated', 'tool_uploadcourse', $cat_created),
            get_string('coursestotal', 'tool_uploadcourse', $total),
            get_string('coursescreated', 'tool_uploadcourse', $created),
            get_string('coursesupdated', 'tool_uploadcourse', $updated),
            get_string('coursesdeleted', 'tool_uploadcourse', $deleted),
            get_string('courseserrors', 'tool_uploadcourse', $errors)
        );

        if ($this->outputmode == self::OUTPUT_PLAIN) {
            foreach ($message as $msg) {
                $this->buffer->output($msg);
            }
        } else if ($this->outputmode == self::OUTPUT_HTML) {
            $buffer = new progress_trace_buffer(new html_list_progress_trace());
            foreach ($message as $msg) {
                $buffer->output($msg);
            }
            $buffer->finished();
        }
    }

    /**
     * Output one more line.
     *
     * @param int $line line number.
     * @param bool $outcome success or not?
     * @param array $status array of statuses.
     * @param array $data extra data to display.
     * @return void
     */
    public function output($line, $outcome, $status, $data) {
        global $OUTPUT;
        
        // Debug message showing the errors statuses
        $debug_msg = str_replace(array("\r\n", "\n", "\r"), '<br />', var_export($data, true));
        $debug_msg = str_replace(array("  "), '&nbsp;&nbsp;', $debug_msg);
        debugging('Debug: $data (admin/tool/uploadcourse/classes/tracker.php output()): ' . $debug_msg . "<br>\n", DEBUG_DEVELOPER);
        
        if ($this->outputmode == self::NO_OUTPUT) {
            return;
        }

        if ($this->outputmode == self::OUTPUT_PLAIN) {
            $message = array(
                $line,
                $outcome ? 'OK' : 'NOK',
                isset($data['id']) ? $data['id'] : '',
                isset($data['shortname']) ? $data['shortname'] : '',
                isset($data['fullname']) ? $data['fullname'] : '',
                isset($data['category_path']) ? $data['category_path'] : '',
                isset($data['idnumber']) ? $data['idnumber'] : ''
            );
            $this->buffer->output(implode("\t", $message));
            if (!empty($status)) {
                foreach ($status as $st) {
                    $this->buffer->output($st, 1);
                }
            }
        } else if ($this->outputmode == self::OUTPUT_HTML) {
            $ci = 0;
            $this->rownb++;
            if (is_array($status)) {
                foreach ($status as $code => $messages) {
                    // Handle the case, when multiple messages are used (for example multiple categories needs to be created), so we are flattening $messages array
                    if (is_array($messages)) {
                        $substatus = implode(html_writer::empty_tag('br'), $messages);
                        $status[$code] = $substatus;
                    }
                }
                $status = implode(html_writer::empty_tag('br'), $status);
            }
            if ($outcome) {
                $outcome = $OUTPUT->pix_icon('i/valid', '');
            } else {
                $outcome = $OUTPUT->pix_icon('i/invalid', '');
            }
            echo html_writer::start_tag('tr', array('class' => 'r' . $this->rownb % 2));
            echo html_writer::tag('td', $line, array('class' => 'c' . $ci++));
            echo html_writer::tag('td', $outcome, array('class' => 'c' . $ci++));
            echo html_writer::tag('td', isset($data['id']) ? $data['id'] : '', array('class' => 'c' . $ci++));
            echo html_writer::tag('td', isset($data['shortname']) ? $data['shortname'] : '', array('class' => 'c' . $ci++));
            echo html_writer::tag('td', isset($data['fullname']) ? $data['fullname'] : '', array('class' => 'c' . $ci++));
            echo html_writer::tag('td', isset($data['category_path']) ? $data['category_path'] : '', array('class' => 'c' . $ci++));
            echo html_writer::tag('td', isset($data['idnumber']) ? $data['idnumber'] : '', array('class' => 'c' . $ci++));
            echo html_writer::tag('td', $status, array('class' => 'c' . $ci++));
            echo html_writer::end_tag('tr');
        }
    }

    /**
     * Start the output.
     *
     * @return void
     */
    public function start() {
        if ($this->outputmode == self::NO_OUTPUT) {
            return;
        }

        if ($this->outputmode == self::OUTPUT_PLAIN) {
            $columns = array_flip($this->columns);
            unset($columns['status']);
            $columns = array_flip($columns);
            $this->buffer->output(implode("\t", $columns));
        } else if ($this->outputmode == self::OUTPUT_HTML) {
            $ci = 0;
            echo html_writer::start_tag('table', array('class' => 'generaltable boxaligncenter flexible-wrap',
                'summary' => get_string('uploadcoursesresult', 'tool_uploadcourse')));
            echo html_writer::start_tag('tr', array('class' => 'heading r' . $this->rownb));
            echo html_writer::tag('th', get_string('csvline', 'tool_uploadcourse'),
                array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('result', 'tool_uploadcourse'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('id', 'tool_uploadcourse'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('shortname'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('fullname'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('categorypath', 'tool_uploadcourse'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('idnumber'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::tag('th', get_string('status'), array('class' => 'c' . $ci++, 'scope' => 'col'));
            echo html_writer::end_tag('tr');
        }
    }

}
