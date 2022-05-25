<?php

defined('MOODLE_INTERNAL') || die();

define('ASSIGNSUBMISSION_LIMPAF_FILEAREA', 'submissions_limpaf');

class assign_submission_limpaf extends assign_submission_plugin {
    public function get_name() {
        return get_string('limpaf', 'assignsubmission_limpaf');
    }

    public function get_settings(MoodleQuickForm $mform) {
        global $CFG, $COURSE;                                                                                                       
                                                                                                                                     
        $defaultwordlimit = $this->get_config('wordlimit') == 0 ? '' : $this->get_config('wordlimit');
        $defaultwordlimitenabled = $this->get_config('wordlimitenabled');

        $options = array('size' => '6', 'maxlength' => '6');
        $name = get_string('wordlimit', 'assignsubmission_limpaf');

        // Create a text box that can be enabled/disabled for limpaf word limit.
        $wordlimitgrp = array();
        $wordlimitgrp[] = $mform->createElement('text', 'assignsubmission_limpaf_wordlimit', '', $options);
        $wordlimitgrp[] = $mform->createElement('checkbox', 'assignsubmission_limpaf_wordlimit_enabled',
                '', get_string('enable'));
        $mform->addGroup($wordlimitgrp, 'assignsubmission_limpaf_wordlimit_group', $name, ' ', false);
        $mform->addHelpButton('assignsubmission_limpaf_wordlimit_group',
                              'wordlimit',
                              'assignsubmission_limpaf');
        $mform->disabledIf('assignsubmission_limpaf_wordlimit',
                           'assignsubmission_limpaf_wordlimit_enabled',
                           'notchecked');
        $mform->hideIf('assignsubmission_limpaf_wordlimit',
                       'assignsubmission_limpaf_enabled',
                       'notchecked');

        // Add numeric rule to text field.
        $wordlimitgrprules = array();
        $wordlimitgrprules['assignsubmission_limpaf_wordlimit'][] = array(null, 'numeric', null, 'client');
        $mform->addGroupRule('assignsubmission_limpaf_wordlimit_group', $wordlimitgrprules);

        // Rest of group setup.
        $mform->setDefault('assignsubmission_limpaf_wordlimit', $defaultwordlimit);
        $mform->setDefault('assignsubmission_limpaf_wordlimit_enabled', $defaultwordlimitenabled);
        $mform->setType('assignsubmission_limpaf_wordlimit', PARAM_INT);
        $mform->hideIf('assignsubmission_limpaf_wordlimit_group',
                       'assignsubmission_limpaf_enabled',
                       'notchecked');                            
    }

    public function save_settings(stdClass $data) {
       if (empty($data->assignsubmission_limpaf_wordlimit) || empty($data->assignsubmission_limpaf_wordlimit_enabled)) {
           $wordlimit = 0;
           $wordlimitenabled = 0;
       } else {
           $wordlimit = $data->assignsubmission_limpaf_wordlimit;
           $wordlimitenabled = 1;
       }

       $this->set_config('wordlimit', $wordlimit);
       $this->set_config('wordlimitenabled', $wordlimitenabled);

       return true;
    }

    private function get_edit_options() {
        $editoroptions = array(
            'noclean' => false,
            'maxfiles' => EDITOR_UNLIMITED_FILES,
            'maxbytes' => $this->assignment->get_course()->maxbytes,
            'context' => $this->assignment->get_context(),
            'return_types' => (FILE_INTERNAL | FILE_EXTERNAL | FILE_CONTROLLED_LINK),
            'removeorphaneddrafts' => true // Whether or not to remove any draft files which aren't referenced in the text.
        );
        return $editoroptions;
    }

    private function get_limpaf_submission($submissionid) {
        global $DB;

        return $DB->get_record('assignsubmission_limpaf', array('submission'=>$submissionid));
    }

    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        $elements = array();

        $editoroptions = $this->get_edit_options();
        $submissionid = $submission ? $submission->id : 0;

        if (!isset($data->limpaf)) {
            $data->limpaf = '';
        }
        if (!isset($data->limpafformat)) {
            $data->limpafformat = editors_get_preferred_format();
        }

        if ($submission) {
            $limpafsubmission = $this->get_limpaf_submission($submission->id);
            if ($limpafsubmission) {
                $data->limpaf = $limpafsubmission->limpaf;
                $data->limpafformat = $limpafsubmission->onlineformat;
            }

        }

        $data = file_prepare_standard_editor($data,
                                             'limpaf',
                                             $editoroptions,
                                             $this->assignment->get_context(),
                                             'assignsubmission_limpaf',
                                             ASSIGNSUBMISSION_limpaf_FILEAREA,
                                             $submissionid);
        $mform->addElement('editor', 'limpaf_editor', $this->get_name(), null, $editoroptions);

        return true;
    }

    public function save(stdClass $submission, stdClass $data) {
        global $USER, $DB;

        $editoroptions = $this->get_edit_options();

        $data = file_postupdate_standard_editor($data,
                                                'limpaf',
                                                $editoroptions,
                                                $this->assignment->get_context(),
                                                'assignsubmission_limpaf',
                                                ASSIGNSUBMISSION_limpaf_FILEAREA,
                                                $submission->id);

        $limpafsubmission = $this->get_limpaf_submission($submission->id);

        $fs = get_file_storage();

        $files = $fs->get_area_files($this->assignment->get_context()->id,
                                     'assignsubmission_limpaf',
                                     ASSIGNSUBMISSION_limpaf_FILEAREA,
                                     $submission->id,
                                     'id',
                                     false);

        // Check word count before submitting anything.
        $exceeded = $this->check_word_count(trim($data->limpaf));
        if ($exceeded) {
            $this->set_error($exceeded);
            return false;
        }

        $params = array(
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submission->id,
            'other' => array(
                'pathnamehashes' => array_keys($files),
                'content' => trim($data->limpaf),
                'format' => $data->limpaf_editor['format']
            )
        );
        if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
            $params['relateduserid'] = $submission->userid;
        }
        if ($this->assignment->is_blind_marking()) {
            $params['anonymous'] = 1;
        }
        $event = \assignsubmission_limpaf\event\assessable_uploaded::create($params);
        $event->trigger();

        $groupname = null;
        $groupid = 0;
        // Get the group name as other fields are not transcribed in the logs and this information is important.
        if (empty($submission->userid) && !empty($submission->groupid)) {
            $groupname = $DB->get_field('groups', 'name', array('id' => $submission->groupid), MUST_EXIST);
            $groupid = $submission->groupid;
        } else {
            $params['relateduserid'] = $submission->userid;
        }

        $count = count_words($data->limpaf);

        // Unset the objectid and other field from params for use in submission events.
        unset($params['objectid']);
        unset($params['other']);
        $params['other'] = array(
            'submissionid' => $submission->id,
            'submissionattempt' => $submission->attemptnumber,
            'submissionstatus' => $submission->status,
            'limpafwordcount' => $count,
            'groupid' => $groupid,
            'groupname' => $groupname
        );

        if ($limpafsubmission) {

            $limpafsubmission->limpaf = $data->limpaf;
            $limpafsubmission->onlineformat = $data->limpaf_editor['format'];
            $params['objectid'] = $limpafsubmission->id;
            $updatestatus = $DB->update_record('assignsubmission_limpaf', $limpafsubmission);
            $event = \assignsubmission_limpaf\event\submission_updated::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
            return $updatestatus;
        } else {

            $limpafsubmission = new stdClass();
            $limpafsubmission->limpaf = $data->limpaf;
            $limpafsubmission->onlineformat = $data->limpaf_editor['format'];

            $limpafsubmission->submission = $submission->id;
            $limpafsubmission->assignment = $this->assignment->get_instance()->id;
            $limpafsubmission->id = $DB->insert_record('assignsubmission_limpaf', $limpafsubmission);
            $params['objectid'] = $limpafsubmission->id;
            $event = \assignsubmission_limpaf\event\submission_created::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
            return $limpafsubmission->id > 0;
        }
    }

    public function get_files(stdClass $submission, stdClass $user) {
        global $DB;

        $files = array();
        $limpafsubmission = $this->get_limpaf_submission($submission->id);

        // Note that this check is the same logic as the result from the is_empty function but we do
        // not call it directly because we already have the submission record.
        if ($limpafsubmission) {
            // Do not pass the text through format_text. The result may not be displayed in Moodle and
            // may be passed to external services such as document conversion or portfolios.
            $formattedtext = $this->assignment->download_rewrite_pluginfile_urls($limpafsubmission->limpaf, $user, $this);
            $head = '<head><meta charset="UTF-8"></head>';
            $submissioncontent = '<!DOCTYPE html><html>' . $head . '<body>'. $formattedtext . '</body></html>';

            $filename = get_string('limpaffilename', 'assignsubmission_limpaf');
            $files[$filename] = array($submissioncontent);

            $fs = get_file_storage();

            $fsfiles = $fs->get_area_files($this->assignment->get_context()->id,
                                           'assignsubmission_limpaf',
                                           ASSIGNSUBMISSION_limpaf_FILEAREA,
                                           $submission->id,
                                           'timemodified',
                                           false);

            foreach ($fsfiles as $file) {
                $files[$file->get_filename()] = $file;
            }
        }

        return $files;
    }

    public function view_summary(stdClass $submission, & $showviewlink) {
        global $CFG;

        $limpafsubmission = $this->get_limpaf_submission($submission->id);
        // Always show the view link.
        $showviewlink = true;

        if ($limpafsubmission) {
            // This contains the shortened version of the text plus an optional 'Export to portfolio' button.
            $text = $this->assignment->render_editor_content(ASSIGNSUBMISSION_limpaf_FILEAREA,
                                                             $limpafsubmission->submission,
                                                             $this->get_type(),
                                                             'limpaf',
                                                             'assignsubmission_limpaf', true);

            // The actual submission text.
            $limpaf = trim($limpafsubmission->limpaf);
            // The shortened version of the submission text.
            $shorttext = shorten_text($limpaf, 140);

            $plagiarismlinks = '';

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir . '/plagiarismlib.php');

                $plagiarismlinks .= plagiarism_get_links(array('userid' => $submission->userid,
                    'content' => $limpaf,
                    'cmid' => $this->assignment->get_course_module()->id,
                    'course' => $this->assignment->get_course()->id,
                    'assignment' => $submission->assignment));
            }
            // We compare the actual text submission and the shortened version. If they are not equal, we show the word count.
            if ($limpaf != $shorttext) {
                $wordcount = get_string('numwords', 'assignsubmission_limpaf', count_words($limpaf));

                return $plagiarismlinks . $wordcount . $text;
            } else {
                return $plagiarismlinks . $text;
            }
        }
        return '';
    }

    public function view(stdClass $submission) {
        global $CFG;
        $result = '';

        $limpafsubmission = $this->get_limpaf_submission($submission->id);

        if ($limpafsubmission) {

            // Render for portfolio API.
            $result .= $this->assignment->render_editor_content(ASSIGNSUBMISSION_limpaf_FILEAREA,
                                                                $limpafsubmission->submission,
                                                                $this->get_type(),
                                                                'limpaf',
                                                                'assignsubmission_limpaf');

            $plagiarismlinks = '';

            if (!empty($CFG->enableplagiarism)) {
                require_once($CFG->libdir . '/plagiarismlib.php');

                $plagiarismlinks .= plagiarism_get_links(array('userid' => $submission->userid,
                    'content' => trim($limpafsubmission->limpaf),
                    'cmid' => $this->assignment->get_course_module()->id,
                    'course' => $this->assignment->get_course()->id,
                    'assignment' => $submission->assignment));
            }
        }

        return $plagiarismlinks . $result;
    }

    public function can_upgrade($type, $version) {
        if ($type == 'online' && $version >= 2011112900) {
            return true;
        }
        return false;
    }

    public function upgrade_settings(context $oldcontext, stdClass $oldassignment, & $log) {
        // No settings to upgrade.
        return true;
    }
    
    public function upgrade(context $oldcontext,
                            stdClass $oldassignment,
                            stdClass $oldsubmission,
                            stdClass $submission,
                            & $log) {
        global $DB;

        $limpafsubmission = new stdClass();
        $limpafsubmission->limpaf = $oldsubmission->data1;
        $limpafsubmission->onlineformat = $oldsubmission->data2;

        $limpafsubmission->submission = $submission->id;
        $limpafsubmission->assignment = $this->assignment->get_instance()->id;

        if ($limpafsubmission->limpaf === null) {
            $limpafsubmission->limpaf = '';
        }

        if ($limpafsubmission->onlineformat === null) {
            $limpafsubmission->onlineformat = editors_get_preferred_format();
        }

        if (!$DB->insert_record('assignsubmission_limpaf', $limpafsubmission) > 0) {
            $log .= get_string('couldnotconvertsubmission', 'mod_assign', $submission->userid);
            return false;
        }

        // Now copy the area files.
        $this->assignment->copy_area_files_for_upgrade($oldcontext->id,
                                                        'mod_assignment',
                                                        'submission',
                                                        $oldsubmission->id,
                                                        $this->assignment->get_context()->id,
                                                        'assignsubmission_limpaf',
                                                        ASSIGNSUBMISSION_limpaf_FILEAREA,
                                                        $submission->id);
        return true;
    }   

    public function get_editor_fields() {
        return array('limpaf' => get_string('pluginname', 'assignsubmission_limpaf'));
    }

    public function get_editor_text($name, $submissionid) {
        if ($name == 'limpaf') {
            $limpafsubmission = $this->get_limpaf_submission($submissionid);
            if ($limpafsubmission) {
                return $limpafsubmission->limpaf;
            }
        }

        return '';
    }

    public function get_editor_format($name, $submissionid) {
        if ($name == 'limpaf') {
            $limpafsubmission = $this->get_limpaf_submission($submissionid);
            if ($limpafsubmission) {
                return $limpafsubmission->onlineformat;
            }
        }

        return 0;
    }

    public function is_empty(stdClass $submission) {
        $limpafsubmission = $this->get_limpaf_submission($submission->id);
        $wordcount = 0;
        $hasinsertedresources = false;

        if (isset($limpafsubmission->limpaf)) {
            $wordcount = count_words(trim($limpafsubmission->limpaf));
            // Check if the online text submission contains video, audio or image elements
            // that can be ignored and stripped by count_words().
            $hasinsertedresources = preg_match('/<\s*((video|audio)[^>]*>(.*?)<\s*\/\s*(video|audio)>)|(img[^>]*>(.*?))/',
                    trim($limpafsubmission->limpaf));
        }

        return $wordcount == 0 && !$hasinsertedresources;
    }

    public function submission_is_empty(stdClass $data) {
        if (!isset($data->limpaf_editor)) {
            return true;
        }
        $wordcount = 0;
        $hasinsertedresources = false;

        if (isset($data->limpaf_editor['text'])) {
            $wordcount = count_words(trim((string)$data->limpaf_editor['text']));
            // Check if the online text submission contains video, audio or image elements
            // that can be ignored and stripped by count_words().
            $hasinsertedresources = preg_match('/<\s*((video|audio)[^>]*>(.*?)<\s*\/\s*(video|audio)>)|(img[^>]*>(.*?))/',
                    trim((string)$data->limpaf_editor['text']));
        }

        return $wordcount == 0 && !$hasinsertedresources;
    }

    public function get_file_areas() {
        return array(ASSIGNSUBMISSION_limpaf_FILEAREA=>$this->get_name());
    }

    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        global $DB;

        // Copy the files across (attached via the text editor).
        $contextid = $this->assignment->get_context()->id;
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, 'assignsubmission_limpaf',
                                     ASSIGNSUBMISSION_limpaf_FILEAREA, $sourcesubmission->id, 'id', false);
        foreach ($files as $file) {
            $fieldupdates = array('itemid' => $destsubmission->id);
            $fs->create_file_from_storedfile($fieldupdates, $file);
        }

        // Copy the assignsubmission_limpaf record.
        $limpafsubmission = $this->get_limpaf_submission($sourcesubmission->id);
        if ($limpafsubmission) {
            unset($limpafsubmission->id);
            $limpafsubmission->submission = $destsubmission->id;
            $DB->insert_record('assignsubmission_limpaf', $limpafsubmission);
        }
        return true;
    }

    public function format_for_log(stdClass $submission) {
        // Format the info for each submission plugin (will be logged).
        $limpafsubmission = $this->get_limpaf_submission($submission->id);
        $limpafloginfo = '';
        $limpafloginfo .= get_string('numwordsforlog',
                                         'assignsubmission_limpaf',
                                         count_words($limpafsubmission->limpaf));

        return $limpafloginfo;
    }
    
    public function delete_instance() {
        global $DB;
        $DB->delete_records('assignsubmission_limpaf',
                            array('assignment'=>$this->assignment->get_instance()->id));

        return true;
    }

    public function check_word_count($submissiontext) {
        global $OUTPUT;

        $wordlimitenabled = $this->get_config('wordlimitenabled');
        $wordlimit = $this->get_config('wordlimit');

        if ($wordlimitenabled == 0) {
            return null;
        }

        // Count words and compare to limit.
        $wordcount = count_words($submissiontext);
        if ($wordcount <= $wordlimit) {
            return null;
        } else {
            $errormsg = get_string('wordlimitexceeded', 'assignsubmission_onlinetext',
                    array('limit' => $wordlimit, 'count' => $wordcount));
            return $OUTPUT->error_text($errormsg);
        }
    }
}