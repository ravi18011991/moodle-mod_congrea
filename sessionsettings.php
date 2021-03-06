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
 * Prints a particular instance of congrea
 *
 * You can have a rather longer description of the file as well,
 * if you like, and it can span multiple lines.
 *
 * @package    mod_congrea
 * @copyright  2018 Ravi Kumar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/lib.php');
require_once(dirname(__FILE__) . '/locallib.php');
require_once(dirname(__FILE__) . '/session_form.php');

$id = optional_param('id', 0, PARAM_INT); // Course_module ID.
$n = optional_param('n', 0, PARAM_INT); // Congrea instance ID - it should be named as the first character of the module.
$sessionsettings = optional_param('sessionsettings', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);
if ($id) {
    $cm = get_coursemodule_from_id('congrea', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $congrea = $DB->get_record('congrea', array('id' => $cm->instance), '*', MUST_EXIST);
} else if ($n) {
    $congrea = $DB->get_record('congrea', array('id' => $n), '*', MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $congrea->course), '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('congrea', $congrea->id, $course->id, false, MUST_EXIST);
} else {
    print_error('You must specify a course_module ID or an instance ID');
}
require_login($course, true, $cm);
$context = context_module::instance($cm->id);
$returnurl = new moodle_url('/mod/congrea/sessionsettings.php', array('id' => $cm->id, 'sessionsettings' => true));
// Print the page header.
$PAGE->set_url('/mod/congrea/sessionsettings.php', array('id' => $cm->id, 'sessionsettings' => $sessionsettings));
$PAGE->set_title(format_string($congrea->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$mform = new mod_congrea_session_form(null, array('id' => $id, 'sessionsettings' => $sessionsettings, 'edit' => $edit));
if ($mform->is_cancelled()) {
    // Do nothing.
    redirect(new moodle_url('/mod/congrea/view.php', array('id' => $cm->id)));
} else if ($fromform = $mform->get_data()) {
    $data = new stdClass();
    $data->starttime = $fromform->fromsessiondate;
    $durationinminutes = $fromform->timeduration / 60;
    $expecteddate = strtotime(date('Y-m-d H:i:s', strtotime("+$durationinminutes minutes", $data->starttime)));
    $data->endtime = $expecteddate;
    $timeduration = round((abs($data->endtime - $data->starttime) / 60));
    $data->timeduration = $timeduration;
    if (!empty($fromform->addmultiply)) {
        $data->isrepeat = $fromform->addmultiply;
        $data->repeattype = $fromform->period;
        if (!empty($fromform->days)) {
            $prefix = $daylist = '';
            foreach ($fromform->days as $keys => $daysname) {
                $daylist .= $prefix . '"' . $keys . '"';
                $prefix = ', ';
            }
            $data->additional = $daylist;
        } else {
            $data->additional = 'none';
        }
    } else {
        $data->isrepeat = 0;
        $data->repeattype = 0;
        $data->additional = 'none';
    }
    $data->teacherid = $fromform->moderatorid;
    $data->congreaid = $congrea->id;
    $exist = $DB->get_field('congrea_sessions', 'congreaid', array('congreaid' => $congrea->id));
    if (!$exist) {
        $sessionid = $DB->insert_record('congrea_sessions', $data); // Insert record in congrea table.
        mod_congrea_update_calendar($congrea, $fromform->fromsessiondate, $expecteddate, $timeduration);
    } else if ($edit) {
        $DB->delete_records('event', array('modulename' => 'congrea', 'instance' => $congrea->id));
        $sessionid = $edit;
        $data->id = $edit;
        $DB->update_record('congrea_sessions', $data);
        mod_congrea_update_calendar($congrea, $fromform->fromsessiondate, $expecteddate, $timeduration);
    }
    if (!empty($fromform->addmultiply)) {
        if ($fromform->period > 0) { // Here need to calculate repeate dates.
            $params = array('modulename' => 'congrea', 'instance' => $congrea->id); // Create multiple.
            $eventid = $DB->get_field('event', 'id', $params);
            $expecteddate = date('Y-m-d H:i:s',
                    strtotime(date('Y-m-d H:i:s', $fromform->fromsessiondate) . "+$fromform->period weeks"));
            $datelist = reapeat_date_list(date('Y-m-d H:i:s', $fromform->fromsessiondate), $expecteddate, $data->additional);
            foreach ($datelist as $startdate) {
                repeat_calendar($congrea, $eventid, $startdate, $sessionid, $timeduration);
            }
        }
    }
    redirect($returnurl);
}
// Output starts here.
echo $OUTPUT->header();
echo $OUTPUT->heading($congrea->name);
if (!empty($sessionsettings)) {
    $currenttab = 'sessionsettings';
}
congrea_print_tabs($currenttab, $context, $cm, $congrea);
echo $OUTPUT->heading('Scheduled Sessions');
$table = new html_table();
$table->head = array('Start Date', 'Session duration', 'Teacher', 'Repeat type', 'Repeat days', 'Action');
$sessionlist = $DB->get_records('congrea_sessions', array('congreaid' => $congrea->id));
if (!empty($sessionlist)) {
    foreach ($sessionlist as $list) {
        $row = array();
        $row[] = userdate($list->starttime);
        $row[] = $list->timeduration . ' ' . 'Minutes';
        $teachername = $DB->get_record('user', array('id' => $list->teacherid));
        if (!empty($teachername)) {
            $username = $teachername->firstname . ' ' . $teachername->lastname; // Todo-for function.
        } else {
            $username = get_string('nouser', 'mod_congrea');
        }
        $row[] = $username;
        if (!empty($list->repeattype)) {
            $row[] = 'Weekly';
        } else {
            $row[] = 'none';
        }
        $row[] = str_replace('"', '', $list->additional);
        ;
        $row[] = html_writer::link(
                        new moodle_url('/mod/congrea/sessionsettings.php',
                                array('id' => $cm->id, 'edit' => $list->id, 'sessionsettings' => $sessionsettings)),
                'Edit', array('class' => 'actionlink exportpage')
        );
        $table->data[] = $row;
    }
    if (!empty($table->data)) {
        echo html_writer::start_tag('div', array('class' => 'no-overflow'));
        echo html_writer::table($table);
        echo html_writer::end_tag('div');
    } else {
        echo 'no session';
    }
} else {
    echo 'No any sessions are available'; // Todo- by get_string.
}
if ($edit) {
    $list = $DB->get_records('congrea_sessions', array('id' => $edit));
    $days = array();
    foreach ($list as $formdata) {
        $data = new stdClass;
        $data->fromsessiondate = $formdata->starttime;
        $data->timeduration = ($formdata->timeduration * 60);
        $data->period = $formdata->repeattype;
        $data->moderatorid = $formdata->teacherid;
        $data->addmultiply = $formdata->isrepeat;
        $dayname = (explode(", ", $formdata->additional));
        foreach ($dayname as $d) {
            $key = trim($d, '"');
            $days[$key] = 1;
        }
        $data->days = $days;
        $data->moderatorid = $formdata->teacherid;
    }
    $mform->set_data($data);
}
$tablestatus = $DB->get_field('congrea_sessions', 'congreaid', array('congreaid' => $congrea->id));
if (empty($tablestatus) || $edit) {
    if (has_capability('mod/congrea:sessionesetting', $context)) {
        $mform->display();
    }
}
// Finish the page.
echo $OUTPUT->footer();
