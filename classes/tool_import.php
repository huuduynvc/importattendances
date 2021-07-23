<?php

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');

class tool_import extends external_api{
    public static function add_attendance_parameters() {
        return new external_function_parameters(
            array(
                'shortname' => new external_value(PARAM_TEXT, 'shortname course'),
                'name' => new external_value(PARAM_TEXT, 'attendance name'),
                'intro' => new external_value(PARAM_RAW, 'attendance description', VALUE_DEFAULT, ''),
                'groupmode' => new external_value(PARAM_INT,
                    'group mode (0 - no groups, 1 - separate groups, 2 - visible groups)', VALUE_DEFAULT, 0),
            )
        );
    }

    public static function add_attendance(string $shortname, $name, $intro, int $groupmode) {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/course/modlib.php');

        $params = self::validate_parameters(self::add_attendance_parameters(), array(
            'shortname' => $shortname,
            'name' => $name,
            'intro' => $intro,
            'groupmode' => $groupmode,
        ));

        // Get course.
        $course = $DB->get_record('course', array('shortname' => $params['shortname']), '*', MUST_EXIST);

        // Verify permissions.
        list($module, $context) = can_add_moduleinfo($course, 'attendance', 0);
        self::validate_context($context);
        require_capability('mod/attendance:addinstance', $context);

        // Verify group mode.
        if (!in_array($params['groupmode'], array(NOGROUPS, SEPARATEGROUPS, VISIBLEGROUPS))) {
            throw new invalid_parameter_exception('Group mode is invalid.');
        }

        // Populate modinfo object.
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'attendance';
        $moduleinfo->module = $module->id;

        //$moduleinfo->name = $params['name'];
        $moduleinfo->name = 'Attendance';
        $moduleinfo->intro = $params['intro'];
        $moduleinfo->introformat = FORMAT_HTML;

        $moduleinfo->section = 0;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = $params['groupmode'];
        $moduleinfo->groupingid = 0;

        // Add the module to the course.
        $moduleinfo = add_moduleinfo($moduleinfo, $course);

        return array('attendanceid' => $moduleinfo->instance,
            'course' => $course);
    }

    public static function validate_parameters(external_description $description, $params) {
        if ($description instanceof external_value) {
            if (is_array($params) or is_object($params)) {
                throw new invalid_parameter_exception('Scalar type expected, array or object received.');
            }

            if ($description->type == PARAM_BOOL) {
                // special case for PARAM_BOOL - we want true/false instead of the usual 1/0 - we can not be too strict here ;-)
                if (is_bool($params) or $params === 0 or $params === 1 or $params === '0' or $params === '1') {
                    return (bool)$params;
                }
            }
            $debuginfo = 'Invalid external api parameter: the value is "' . $params .
                '", the server was expecting "' . $description->type . '" type';
            return validate_param($params, $description->type, $description->allownull, $debuginfo);

        } else if ($description instanceof external_single_structure) {
            if (!is_array($params)) {
                throw new invalid_parameter_exception('Only arrays accepted. The bad value is: \''
                    . print_r($params, true) . '\'');
            }
            $result = array();
            foreach ($description->keys as $key=>$subdesc) {
                if (!array_key_exists($key, $params)) {
                    if ($subdesc->required == VALUE_REQUIRED) {
                        throw new invalid_parameter_exception('Missing required key in single structure: '. $key);
                    }
                    if ($subdesc->required == VALUE_DEFAULT) {
                        try {
                            $result[$key] = static::validate_parameters($subdesc, $subdesc->default);
                        } catch (invalid_parameter_exception $e) {
                            //we are only interested by exceptions returned by validate_param() and validate_parameters()
                            //(in order to build the path to the faulty attribut)
                            throw new invalid_parameter_exception($key." => ".$e->getMessage() . ': ' .$e->debuginfo);
                        }
                    }
                } else {
                    try {
                        $result[$key] = static::validate_parameters($subdesc, $params[$key]);
                    } catch (invalid_parameter_exception $e) {
                        //we are only interested by exceptions returned by validate_param() and validate_parameters()
                        //(in order to build the path to the faulty attribut)
                        throw new invalid_parameter_exception($key." => ".$e->getMessage() . ': ' .$e->debuginfo);
                    }
                }
                unset($params[$key]);
            }
            if (!empty($params)) {
                throw new invalid_parameter_exception('Unexpected keys (' . implode(', ', array_keys($params)) . ') detected in parameter array.');
            }
            return $result;

        } else if ($description instanceof external_multiple_structure) {
            if (!is_array($params)) {
                throw new invalid_parameter_exception('Only arrays accepted. The bad value is: \''
                    . print_r($params, true) . '\'');
            }
            $result = array();
            foreach ($params as $param) {
                $result[] = static::validate_parameters($description->content, $param);
            }
            return $result;

        } else {
            throw new invalid_parameter_exception('Invalid external api description');
        }
    }

    public static function add_session($sess) : int {
        global $DB;
        $config = get_config('attendance');

        //default in $sess
        //$sess->attendanceid ;

        $sess->automarkcompleted = 0;
        if (!isset($sess->automark)) {
            $sess->automark = 0;
        }
        if (empty($config->enablecalendar)) {
            // If calendard disabled at site level, don't use it.
            $sess->calendarevent = 0;
        }
        $sess->id = $DB->insert_record('attendance_sessions', $sess);

        $sess->caleventid = 0;
        //attendance_create_calendar_event($sess);

        $infoarray = array();

        // Trigger a session added event.
        $sess->lasttaken = 0;
        $sess->lasttakenby = 0;
        if (!isset($sess->studentscanmark)) {
            $sess->studentscanmark = 0;
        }
        if (!isset($sess->autoassignstatus)) {
            $sess->autoassignstatus = 0;
        }
        if (!isset($sess->studentpassword)) {
            $sess->studentpassword = '';
        }
        if (!isset($sess->subnet)) {
            $sess->subnet = '';
        }

        if (!isset($sess->preventsharedip)) {
            $sess->preventsharedip = 0;
        }

        if (!isset($sess->preventsharediptime)) {
            $sess->preventsharediptime = '';
        }
        if (!isset($sess->includeqrcode)) {
            $sess->includeqrcode = 0;
        }
        if (!isset($sess->rotateqrcode)) {
            $sess->rotateqrcode = 0;
            $sess->rotateqrcodesecret = '';
        }
        return $sess->id;
    }

    public static function add_session_v2($sess) : int {
        global $DB;
        $config = get_config('attendance');

        $sess->automarkcompleted = 0;
        if (!isset($sess->automark)) {
            $sess->automark = 0;
        }
        if (empty($config->enablecalendar)) {
            // If calendard disabled at site level, don't use it.
            $sess->calendarevent = 0;
        }
        $sess->id = $DB->insert_record('attendance_sessions', $sess);

        $sess->caleventid = 0;
        attendance_create_calendar_event($sess);

        $infoarray = array();
        $infoarray[] = construct_session_full_date_time($sess->sessdate, $sess->duration);

//        // Trigger a session added event.
//        $event = \mod_attendance\event\session_added::create(array(
//            'objectid' => $sess->id,
//            'other' => array('info' => implode(',', $infoarray))
//        ));

        $sess->description = 'Regular class session';
        $sess->lasttaken = 0;
        $sess->lasttakenby = 0;
        if (!isset($sess->studentscanmark)) {
            $sess->studentscanmark = 0;
        }
        if (!isset($sess->autoassignstatus)) {
            $sess->autoassignstatus = 0;
        }
        if (!isset($sess->studentpassword)) {
            $sess->studentpassword = '';
        }
        if (!isset($sess->subnet)) {
            $sess->subnet = '';
        }

        if (!isset($sess->preventsharedip)) {
            $sess->preventsharedip = 0;
        }

        if (!isset($sess->preventsharediptime)) {
            $sess->preventsharediptime = '';
        }
        if (!isset($sess->includeqrcode)) {
            $sess->includeqrcode = 0;
        }
        if (!isset($sess->rotateqrcode)) {
            $sess->rotateqrcode = 0;
            $sess->rotateqrcodesecret = '';
        }
//        $event->add_record_snapshot('attendance_sessions', $sess);

        return $sess->id;
    }

    public static function addMore($sess){
        global $CFG;
        $lichbd_NVC = array(0 => 24000,27000,30600,34200,37200,40200,45000,48000,51600,54600,58200,61200);
        $lichkt_NVC = array(0=> 27000,30000,34200,36600,40200,43200,48000,51000,54600,57600,61200,64200);
        $lichbd_LT = array(0 => 27000,30600,34200,37200,40200,45000,48000,51600,54600,58200);
        $lichkt_LT = array(0=> 30000,34200,36600,40200,43200,48000,51000,54600,57600,61200);

        list($wday_lesson, $start_lesson, $end_lesson) = preg_split('/[\()-]+/', $sess->schedule);
        //var_dump($sess->schedule);die();
        //var_dump($wday_lesson,$start_lesson,$end_lesson);die();
        $wday_startdate = getdate(($sess->startdate)->getTimestamp());
        $wday_lesson = (int)$wday_lesson[1] - 1;
        if($wday_lesson >= (int)$wday_startdate['wday']){
            $startdate = ($sess->startdate)->getTimestamp() + ($wday_lesson - (int)$wday_startdate['wday'])*DAYSECS;
        }else{
            $startdate = ($sess->startdate)->getTimestamp() + (7-((int)$wday_startdate['wday'] - $wday_lesson))*DAYSECS;
        }

        //$startdate = $sess->startdate;
        $enddate = ($sess->enddate)->getTimestamp() + DAYSECS; // Because enddate in 0:0am.

        if ($enddate < $startdate) {
            return null;
        }

        // Getting first day of week.
        $sdate = $startdate;
        $dinfo = usergetdate($sdate);
        var_dump($dinfo);die();
        if ($CFG->calendar_startwday === '0') { // Week start from sunday.
            $startweek = $startdate - $dinfo['wday'] * DAYSECS; // Call new variable.
        } else {
            $wday = $dinfo['wday'] === 0 ? 7 : $dinfo['wday'];
            $startweek = $startdate - ($wday - 1) * DAYSECS;
        }

        $wdaydesc = array(0 => 'CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7');
        while ($sdate < $enddate) {
            if ($sdate < $startweek + WEEKSECS) {
                $dinfo = usergetdate($sdate);
                if (isset($sess->sdays) && array_key_exists($wdaydesc[$dinfo['wday']], $sess->sdays)) {
                    $sess = new stdClass();
                    $sess->sessdate = $sess->startdate->getTimestamp() + + $lichbd_NVC[(int)$start_lesson - 1];
                    $sess->lasttaken = $sess->startdate->getTimestamp() + + $lichkt_NVC[(int)$end_lesson - 1];
                    //hd981
                $sdate += DAYSECS;

                }
                //self::add_session($sess);
            } else {
                $startweek += WEEKSECS;
                $sdate = $startweek;
            }

        }

    }
}