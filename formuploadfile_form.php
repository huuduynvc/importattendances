<?php
//moodleform is defined in formslib.php
require_once("$CFG->libdir/formslib.php");

class formuploadfile_form extends moodleform
{
//Add elements to form
    public function definition()
    {
        global $CFG;
        $maxbytes = $CFG->forum_maxbytes;
        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('filepicker', 'userfile', "Thêm lịch cho các khoá học", null,
            array('maxbytes' => $maxbytes, 'accepted_types' => ['.xls','.xlsx']));
        $mform->addElement('submit', 'submitbutton', "Submit");

        function validation($data, $files)
        {
            return array();
        }
    }
}