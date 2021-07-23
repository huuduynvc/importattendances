<?php
// This file is part of
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
 * Step 1(Search form).
 *
 * @package    tool_coursearchiver
 * @copyright  2015 Matthew Davidson
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);
include 'PHPExcel/IOFactory.php';
require_once(__DIR__ . '/../../../config.php');
require_once (__DIR__.'/classes/SimpleXLSX.php');
require_once (__DIR__.'/classes/tool_import.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot.'/'.$CFG->admin.'/tool/importattendances/formuploadfile_form.php');
require_once(__DIR__ . '/../../../mod/attendance/locallib.php');
require_once(__DIR__ . '/../../../mod/attendance/classes/structure.php');


admin_externalpage_setup('toolimportattendances');
require_login();

global $SESSION;
global $DB;


$mform = new formuploadfile_form();
if ($mform->is_cancelled()) {
    echo $OUTPUT->header();
    //Handle form cancel operation, if cancel button is present on form
} else if ($fromform = $mform->get_data()) {
    //In this case you process validated data. $mform->get_data() returns data posted in form.
    if ( $xlsx = SimpleXLSX::parseData($mform->get_file_content("userfile")) ) {
        $sheets=$xlsx->sheetNames();

//        foreach($sheets as $index => $name){
//            echo "Reading sheet :".$name."<br>";
//            foreach ( $xlsx->rows($index) as $r => $row ) {
//                print_r($row)
//                echo "<br>";
//            }
//            echo "<hr>";
//        }

        foreach($sheets as $index => $name){
            foreach ( $xlsx->rows($index) as $r => $row ) {
                if (is_number($row[0])){
                    $var = $row[12];
                    $date = str_replace('/', '-', $var);
                    $enddate = DateTime::createFromFormat("d-m-Y", $date);

                    $var1 = $row[11];
                    $date1 = str_replace('/', '-', $var1);
                    $startdate = DateTime::createFromFormat("d-m-Y", $date1);

                    $formdata = new stdClass();
                    $formdata->sessiontype = 0;
                    list($cs,$room) = preg_split('/[\_-]+/', $row[8]);
                    $room_id = $DB->get_record_sql("SELECT r.id FROM {room} as r WHERE r.name = :room AND r.campus = :cs ",array('room' => $room, 'cs' => $cs));
                    if($room_id == null){
                        $formdata->room = $DB->insert_record('room',(object)array('name'=>$room,'campus'=>$cs));
                    }else{
                        $formdata->room = $room_id->id;
                    }

                    $formdata->sessiondate = $startdate->getTimestamp();

                    $lich_NVC = array(
                        0 => (object)array("starthour" => "6", "startminute" => "40", "endhour" => "7", "endminute" => "30"),
                        (object)array("starthour" => "7", "startminute" => "30", "endhour" => "8", "endminute" => "20",),
                        (object)array("starthour" => "8", "startminute" => "30", "endhour" => "9", "endminute" => "20",),
                        (object)array("starthour" => "9", "startminute" => "20", "endhour" => "10", "endminute" => "10",),
                        (object)array("starthour" => "10", "startminute" => "20", "endhour" => "11", "endminute" => "10",),
                        (object)array("starthour" => "11", "startminute" => "10", "endhour" => "12", "endminute" => "0",),
                        (object)array("starthour" => "12", "startminute" => "30", "endhour" => "13", "endminute" => "20",),
                        (object)array("starthour" => "13", "startminute" => "20", "endhour" => "14", "endminute" => "10",),
                        (object)array("starthour" => "14", "startminute" => "20", "endhour" => "15", "endminute" => "10",),
                        (object)array("starthour" => "15", "startminute" => "10", "endhour" => "16", "endminute" => "0",),
                        (object)array("starthour" => "16", "startminute" => "10", "endhour" => "17", "endminute" => "0",),
                        (object)array("starthour" => "17", "startminute" => "0", "endhour" => "17", "endminute" => "50",),
                    );

                    list($wday_lesson, $start_lesson, $end_lesson) = preg_split('/[\()-]+/', $row[7]);

                    $formdata->sestime = array(
                        "starthour" => $lich_NVC[(int)$start_lesson - 1]->starthour,
                        "startminute" => $lich_NVC[(int)$start_lesson - 1]->startminute,
                        "endhour" => $lich_NVC[(int)$end_lesson - 1]->starthour,
                        "endminute" =>$lich_NVC[(int)$end_lesson - 1]->startminute,
                    );
                    $formdata->statusset = 0;
                    $formdata->sdescription = array(
                        "text" => "",
                        "format" => "1",
                        "itemid" => 720925678
                    );
                    $formdata->calendarevent = "1";
                    $formdata->absenteereport = 1;
                    $formdata->addmultiply = "1";
                    $wdaydesc = array(0 => 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat');

                    $formdata->sdays = array(
                        $wdaydesc[(int)$wday_lesson[1]-1] => "1"
                    );

                    $formdata->period = "1";
                    $formdata->sessionenddate = $enddate->getTimestamp();
                    $formdata->coursestartdate = $startdate->getTimestamp();
                    $formdata->previoussessiondate = 0;
                    $formdata->mform_isexpanded_id_headerstudentmarking = 1;
                    $formdata->automark = "2";
                    $formdata->subnet = "";
                    $formdata->usedefaultsubnet = "1";
                    $formdata->preventsharedip = "0";
                    $formdata->preventsharediptime = 0;

                    $shortname = $row[1].'_'.$row[3];

                    $result = tool_import::add_attendance($shortname,$row[3],$row[3],NOGROUPS);

                    // Get activities in course.
                    $course = $result["course"];
                    $activities = $DB->get_recordset('attendance', array(
                        'course' => $course->id
                    ), 'id', 'id');

                    foreach ($activities as $activity) {
                        // Build the session data.
                        $cm = get_coursemodule_from_instance('attendance', $activity->id, $course->id);
                        if (!empty($cm->deletioninprogress)) {
                            // Don't do anything if this attendance is in recycle bin.
                            continue;
                        }
                        $att = new mod_attendance_structure($activity, $cm, $course);
                        $sessions = attendance_construct_sessions_data_for_add($formdata, $att);

                        if (! empty($sessions)) {
                            $att->add_sessions($sessions);
                        }
                    }
                    $activities->close();
                }
            }
        }
        echo $OUTPUT->header();
        echo "<h3>Update thành công</h3>";
    } else {
        echo $OUTPUT->header();
        echo SimpleXLSX::parseError();
        echo "<h3>Update thất bại</h3>";
    }
} else {
    echo $OUTPUT->header();
    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.

    //displays the form
    echo $mform->display();
}

echo $OUTPUT->footer();