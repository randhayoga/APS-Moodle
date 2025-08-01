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
 * Back-end code for handling data about quizzes and the current user's attempt.
 *
 * There are classes for loading all the information about a quiz and attempts,
 * and for displaying the navigation panel.
 *
 * @package   mod_quiz
 * @copyright 2008 onwards Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_question\local\bank\question_version_status;
use mod_quiz\question\bank\qbank_helper;


/**
 * Class for quiz exceptions. Just saves a couple of arguments on the
 * constructor for a moodle_exception.
 *
 * @copyright 2008 Tim Hunt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since     Moodle 2.0
 */
class moodle_quiz_exception extends moodle_exception {
    /**
     * Constructor.
     *
     * @param quiz $quizobj the quiz the error relates to.
     * @param string $errorcode The name of the string from error.php to print.
     * @param mixed $a Extra words and phrases that might be required in the error string.
     * @param string $link The url where the user will be prompted to continue.
     *      If no url is provided the user will be directed to the site index page.
     * @param string|null $debuginfo optional debugging information.
     */
    public function __construct($quizobj, $errorcode, $a = null, $link = '', $debuginfo = null) {
        if (!$link) {
            $link = $quizobj->view_url();
        }
        parent::__construct($errorcode, 'quiz', $link, $a, $debuginfo);
    }
}


/**
 * A class encapsulating a quiz and the questions it contains, and making the
 * information available to scripts like view.php.
 *
 * Initially, it only loads a minimal amout of information about each question - loading
 * extra information only when necessary or when asked. The class tracks which questions
 * are loaded.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class quiz {
    /** @var stdClass the course settings from the database. */
    protected $course;
    /** @var stdClass the course_module settings from the database. */
    protected $cm;
    /** @var stdClass the quiz settings from the database. */
    protected $quiz;
    /** @var context the quiz context. */
    protected $context;

    /**
     * @var stdClass[] of questions augmented with slot information. For non-random
     *     questions, the array key is question id. For random quesions it is 's' . $slotid.
     *     probalby best to use ->questionid field of the object instead.
     */
    protected $questions = null;
    /** @var stdClass[] of quiz_section rows. */
    protected $sections = null;
    /** @var quiz_access_manager the access manager for this quiz. */
    protected $accessmanager = null;
    /** @var bool whether the current user has capability mod/quiz:preview. */
    protected $ispreviewuser = null;

    // Constructor =============================================================
    /**
     * Constructor, assuming we already have the necessary data loaded.
     *
     * @param object $quiz the row from the quiz table.
     * @param object $cm the course_module object for this quiz.
     * @param object $course the row from the course table for the course we belong to.
     * @param bool $getcontext intended for testing - stops the constructor getting the context.
     */
    public function __construct($quiz, $cm, $course, $getcontext = true) {
        $this->quiz = $quiz;
        $this->cm = $cm;
        $this->quiz->cmid = $this->cm->id;
        $this->course = $course;
        if ($getcontext && !empty($cm->id)) {
            $this->context = context_module::instance($cm->id);
        }
    }

    /**
     * Static function to create a new quiz object for a specific user.
     *
     * @param int $quizid the the quiz id.
     * @param int|null $userid the the userid (optional). If passed, relevant overrides are applied.
     * @return quiz the new quiz object.
     */
    public static function create($quizid, $userid = null) {
        global $DB;

        $quiz = quiz_access_manager::load_quiz_and_settings($quizid);
        $course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        // Update quiz with override information.
        if ($userid) {
            $quiz = quiz_update_effective_access($quiz, $userid);
        }

        return new quiz($quiz, $cm, $course);
    }

    /**
     * Create a {@link quiz_attempt} for an attempt at this quiz.
     *
     * @param object $attemptdata row from the quiz_attempts table.
     * @return quiz_attempt the new quiz_attempt object.
     */
    public function create_attempt_object($attemptdata) {
        return new quiz_attempt($attemptdata, $this->quiz, $this->cm, $this->course);
    }

    // Functions for loading more data =========================================

    /**
     * Load just basic information about all the questions in this quiz.
     */
    public function preload_questions() {
        $slots = qbank_helper::get_question_structure($this->quiz->id, $this->context);
        $this->questions = [];
        foreach ($slots as $slot) {
            $this->questions[$slot->questionid] = $slot;
        }
    }

    /**
     * Fully load some or all of the questions for this quiz. You must call
     * {@link preload_questions()} first.
     *
     * @param array|null $deprecated no longer supported (it was not used).
     */
    public function load_questions($deprecated = null) {
        if ($deprecated !== null) {
            debugging('The argument to quiz::load_questions is no longer supported. ' .
                    'All questions are always loaded.', DEBUG_DEVELOPER);
        }
        if ($this->questions === null) {
            throw new coding_exception('You must call preload_questions before calling load_questions.');
        }

        $questionstoprocess = [];
        foreach ($this->questions as $question) {
            if (is_number($question->questionid)) {
                $question->id = $question->questionid;
                $questionstoprocess[$question->questionid] = $question;
            }
        }
        get_question_options($questionstoprocess);
    }

    /**
     * Get an instance of the {@link \mod_quiz\structure} class for this quiz.
     * @return \mod_quiz\structure describes the questions in the quiz.
     */
    public function get_structure() {
        return \mod_quiz\structure::create_for_quiz($this);
    }

    // Simple getters ==========================================================
    /**
     * Get the id of the course this quiz belongs to.
     *
     * @return int the course id.
     */
    public function get_courseid() {
        return $this->course->id;
    }

    /**
     * Get the course settings object that this quiz belongs to.
     *
     * @return object the row of the course table.
     */
    public function get_course() {
        return $this->course;
    }

    /**
     * Get this quiz's id (in the quiz table).
     *
     * @return int the quiz id.
     */
    public function get_quizid() {
        return $this->quiz->id;
    }

    /**
     * Get the quiz settings object.
     *
     * @return stdClass the row of the quiz table.
     */
    public function get_quiz() {
        return $this->quiz;
    }

    /**
     * Get the quiz name.
     *
     * @return string the name of this quiz.
     */
    public function get_quiz_name() {
        return $this->quiz->name;
    }

    /**
     * Get the navigation method in use.
     *
     * @return int QUIZ_NAVMETHOD_FREE or QUIZ_NAVMETHOD_SEQ.
     */
    public function get_navigation_method() {
        return $this->quiz->navmethod;
    }

    /** @return int the number of attempts allowed at this quiz (0 = infinite). */
    public function get_num_attempts_allowed() {
        return $this->quiz->attempts;
    }

    /**
     * Get the course-module id for this quiz.
     *
     * @return int the course_module id.
     */
    public function get_cmid() {
        return $this->cm->id;
    }

    /**
     * Get the course-module object for this quiz.
     *
     * @return object the course_module object.
     */
    public function get_cm() {
        return $this->cm;
    }

    /**
     * Get the quiz context.
     *
     * @return context_module the module context for this quiz.
     */
    public function get_context() {
        return $this->context;
    }

    /**
     * @return bool whether the current user is someone who previews the quiz,
     * rather than attempting it.
     */
    public function is_preview_user() {
        if (is_null($this->ispreviewuser)) {
            $this->ispreviewuser = has_capability('mod/quiz:preview', $this->context);
        }
        return $this->ispreviewuser;
    }

    /**
     * Checks user enrollment in the current course.
     *
     * @param int $userid the id of the user to check.
     * @return bool whether the user is enrolled.
     */
    public function is_participant($userid) {
        return is_enrolled($this->get_context(), $userid, 'mod/quiz:attempt', $this->show_only_active_users());
    }

    /**
     * Check is only active users in course should be shown.
     *
     * @return bool true if only active users should be shown.
     */
    public function show_only_active_users() {
        return !has_capability('moodle/course:viewsuspendedusers', $this->get_context());
    }

    /**
     * @return bool whether any questions have been added to this quiz.
     */
    public function has_questions() {
        if ($this->questions === null) {
            $this->preload_questions();
        }
        return !empty($this->questions);
    }

    /**
     * @param int $id the question id.
     * @return stdClass the question object with that id.
     */
    public function get_question($id) {
        return $this->questions[$id];
    }

    /**
     * @param array|null $questionids question ids of the questions to load. null for all.
     * @return stdClass[] the question data objects.
     */
    public function get_questions($questionids = null) {
        if (is_null($questionids)) {
            $questionids = array_keys($this->questions);
        }
        $questions = array();
        foreach ($questionids as $id) {
            if (!array_key_exists($id, $this->questions)) {
                throw new moodle_exception('cannotstartmissingquestion', 'quiz', $this->view_url());
            }
            $questions[$id] = $this->questions[$id];
            $this->ensure_question_loaded($id);
        }
        return $questions;
    }

    /**
     * Get all the sections in this quiz.
     *
     * @return array 0, 1, 2, ... => quiz_sections row from the database.
     */
    public function get_sections() {
        global $DB;
        if ($this->sections === null) {
            $this->sections = array_values($DB->get_records('quiz_sections',
                    array('quizid' => $this->get_quizid()), 'firstslot'));
        }
        return $this->sections;
    }

    /**
     * Return quiz_access_manager and instance of the quiz_access_manager class
     * for this quiz at this time.
     *
     * @param int $timenow the current time as a unix timestamp.
     * @return quiz_access_manager and instance of the quiz_access_manager class
     *      for this quiz at this time.
     */
    public function get_access_manager($timenow) {
        if (is_null($this->accessmanager)) {
            $this->accessmanager = new quiz_access_manager($this, $timenow,
                    has_capability('mod/quiz:ignoretimelimits', $this->context, null, false));
        }
        return $this->accessmanager;
    }

    /**
     * Wrapper round the has_capability funciton that automatically passes in the quiz context.
     *
     * @param string $capability the name of the capability to check. For example mod/quiz:view.
     * @param int|null $userid A user id. By default (null) checks the permissions of the current user.
     * @param bool $doanything If false, ignore effect of admin role assignment.
     * @return boolean true if the user has this capability. Otherwise false.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return has_capability($capability, $this->context, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability function that automatically passes in the quiz context.
     *
     * @param string $capability the name of the capability to check. For example mod/quiz:view.
     * @param int|null $userid A user id. By default (null) checks the permissions of the current user.
     * @param bool $doanything If false, ignore effect of admin role assignment.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        require_capability($capability, $this->context, $userid, $doanything);
    }

    // URLs related to this attempt ============================================
    /**
     * @return string the URL of this quiz's view page.
     */
    public function view_url() {
        global $CFG;
        return $CFG->wwwroot . '/mod/quiz/view.php?id=' . $this->cm->id;
    }

    /**
     * @return string the URL of this quiz's edit page.
     */
    public function edit_url() {
        global $CFG;
        return $CFG->wwwroot . '/mod/quiz/edit.php?cmid=' . $this->cm->id;
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @param int $page optional page number to go to in the attempt.
     * @return string the URL of that attempt.
     */
    public function attempt_url($attemptid, $page = 0) {
        global $CFG;
        $url = $CFG->wwwroot . '/mod/quiz/attempt.php?attempt=' . $attemptid;
        if ($page) {
            $url .= '&page=' . $page;
        }
        $url .= '&cmid=' . $this->get_cmid();
        return $url;
    }

    /**
     * Get the URL to start/continue an attempt.
     *
     * @param int $page page in the attempt to start on (optional).
     * @return moodle_url the URL of this quiz's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($page = 0) {
        $params = array('cmid' => $this->cm->id, 'sesskey' => sesskey());
        if ($page) {
            $params['page'] = $page;
        }
        return new moodle_url('/mod/quiz/startattempt.php', $params);
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @return string the URL of the review of that attempt.
     */
    public function review_url($attemptid) {
        return new moodle_url('/mod/quiz/review.php', array('attempt' => $attemptid, 'cmid' => $this->get_cmid()));
    }

    /**
     * @param int $attemptid the id of an attempt.
     * @return string the URL of the review of that attempt.
     */
    public function summary_url($attemptid) {
        return new moodle_url('/mod/quiz/summary.php', array('attempt' => $attemptid, 'cmid' => $this->get_cmid()));
    }

    // Bits of content =========================================================

    /**
     * @param bool $notused not used.
     * @return string an empty string.
     * @deprecated since 3.1. This sort of functionality is now entirely handled by quiz access rules.
     */
    public function confirm_start_attempt_message($notused) {
        debugging('confirm_start_attempt_message is deprecated. ' .
                'This sort of functionality is now entirely handled by quiz access rules.');
        return '';
    }

    /**
     * If $reviewoptions->attempt is false, meaning that students can't review this
     * attempt at the moment, return an appropriate string explaining why.
     *
     * @param int $when One of the mod_quiz_display_options::DURING,
     *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
     * @param bool $short if true, return a shorter string.
     * @return string an appropraite message.
     */
    public function cannot_review_message($when, $short = false) {

        if ($short) {
            $langstrsuffix = 'short';
            $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        } else {
            $langstrsuffix = '';
            $dateformat = '';
        }

        if ($when == mod_quiz_display_options::DURING ||
                $when == mod_quiz_display_options::IMMEDIATELY_AFTER) {
            return '';
        } else if ($when == mod_quiz_display_options::LATER_WHILE_OPEN && $this->quiz->timeclose &&
                $this->quiz->reviewattempt & mod_quiz_display_options::AFTER_CLOSE) {
            return get_string('noreviewuntil' . $langstrsuffix, 'quiz',
                    userdate($this->quiz->timeclose, $dateformat));
        } else {
            return get_string('noreview' . $langstrsuffix, 'quiz');
        }
    }

    /**
     * Probably not used any more, but left for backwards compatibility.
     *
     * @param string $title the name of this particular quiz page.
     * @return string always returns ''.
     */
    public function navigation($title) {
        global $PAGE;
        $PAGE->navbar->add($title);
        return '';
    }

    // Private methods =========================================================
    /**
     * Check that the definition of a particular question is loaded, and if not throw an exception.
     *
     * @param int $id a question id.
     */
    protected function ensure_question_loaded($id) {
        if (isset($this->questions[$id]->_partiallyloaded)) {
            throw new moodle_quiz_exception($this, 'questionnotloaded', $id);
        }
    }

    /**
     * Return all the question types used in this quiz.
     *
     * @param  boolean $includepotential if the quiz include random questions,
     *      setting this flag to true will make the function to return all the
     *      possible question types in the random questions category.
     * @return array a sorted array including the different question types.
     * @since  Moodle 3.1
     */
    public function get_all_question_types_used($includepotential = false) {
        $questiontypes = array();

        // To control if we need to look in categories for questions.
        $qcategories = array();

        foreach ($this->get_questions() as $questiondata) {
            if ($questiondata->status == question_version_status::QUESTION_STATUS_DRAFT) {
                // Skip questions where all versions are draft.
                continue;
            }
            if ($questiondata->qtype === 'random' && $includepotential) {
                if (!isset($qcategories[$questiondata->category])) {
                    $qcategories[$questiondata->category] = false;
                }
                if (!empty($questiondata->filtercondition)) {
                    $filtercondition = json_decode($questiondata->filtercondition);
                    $qcategories[$questiondata->category] = !empty($filtercondition->includingsubcategories);
                }
            } else {
                if (!in_array($questiondata->qtype, $questiontypes)) {
                    $questiontypes[] = $questiondata->qtype;
                }
            }
        }

        if (!empty($qcategories)) {
            // We have to look for all the question types in these categories.
            $categoriestolook = array();
            foreach ($qcategories as $cat => $includesubcats) {
                if ($includesubcats) {
                    $categoriestolook = array_merge($categoriestolook, question_categorylist($cat));
                } else {
                    $categoriestolook[] = $cat;
                }
            }
            $questiontypesincategories = question_bank::get_all_question_types_in_categories($categoriestolook);
            $questiontypes = array_merge($questiontypes, $questiontypesincategories);
        }
        $questiontypes = array_unique($questiontypes);
        sort($questiontypes);

        return $questiontypes;
    }
}


/**
 * This class extends the quiz class to hold data about the state of a particular attempt,
 * in addition to the data about the quiz.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class quiz_attempt {

    /** @var string to identify the in progress state. */
    const IN_PROGRESS = 'inprogress';
    /** @var string to identify the overdue state. */
    const OVERDUE     = 'overdue';
    /** @var string to identify the finished state. */
    const FINISHED    = 'finished';
    /** @var string to identify the abandoned state. */
    const ABANDONED   = 'abandoned';

    /** @var int maximum number of slots in the quiz for the review page to default to show all. */
    const MAX_SLOTS_FOR_DEFAULT_REVIEW_SHOW_ALL = 50;

    /** @var quiz object containing the quiz settings. */
    protected $quizobj;

    /** @var stdClass the quiz_attempts row. */
    protected $attempt;

    /** @var question_usage_by_activity the question usage for this quiz attempt. */
    protected $quba;

    /**
     * @var array of slot information. These objects contain ->slot (int),
     *      ->requireprevious (bool), ->questionids (int) the original question for random questions,
     *      ->firstinsection (bool), ->section (stdClass from $this->sections).
     *      This does not contain page - get that from {@link get_question_page()} -
     *      or maxmark - get that from $this->quba.
     */
    protected $slots;

    /** @var array of quiz_sections rows, with a ->lastslot field added. */
    protected $sections;

    /** @var array page no => array of slot numbers on the page in order. */
    protected $pagelayout;

    /** @var array slot => displayed question number for this slot. (E.g. 1, 2, 3 or 'i'.) */
    protected $questionnumbers;

    /** @var array slot => page number for this slot. */
    protected $questionpages;

    /** @var mod_quiz_display_options cache for the appropriate review options. */
    protected $reviewoptions = null;

    // Constructor =============================================================
    /**
     * Constructor assuming we already have the necessary data loaded.
     *
     * @param object $attempt the row of the quiz_attempts table.
     * @param object $quiz the quiz object for this attempt and user.
     * @param object $cm the course_module object for this quiz.
     * @param object $course the row from the course table for the course we belong to.
     * @param bool $loadquestions (optional) if true, the default, load all the details
     *      of the state of each question. Else just set up the basic details of the attempt.
     */
    public function __construct($attempt, $quiz, $cm, $course, $loadquestions = true) {
        $this->attempt = $attempt;
        $this->quizobj = new quiz($quiz, $cm, $course);

        if ($loadquestions) {
            $this->load_questions();
        }
    }

    /**
     * Used by {create()} and {create_from_usage_id()}.
     *
     * @param array $conditions passed to $DB->get_record('quiz_attempts', $conditions).
     * @return quiz_attempt the desired instance of this class.
     */
    protected static function create_helper($conditions) {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', $conditions, '*', MUST_EXIST);
        $quiz = quiz_access_manager::load_quiz_and_settings($attempt->quiz);
        $course = $DB->get_record('course', array('id' => $quiz->course), '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        // Update quiz with override information.
        $quiz = quiz_update_effective_access($quiz, $attempt->userid);

        return new quiz_attempt($attempt, $quiz, $cm, $course);
    }

    /**
     * Static function to create a new quiz_attempt object given an attemptid.
     *
     * @param int $attemptid the attempt id.
     * @return quiz_attempt the new quiz_attempt object
     */
    public static function create($attemptid) {
        return self::create_helper(array('id' => $attemptid));
    }

    /**
     * Static function to create a new quiz_attempt object given a usage id.
     *
     * @param int $usageid the attempt usage id.
     * @return quiz_attempt the new quiz_attempt object
     */
    public static function create_from_usage_id($usageid) {
        return self::create_helper(array('uniqueid' => $usageid));
    }

    /**
     * @param string $state one of the state constants like IN_PROGRESS.
     * @return string the human-readable state name.
     */
    public static function state_name($state) {
        return quiz_attempt_state_name($state);
    }

    /**
     * This method can be called later if the object was constructed with $loadqusetions = false.
     */
    public function load_questions() {
        global $DB;

        if (isset($this->quba)) {
            throw new coding_exception('This quiz attempt has already had the questions loaded.');
        }

        $this->quba = question_engine::load_questions_usage_by_activity($this->attempt->uniqueid);
        $this->slots = $DB->get_records('quiz_slots',
                array('quizid' => $this->get_quizid()), 'slot', 'slot, id, requireprevious');
        $this->sections = array_values($DB->get_records('quiz_sections',
                array('quizid' => $this->get_quizid()), 'firstslot'));

        $this->link_sections_and_slots();
        $this->determine_layout();
        $this->number_questions();
    }

    /**
     * Preload all attempt step users to show in Response history.
     *
     * @throws dml_exception
     */
    public function preload_all_attempt_step_users(): void {
        $this->quba->preload_all_step_users();
    }

    /**
     * Let each slot know which section it is part of.
     */
    protected function link_sections_and_slots() {
        foreach ($this->sections as $i => $section) {
            if (isset($this->sections[$i + 1])) {
                $section->lastslot = $this->sections[$i + 1]->firstslot - 1;
            } else {
                $section->lastslot = count($this->slots);
            }
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $this->slots[$slot]->section = $section;
            }
        }
    }

    /**
     * Parse attempt->layout to populate the other arrays the represent the layout.
     */
    protected function determine_layout() {
        $this->pagelayout = array();

        // Break up the layout string into pages.
        $pagelayouts = explode(',0', $this->attempt->layout);

        // Strip off any empty last page (normally there is one).
        if (end($pagelayouts) == '') {
            array_pop($pagelayouts);
        }

        // File the ids into the arrays.
        // Tracking which is the first slot in each section in this attempt is
        // trickier than you might guess, since the slots in this section
        // may be shuffled, so $section->firstslot (the lowest numbered slot in
        // the section) may not be the first one.
        $unseensections = $this->sections;
        $this->pagelayout = array();
        foreach ($pagelayouts as $page => $pagelayout) {
            $pagelayout = trim($pagelayout, ',');
            if ($pagelayout == '') {
                continue;
            }
            $this->pagelayout[$page] = explode(',', $pagelayout);
            foreach ($this->pagelayout[$page] as $slot) {
                $sectionkey = array_search($this->slots[$slot]->section, $unseensections);
                if ($sectionkey !== false) {
                    $this->slots[$slot]->firstinsection = true;
                    unset($unseensections[$sectionkey]);
                } else {
                    $this->slots[$slot]->firstinsection = false;
                }
            }
        }
    }

    /**
     * Work out the number to display for each question/slot.
     */
    protected function number_questions() {
        $number = 1;
        foreach ($this->pagelayout as $page => $slots) {
            foreach ($slots as $slot) {
                if ($length = $this->is_real_question($slot)) {
                    $this->questionnumbers[$slot] = $number;
                    $number += $length;
                } else {
                    $this->questionnumbers[$slot] = get_string('infoshort', 'quiz');
                }
                $this->questionpages[$slot] = $page;
            }
        }
    }

    /**
     * If the given page number is out of range (before the first page, or after
     * the last page, chnage it to be within range).
     *
     * @param int $page the requested page number.
     * @return int a safe page number to use.
     */
    public function force_page_number_into_range($page) {
        return min(max($page, 0), count($this->pagelayout) - 1);
    }

    // Simple getters ==========================================================
    public function get_quiz() {
        return $this->quizobj->get_quiz();
    }

    public function get_quizobj() {
        return $this->quizobj;
    }

    /** @return int the course id. */
    public function get_courseid() {
        return $this->quizobj->get_courseid();
    }

    /**
     * Get the course settings object.
     *
     * @return stdClass the course settings object.
     */
    public function get_course() {
        return $this->quizobj->get_course();
    }

    /** @return int the quiz id. */
    public function get_quizid() {
        return $this->quizobj->get_quizid();
    }

    /** @return string the name of this quiz. */
    public function get_quiz_name() {
        return $this->quizobj->get_quiz_name();
    }

    /** @return int the quiz navigation method. */
    public function get_navigation_method() {
        return $this->quizobj->get_navigation_method();
    }

    /** @return object the course_module object. */
    public function get_cm() {
        return $this->quizobj->get_cm();
    }

    /**
     * Get the course-module id.
     *
     * @return int the course_module id.
     */
    public function get_cmid() {
        return $this->quizobj->get_cmid();
    }

    /**
     * @return bool whether the current user is someone who previews the quiz,
     * rather than attempting it.
     */
    public function is_preview_user() {
        return $this->quizobj->is_preview_user();
    }

    /** @return int the number of attempts allowed at this quiz (0 = infinite). */
    public function get_num_attempts_allowed() {
        return $this->quizobj->get_num_attempts_allowed();
    }

    /** @return int number fo pages in this quiz. */
    public function get_num_pages() {
        return count($this->pagelayout);
    }

    /**
     * @param int $timenow the current time as a unix timestamp.
     * @return quiz_access_manager and instance of the quiz_access_manager class
     *      for this quiz at this time.
     */
    public function get_access_manager($timenow) {
        return $this->quizobj->get_access_manager($timenow);
    }

    /** @return int the attempt id. */
    public function get_attemptid() {
        return $this->attempt->id;
    }

    /** @return int the attempt unique id. */
    public function get_uniqueid() {
        return $this->attempt->uniqueid;
    }

    /** @return object the row from the quiz_attempts table. */
    public function get_attempt() {
        return $this->attempt;
    }

    /** @return int the number of this attemp (is it this user's first, second, ... attempt). */
    public function get_attempt_number() {
        return $this->attempt->attempt;
    }

    /** @return string one of the quiz_attempt::IN_PROGRESS, FINISHED, OVERDUE or ABANDONED constants. */
    public function get_state() {
        return $this->attempt->state;
    }

    /** @return int the id of the user this attempt belongs to. */
    public function get_userid() {
        return $this->attempt->userid;
    }

    /** @return int the current page of the attempt. */
    public function get_currentpage() {
        return $this->attempt->currentpage;
    }

    public function get_sum_marks() {
        return $this->attempt->sumgrades;
    }

    /**
     * @return bool whether this attempt has been finished (true) or is still
     *     in progress (false). Be warned that this is not just state == self::FINISHED,
     *     it also includes self::ABANDONED.
     */
    public function is_finished() {
        return $this->attempt->state == self::FINISHED || $this->attempt->state == self::ABANDONED;
    }

    /** @return bool whether this attempt is a preview attempt. */
    public function is_preview() {
        return $this->attempt->preview;
    }

    /**
     * Is this someone dealing with their own attempt or preview?
     *
     * @return bool true => own attempt/preview. false => reviewing someone else's.
     */
    public function is_own_attempt() {
        global $USER;
        return $this->attempt->userid == $USER->id;
    }

    /**
     * @return bool whether this attempt is a preview belonging to the current user.
     */
    public function is_own_preview() {
        return $this->is_own_attempt() &&
                $this->is_preview_user() && $this->attempt->preview;
    }

    /**
     * Is the current user allowed to review this attempt. This applies when
     * {@link is_own_attempt()} returns false.
     *
     * @return bool whether the review should be allowed.
     */
    public function is_review_allowed() {
        if (!$this->has_capability('mod/quiz:viewreports')) {
            return false;
        }

        $cm = $this->get_cm();
        if ($this->has_capability('moodle/site:accessallgroups') ||
                groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
            return true;
        }

        // Check the users have at least one group in common.
        $teachersgroups = groups_get_activity_allowed_groups($cm);
        $studentsgroups = groups_get_all_groups(
                $cm->course, $this->attempt->userid, $cm->groupingid);
        return $teachersgroups && $studentsgroups &&
                array_intersect(array_keys($teachersgroups), array_keys($studentsgroups));
    }

    /**
     * Has the student, in this attempt, engaged with the quiz in a non-trivial way?
     *
     * That is, is there any question worth a non-zero number of marks, where
     * the student has made some response that we have saved?
     *
     * @return bool true if we have saved a response for at least one graded question.
     */
    public function has_response_to_at_least_one_graded_question() {
        foreach ($this->quba->get_attempt_iterator() as $qa) {
            if ($qa->get_max_mark() == 0) {
                continue;
            }
            if ($qa->get_num_steps() > 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Do any questions in this attempt need to be graded manually?
     *
     * @return bool True if we have at least one question still needs manual grading.
     */
    public function requires_manual_grading(): bool {
        return $this->quba->get_total_mark() === null;
    }

    /**
     * Get extra summary information about this attempt.
     *
     * Some behaviours may be able to provide interesting summary information
     * about the attempt as a whole, and this method provides access to that data.
     * To see how this works, try setting a quiz to one of the CBM behaviours,
     * and then look at the extra information displayed at the top of the quiz
     * review page once you have sumitted an attempt.
     *
     * In the return value, the array keys are identifiers of the form
     * qbehaviour_behaviourname_meaningfullkey. For qbehaviour_deferredcbm_highsummary.
     * The values are arrays with two items, title and content. Each of these
     * will be either a string, or a renderable.
     *
     * @param question_display_options $options the display options for this quiz attempt at this time.
     * @return array as described above.
     */
    public function get_additional_summary_data(question_display_options $options) {
        return $this->quba->get_summary_information($options);
    }

    /**
     * Get the overall feedback corresponding to a particular mark.
     *
     * @param number $grade a particular grade.
     * @return string the feedback.
     */
    public function get_overall_feedback($grade) {
        return quiz_feedback_for_grade($grade, $this->get_quiz(),
                $this->quizobj->get_context());
    }

    /**
     * Wrapper round the has_capability funciton that automatically passes in the quiz context.
     *
     * @param string $capability the name of the capability to check. For example mod/forum:view.
     * @param int|null $userid A user id. By default (null) checks the permissions of the current user.
     * @param bool $doanything If false, ignore effect of admin role assignment.
     * @return boolean true if the user has this capability. Otherwise false.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return $this->quizobj->has_capability($capability, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability function that automatically passes in the quiz context.
     *
     * @param string $capability the name of the capability to check. For example mod/forum:view.
     * @param int|null $userid A user id. By default (null) checks the permissions of the current user.
     * @param bool $doanything If false, ignore effect of admin role assignment.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        $this->quizobj->require_capability($capability, $userid, $doanything);
    }

    /**
     * Check the appropriate capability to see whether this user may review their own attempt.
     * If not, prints an error.
     */
    public function check_review_capability() {
        if ($this->get_attempt_state() == mod_quiz_display_options::IMMEDIATELY_AFTER) {
            $capability = 'mod/quiz:attempt';
        } else {
            $capability = 'mod/quiz:reviewmyattempts';
        }

        // These next tests are in a slighly funny order. The point is that the
        // common and most performance-critical case is students attempting a quiz
        // so we want to check that permisison first.

        if ($this->has_capability($capability)) {
            // User has the permission that lets you do the quiz as a student. Fine.
            return;
        }

        if ($this->has_capability('mod/quiz:viewreports') ||
                $this->has_capability('mod/quiz:preview')) {
            // User has the permission that lets teachers review. Fine.
            return;
        }

        // They should not be here. Trigger the standard no-permission error
        // but using the name of the student capability.
        // We know this will fail. We just want the stadard exception thown.
        $this->require_capability($capability);
    }

    /**
     * Checks whether a user may navigate to a particular slot.
     *
     * @param int $slot the target slot (currently does not affect the answer).
     * @return bool true if the navigation should be allowed.
     */
    public function can_navigate_to($slot) {
        if ($this->attempt->state == self::OVERDUE) {
            // When the attempt is overdue, students can only see the
            // attempt summary page and cannot navigate anywhere else.
            return false;
        }

        switch ($this->get_navigation_method()) {
            case QUIZ_NAVMETHOD_FREE:
                return true;
                break;
            case QUIZ_NAVMETHOD_SEQ:
                return false;
                break;
        }
        return true;
    }

    /**
     * @return int one of the mod_quiz_display_options::DURING,
     *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
     */
    public function get_attempt_state() {
        return quiz_attempt_state($this->get_quiz(), $this->attempt);
    }

    /**
     * Wrapper that the correct mod_quiz_display_options for this quiz at the
     * moment.
     *
     * @param bool $reviewing true for options when reviewing, false for when attempting.
     * @return question_display_options the render options for this user on this attempt.
     */
    public function get_display_options($reviewing) {
        if ($reviewing) {
            if (is_null($this->reviewoptions)) {
                $this->reviewoptions = quiz_get_review_options($this->get_quiz(),
                        $this->attempt, $this->quizobj->get_context());
                if ($this->is_own_preview()) {
                    // It should  always be possible for a teacher to review their
                    // own preview irrespective of the review options settings.
                    $this->reviewoptions->attempt = true;
                }
            }
            return $this->reviewoptions;

        } else {
            $options = mod_quiz_display_options::make_from_quiz($this->get_quiz(),
                    mod_quiz_display_options::DURING);
            $options->flags = quiz_get_flag_option($this->attempt, $this->quizobj->get_context());
            return $options;
        }
    }

    /**
     * Wrapper that the correct mod_quiz_display_options for this quiz at the
     * moment.
     *
     * @param bool $reviewing true for review page, else attempt page.
     * @param int $slot which question is being displayed.
     * @param moodle_url $thispageurl to return to after the editing form is
     *      submitted or cancelled. If null, no edit link will be generated.
     *
     * @return question_display_options the render options for this user on this
     *      attempt, with extra info to generate an edit link, if applicable.
     */
    public function get_display_options_with_edit_link($reviewing, $slot, $thispageurl) {
        $options = clone($this->get_display_options($reviewing));

        if (!$thispageurl) {
            return $options;
        }

        if (!($reviewing || $this->is_preview())) {
            return $options;
        }

        $question = $this->quba->get_question($slot, false);
        if (!question_has_capability_on($question, 'edit', $question->category)) {
            return $options;
        }

        $options->editquestionparams['cmid'] = $this->get_cmid();
        $options->editquestionparams['returnurl'] = $thispageurl;

        return $options;
    }

    /**
     * @param int $page page number
     * @return bool true if this is the last page of the quiz.
     */
    public function is_last_page($page) {
        return $page == count($this->pagelayout) - 1;
    }

    /**
     * Return the list of slot numbers for either a given page of the quiz, or for the
     * whole quiz.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the requested list of slot numbers.
     */
    public function get_slots($page = 'all') {
        if ($page === 'all') {
            $numbers = array();
            foreach ($this->pagelayout as $numbersonpage) {
                $numbers = array_merge($numbers, $numbersonpage);
            }
            return $numbers;
        } else {
            return $this->pagelayout[$page];
        }
    }

    /**
     * Return the list of slot numbers for either a given page of the quiz, or for the
     * whole quiz.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the requested list of slot numbers.
     */
    public function get_active_slots($page = 'all') {
        $activeslots = array();
        foreach ($this->get_slots($page) as $slot) {
            if (!$this->is_blocked_by_previous_question($slot)) {
                $activeslots[] = $slot;
            }
        }
        return $activeslots;
    }

    /**
     * Helper method for unit tests. Get the underlying question usage object.
     *
     * @return question_usage_by_activity the usage.
     */
    public function get_question_usage() {
        if (!(PHPUNIT_TEST || defined('BEHAT_TEST'))) {
            throw new coding_exception('get_question_usage is only for use in unit tests. ' .
                    'For other operations, use the quiz_attempt api, or extend it properly.');
        }
        return $this->quba;
    }

    /**
     * Get the question_attempt object for a particular question in this attempt.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_attempt the requested question_attempt.
     */
    public function get_question_attempt($slot) {
        return $this->quba->get_question_attempt($slot);
    }

    /**
     * Get all the question_attempt objects that have ever appeared in a given slot.
     *
     * This relates to the 'Try another question like this one' feature.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_attempt[] the attempts.
     */
    public function all_question_attempts_originally_in_slot($slot) {
        $qas = array();
        foreach ($this->quba->get_attempt_iterator() as $qa) {
            if ($qa->get_metadata('originalslot') == $slot) {
                $qas[] = $qa;
            }
        }
        $qas[] = $this->quba->get_question_attempt($slot);
        return $qas;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return int whether that question is a real question. Actually returns the
     *     question length, which could theoretically be greater than one.
     */
    public function is_real_question($slot) {
        return $this->quba->get_question($slot, false)->length;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether that question is a real question.
     */
    public function is_question_flagged($slot) {
        return $this->quba->get_question_attempt($slot)->is_flagged();
    }

    /**
     * Checks whether the question in this slot requires the previous
     * question to have been completed.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether the previous question must have been completed before
     *      this one can be seen.
     */
    public function is_blocked_by_previous_question($slot) {
        return $slot > 1 && isset($this->slots[$slot]) && $this->slots[$slot]->requireprevious &&
            !$this->slots[$slot]->section->shufflequestions &&
            !$this->slots[$slot - 1]->section->shufflequestions &&
            $this->get_navigation_method() != QUIZ_NAVMETHOD_SEQ &&
            !$this->get_question_state($slot - 1)->is_finished() &&
            $this->quba->can_question_finish_during_attempt($slot - 1);
    }

    /**
     * Is it possible for this question to be re-started within this attempt?
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether the student should be given the option to restart this question now.
     */
    public function can_question_be_redone_now($slot) {
        return $this->get_quiz()->canredoquestions && !$this->is_finished() &&
                $this->get_question_state($slot)->is_finished();
    }

    /**
     * Given a slot in this attempt, which may or not be a redone question, return the original slot.
     *
     * @param int $slot identifies a particular question in this attempt.
     * @return int the slot where this question was originally.
     */
    public function get_original_slot($slot) {
        $originalslot = $this->quba->get_question_attempt_metadata($slot, 'originalslot');
        if ($originalslot) {
            return $originalslot;
        } else {
            return $slot;
        }
    }

    /**
     * Get the displayed question number for a slot.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the displayed question number for the question in this slot.
     *      For example '1', '2', '3' or 'i'.
     */
    public function get_question_number($slot) {
        return $this->questionnumbers[$slot];
    }

    /**
     * If the section heading, if any, that should come just before this slot.
     *
     * @param int $slot identifies a particular question in this attempt.
     * @return string the required heading, or null if there is not one here.
     */
    public function get_heading_before_slot($slot) {
        if ($this->slots[$slot]->firstinsection) {
            return $this->slots[$slot]->section->heading;
        } else {
            return null;
        }
    }

    /**
     * Return the page of the quiz where this question appears.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return int the page of the quiz this question appears on.
     */
    public function get_question_page($slot) {
        return $this->questionpages[$slot];
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the quiz.
     */
    public function get_question_name($slot) {
        return $this->quba->get_question($slot, false)->name;
    }

    /**
     * Return the {@link question_state} that this question is in.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_state the state this question is in.
     */
    public function get_question_state($slot) {
        return $this->quba->get_question_state($slot);
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     *      be distinguished.
     * @return string the formatted grade, to the number of decimal places specified
     *      by the quiz.
     */
    public function get_question_status($slot, $showcorrectness) {
        return $this->quba->get_question_state_string($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     *      be distinguished.
     * @return string class name for this state.
     */
    public function get_question_state_class($slot, $showcorrectness) {
        return $this->quba->get_question_state_class($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question.
     *
     * You must previously have called load_question_states to load the state
     * data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified by the quiz.
     */
    public function get_question_mark($slot) {
        return quiz_format_question_grade($this->get_quiz(), $this->quba->get_question_mark($slot));
    }

    /**
     * Get the time of the most recent action performed on a question.
     *
     * @param int $slot the number used to identify this question within this usage.
     * @return int timestamp.
     */
    public function get_question_action_time($slot) {
        return $this->quba->get_question_action_time($slot);
    }

    /**
     * Return the question type name for a given slot within the current attempt.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the question type name.
     * @since  Moodle 3.1
     */
    public function get_question_type_name($slot) {
        return $this->quba->get_question($slot, false)->get_type_name();
    }

    /**
     * Get the time remaining for an in-progress attempt, if the time is short
     * enough that it would be worth showing a timer.
     *
     * @param int $timenow the time to consider as 'now'.
     * @return int|false the number of seconds remaining for this attempt.
     *      False if there is no limit.
     */
    public function get_time_left_display($timenow) {
        if ($this->attempt->state != self::IN_PROGRESS) {
            return false;
        }
        return $this->get_access_manager($timenow)->get_time_left_display($this->attempt, $timenow);
    }


    /**
     * @return int the time when this attempt was submitted. 0 if it has not been
     * submitted yet.
     */
    public function get_submitted_date() {
        return $this->attempt->timefinish;
    }

    /**
     * If the attempt is in an applicable state, work out the time by which the
     * student should next do something.
     *
     * @return int timestamp by which the student needs to do something.
     */
    public function get_due_date() {
        $deadlines = array();
        if ($this->quizobj->get_quiz()->timelimit) {
            $deadlines[] = $this->attempt->timestart + $this->quizobj->get_quiz()->timelimit;
        }
        if ($this->quizobj->get_quiz()->timeclose) {
            $deadlines[] = $this->quizobj->get_quiz()->timeclose;
        }
        if ($deadlines) {
            $duedate = min($deadlines);
        } else {
            return false;
        }

        switch ($this->attempt->state) {
            case self::IN_PROGRESS:
                return $duedate;

            case self::OVERDUE:
                return $duedate + $this->quizobj->get_quiz()->graceperiod;

            default:
                throw new coding_exception('Unexpected state: ' . $this->attempt->state);
        }
    }

    // URLs related to this attempt ============================================
    /**
     * @return string quiz view url.
     */
    public function view_url() {
        return $this->quizobj->view_url();
    }

    /**
     * Get the URL to start or continue an attempt.
     *
     * @param int|null $slot which question in the attempt to go to after starting (optional).
     * @param int $page which page in the attempt to go to after starting.
     * @return string the URL of this quiz's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($slot = null, $page = -1) {
        if ($page == -1 && !is_null($slot)) {
            $page = $this->get_question_page($slot);
        } else {
            $page = 0;
        }
        return $this->quizobj->start_attempt_url($page);
    }

    /**
     * Generates the title of the attempt page.
     *
     * @param int $page the page number (starting with 0) in the attempt.
     * @return string attempt page title.
     */
    public function attempt_page_title(int $page) : string {
        if ($this->get_num_pages() > 1) {
            $a = new stdClass();
            $a->name = $this->get_quiz_name();
            $a->currentpage = $page + 1;
            $a->totalpages = $this->get_num_pages();
            $title = get_string('attempttitlepaged', 'quiz', $a);
        } else {
            $title = get_string('attempttitle', 'quiz', $this->get_quiz_name());
        }

        return $title;
    }

    /**
     * @param int|null $slot if specified, the slot number of a specific question to link to.
     * @param int $page if specified, a particular page to link to. If not given deduced
     *      from $slot, or goes to the first page.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return string the URL to continue this attempt.
     */
    public function attempt_url($slot = null, $page = -1, $thispage = -1) {
        return $this->page_and_question_url('attempt', $slot, $page, false, $thispage);
    }

    /**
     * Generates the title of the summary page.
     *
     * @return string summary page title.
     */
    public function summary_page_title() : string {
        return get_string('attemptsummarytitle', 'quiz', $this->get_quiz_name());
    }

    /**
     * @return moodle_url the URL of this quiz's summary page.
     */
    public function summary_url() {
        return new moodle_url('/mod/quiz/summary.php', array('attempt' => $this->attempt->id, 'cmid' => $this->get_cmid()));
    }

    /**
     * @return moodle_url the URL of this quiz's summary page.
     */
    public function processattempt_url() {
        return new moodle_url('/mod/quiz/processattempt.php');
    }

    /**
     * Generates the title of the review page.
     *
     * @param int $page the page number (starting with 0) in the attempt.
     * @param bool $showall whether the review page contains the entire attempt on one page.
     * @return string title of the review page.
     */
    public function review_page_title(int $page, bool $showall = false) : string {
        if (!$showall && $this->get_num_pages() > 1) {
            $a = new stdClass();
            $a->name = $this->get_quiz_name();
            $a->currentpage = $page + 1;
            $a->totalpages = $this->get_num_pages();
            $title = get_string('attemptreviewtitlepaged', 'quiz', $a);
        } else {
            $title = get_string('attemptreviewtitle', 'quiz', $this->get_quiz_name());
        }

        return $title;
    }

    /**
     * @param int|null $slot indicates which question to link to.
     * @param int $page if specified, the URL of this particular page of the attempt, otherwise
     *      the URL will go to the first page.  If -1, deduce $page from $slot.
     * @param bool|null $showall if true, the URL will be to review the entire attempt on one page,
     *      and $page will be ignored. If null, a sensible default will be chosen.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     *      this page to be output as only a fragment.
     * @return string the URL to review this attempt.
     */
    public function review_url($slot = null, $page = -1, $showall = null, $thispage = -1) {
        return $this->page_and_question_url('review', $slot, $page, $showall, $thispage);
    }

    /**
     * By default, should this script show all questions on one page for this attempt?
     *
     * @param string $script the script name, e.g. 'attempt', 'summary', 'review'.
     * @return bool whether show all on one page should be on by default.
     */
    public function get_default_show_all($script) {
        return $script === 'review' && count($this->questionpages) < self::MAX_SLOTS_FOR_DEFAULT_REVIEW_SHOW_ALL;
    }

    // Bits of content =========================================================

    /**
     * If $reviewoptions->attempt is false, meaning that students can't review this
     * attempt at the moment, return an appropriate string explaining why.
     *
     * @param bool $short if true, return a shorter string.
     * @return string an appropriate message.
     */
    public function cannot_review_message($short = false) {
        return $this->quizobj->cannot_review_message(
                $this->get_attempt_state(), $short);
    }

    /**
     * Initialise the JS etc. required all the questions on a page.
     *
     * @param int|string $page a page number, or 'all'.
     * @param bool $showall if true forces page number to all.
     * @return string HTML to output - mostly obsolete, will probably be an empty string.
     */
    public function get_html_head_contributions($page = 'all', $showall = false) {
        if ($showall) {
            $page = 'all';
        }
        $result = '';
        foreach ($this->get_slots($page) as $slot) {
            $result .= $this->quba->render_question_head_html($slot);
        }
        $result .= question_engine::initialise_js();
        return $result;
    }

    /**
     * Initialise the JS etc. required by one question.
     *
     * @param int $slot the question slot number.
     * @return string HTML to output - but this is mostly obsolete. Will probably be an empty string.
     */
    public function get_question_html_head_contributions($slot) {
        return $this->quba->render_question_head_html($slot) .
                question_engine::initialise_js();
    }

    /**
     * Print the HTML for the start new preview button, if the current user
     * is allowed to see one.
     *
     * @return string HTML for the button.
     */
    public function restart_preview_button() {
        global $OUTPUT;
        if ($this->is_preview() && $this->is_preview_user()) {
            return $OUTPUT->single_button(new moodle_url(
                    $this->start_attempt_url(), array('forcenew' => true)),
                    get_string('startnewpreview', 'quiz'));
        } else {
            return '';
        }
    }

    /**
     * Generate the HTML that displayes the question in its current state, with
     * the appropriate display options.
     *
     * @param int $slot identifies the question in the attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param mod_quiz_renderer $renderer the quiz renderer.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question($slot, $reviewing, mod_quiz_renderer $renderer, $thispageurl = null) {
        if ($this->is_blocked_by_previous_question($slot)) {
            $placeholderqa = $this->make_blocked_question_placeholder($slot);

            $displayoptions = $this->get_display_options($reviewing);
            $displayoptions->manualcomment = question_display_options::HIDDEN;
            $displayoptions->history = question_display_options::HIDDEN;
            $displayoptions->readonly = true;

            return html_writer::div($placeholderqa->render($displayoptions,
                    $this->get_question_number($this->get_original_slot($slot))),
                    'mod_quiz-blocked_question_warning');
        }

        return $this->render_question_helper($slot, $reviewing, $thispageurl, $renderer, null);
    }

    /**
     * Helper used by {@link render_question()} and {@link render_question_at_step()}.
     *
     * @param int $slot identifies the question in the attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @param mod_quiz_renderer $renderer the quiz renderer.
     * @param int|null $seq the seq number of the past state to display.
     * @return string HTML fragment.
     */
    protected function render_question_helper($slot, $reviewing, $thispageurl,
            mod_quiz_renderer $renderer, $seq) {
        $originalslot = $this->get_original_slot($slot);
        $number = $this->get_question_number($originalslot);
        $displayoptions = $this->get_display_options_with_edit_link($reviewing, $slot, $thispageurl);

        if ($slot != $originalslot) {
            $originalmaxmark = $this->get_question_attempt($slot)->get_max_mark();
            $this->get_question_attempt($slot)->set_max_mark($this->get_question_attempt($originalslot)->get_max_mark());
        }

        if ($this->can_question_be_redone_now($slot)) {
            $displayoptions->extrainfocontent = $renderer->redo_question_button(
                    $slot, $displayoptions->readonly);
        }

        if ($displayoptions->history && $displayoptions->questionreviewlink) {
            $links = $this->links_to_other_redos($slot, $displayoptions->questionreviewlink);
            if ($links) {
                $displayoptions->extrahistorycontent = html_writer::tag('p',
                        get_string('redoesofthisquestion', 'quiz', $renderer->render($links)));
            }
        }

        if ($seq === null) {
            $output = $this->quba->render_question($slot, $displayoptions, $number);
        } else {
            $output = $this->quba->render_question_at_step($slot, $seq, $displayoptions, $number);
        }

        if ($slot != $originalslot) {
            $this->get_question_attempt($slot)->set_max_mark($originalmaxmark);
        }

        return $output;
    }

    /**
     * Create a fake question to be displayed in place of a question that is blocked
     * until the previous question has been answered.
     *
     * @param int $slot int slot number of the question to replace.
     * @return question_attempt the placeholder question attempt.
     */
    protected function make_blocked_question_placeholder($slot) {
        $replacedquestion = $this->get_question_attempt($slot)->get_question(false);

        question_bank::load_question_definition_classes('description');
        $question = new qtype_description_question();
        $question->id = $replacedquestion->id;
        $question->category = null;
        $question->parent = 0;
        $question->qtype = question_bank::get_qtype('description');
        $question->name = '';
        $question->questiontext = get_string('questiondependsonprevious', 'quiz');
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '';
        $question->defaultmark = $this->quba->get_question_max_mark($slot);
        $question->length = $replacedquestion->length;
        $question->penalty = 0;
        $question->stamp = '';
        $question->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $question->timecreated = null;
        $question->timemodified = null;
        $question->createdby = null;
        $question->modifiedby = null;

        $placeholderqa = new question_attempt($question, $this->quba->get_id(),
                null, $this->quba->get_question_max_mark($slot));
        $placeholderqa->set_slot($slot);
        $placeholderqa->start($this->get_quiz()->preferredbehaviour, 1);
        $placeholderqa->set_flagged($this->is_question_flagged($slot));
        return $placeholderqa;
    }

    /**
     * Like {@link render_question()} but displays the question at the past step
     * indicated by $seq, rather than showing the latest step.
     *
     * @param int $slot the slot number of a question in this quiz attempt.
     * @param int $seq the seq number of the past state to display.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param mod_quiz_renderer $renderer the quiz renderer.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question_at_step($slot, $seq, $reviewing,
            mod_quiz_renderer $renderer, $thispageurl = null) {
        return $this->render_question_helper($slot, $reviewing, $thispageurl, $renderer, $seq);
    }

    /**
     * Wrapper round print_question from lib/questionlib.php.
     *
     * @param int $slot the id of a question in this quiz attempt.
     * @return string HTML of the question.
     */
    public function render_question_for_commenting($slot) {
        $options = $this->get_display_options(true);
        $options->generalfeedback = question_display_options::HIDDEN;
        $options->manualcomment = question_display_options::EDITABLE;
        return $this->quba->render_question($slot, $options,
                $this->get_question_number($slot));
    }

    /**
     * Check wheter access should be allowed to a particular file.
     *
     * @param int $slot the slot of a question in this quiz attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param int $contextid the file context id from the request.
     * @param string $component the file component from the request.
     * @param string $filearea the file area from the request.
     * @param array $args extra part components from the request.
     * @param bool $forcedownload whether to force download.
     * @return string HTML for the question in its current state.
     */
    public function check_file_access($slot, $reviewing, $contextid, $component,
            $filearea, $args, $forcedownload) {
        $options = $this->get_display_options($reviewing);

        // Check permissions - warning there is similar code in review.php and
        // reviewquestion.php. If you change on, change them all.
        if ($reviewing && $this->is_own_attempt() && !$options->attempt) {
            return false;
        }

        if ($reviewing && !$this->is_own_attempt() && !$this->is_review_allowed()) {
            return false;
        }

        return $this->quba->check_file_access($slot, $options,
                $component, $filearea, $args, $forcedownload);
    }

    /**
     * Get the navigation panel object for this attempt.
     *
     * @param mod_quiz_renderer $output the quiz renderer to use to output things.
     * @param string $panelclass The type of panel, quiz_attempt_nav_panel or quiz_review_nav_panel
     * @param int $page the current page number.
     * @param bool $showall whether we are showing the whole quiz on one page. (Used by review.php.)
     * @return block_contents the requested object.
     */
    public function get_navigation_panel(mod_quiz_renderer $output,
             $panelclass, $page, $showall = false) {
        $panel = new $panelclass($this, $this->get_display_options(true), $page, $showall);

        $bc = new block_contents();
        $bc->attributes['id'] = 'mod_quiz_navblock';
        $bc->attributes['role'] = 'navigation';
        $bc->title = get_string('quiznavigation', 'quiz');
        $bc->content = $output->navigation_panel($panel);
        return $bc;
    }

    /**
     * Return an array of variant URLs to other attempts at this quiz.
     *
     * The $url passed in must contain an attempt parameter.
     *
     * The {@link mod_quiz_links_to_other_attempts} object returned contains an
     * array with keys that are the attempt number, 1, 2, 3.
     * The array values are either a {@link moodle_url} with the attempt parameter
     * updated to point to the attempt id of the other attempt, or null corresponding
     * to the current attempt number.
     *
     * @param moodle_url $url a URL.
     * @return mod_quiz_links_to_other_attempts|bool containing array int => null|moodle_url.
     *      False if none.
     */
    public function links_to_other_attempts(moodle_url $url) {
        $attempts = quiz_get_user_attempts($this->get_quiz()->id, $this->attempt->userid, 'all');
        if (count($attempts) <= 1) {
            return false;
        }

        $links = new mod_quiz_links_to_other_attempts();
        foreach ($attempts as $at) {
            if ($at->id == $this->attempt->id) {
                $links->links[$at->attempt] = null;
            } else {
                $links->links[$at->attempt] = new moodle_url($url, array('attempt' => $at->id));
            }
        }
        return $links;
    }

    /**
     * Return an array of variant URLs to other redos of the question in a particular slot.
     *
     * The $url passed in must contain a slot parameter.
     *
     * The {@link mod_quiz_links_to_other_attempts} object returned contains an
     * array with keys that are the redo number, 1, 2, 3.
     * The array values are either a {@link moodle_url} with the slot parameter
     * updated to point to the slot that has that redo of this question; or null
     * corresponding to the redo identified by $slot.
     *
     * @param int $slot identifies a question in this attempt.
     * @param moodle_url $baseurl the base URL to modify to generate each link.
     * @return mod_quiz_links_to_other_attempts|null containing array int => null|moodle_url,
     *      or null if the question in this slot has not been redone.
     */
    public function links_to_other_redos($slot, moodle_url $baseurl) {
        $originalslot = $this->get_original_slot($slot);

        $qas = $this->all_question_attempts_originally_in_slot($originalslot);
        if (count($qas) <= 1) {
            return null;
        }

        $links = new mod_quiz_links_to_other_attempts();
        $index = 1;
        foreach ($qas as $qa) {
            if ($qa->get_slot() == $slot) {
                $links->links[$index] = null;
            } else {
                $url = new moodle_url($baseurl, array('slot' => $qa->get_slot()));
                $links->links[$index] = new action_link($url, $index,
                        new popup_action('click', $url, 'reviewquestion',
                                array('width' => 450, 'height' => 650)),
                        array('title' => get_string('reviewresponse', 'question')));
            }
            $index++;
        }
        return $links;
    }

    // Methods for processing ==================================================

    /**
     * Check this attempt, to see if there are any state transitions that should
     * happen automatically. This function will update the attempt checkstatetime.
     * @param int $timestamp the timestamp that should be stored as the modified
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function handle_if_time_expired($timestamp, $studentisonline) {

        $timeclose = $this->get_access_manager($timestamp)->get_end_time($this->attempt);

        if ($timeclose === false || $this->is_preview()) {
            $this->update_timecheckstate(null);
            return; // No time limit.
        }
        if ($timestamp < $timeclose) {
            $this->update_timecheckstate($timeclose);
            return; // Time has not yet expired.
        }

        // If the attempt is already overdue, look to see if it should be abandoned ...
        if ($this->attempt->state == self::OVERDUE) {
            $timeoverdue = $timestamp - $timeclose;
            $graceperiod = $this->quizobj->get_quiz()->graceperiod;
            if ($timeoverdue >= $graceperiod) {
                $this->process_abandon($timestamp, $studentisonline);
            } else {
                // Overdue time has not yet expired
                $this->update_timecheckstate($timeclose + $graceperiod);
            }
            return; // ... and we are done.
        }

        if ($this->attempt->state != self::IN_PROGRESS) {
            $this->update_timecheckstate(null);
            return; // Attempt is already in a final state.
        }

        // Otherwise, we were in quiz_attempt::IN_PROGRESS, and time has now expired.
        // Transition to the appropriate state.
        switch ($this->quizobj->get_quiz()->overduehandling) {
            case 'autosubmit':
                $this->process_finish($timestamp, false, $studentisonline ? $timestamp : $timeclose, $studentisonline);
                return;

            case 'graceperiod':
                $this->process_going_overdue($timestamp, $studentisonline);
                return;

            case 'autoabandon':
                $this->process_abandon($timestamp, $studentisonline);
                return;
        }

        // This is an overdue attempt with no overdue handling defined, so just abandon.
        $this->process_abandon($timestamp, $studentisonline);
        return;
    }

    /**
     * Process all the actions that were submitted as part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modified.
     *      time in the database for these actions. If null, will use the current time.
     * @param bool $becomingoverdue
     * @param array|null $simulatedresponses If not null, then we are testing, and this is an array of simulated data.
     *      There are two formats supported here, for historical reasons. The newer approach is to pass an array created by
     *      {@link core_question_generator::get_simulated_post_data_for_questions_in_usage()}.
     *      the second is to pass an array slot no => contains arrays representing student
     *      responses which will be passed to {@link question_definition::prepare_simulated_post_data()}.
     *      This second method will probably get deprecated one day.
     */
    public function process_submitted_actions($timestamp, $becomingoverdue = false, $simulatedresponses = null) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        if ($simulatedresponses !== null) {
            if (is_int(key($simulatedresponses))) {
                // Legacy approach. Should be removed one day.
                $simulatedpostdata = $this->quba->prepare_simulated_post_data($simulatedresponses);
            } else {
                $simulatedpostdata = $simulatedresponses;
            }
        } else {
            $simulatedpostdata = null;
        }

        $this->quba->process_all_actions($timestamp, $simulatedpostdata);
        question_engine::save_questions_usage_by_activity($this->quba);

        $this->attempt->timemodified = $timestamp;
        if ($this->attempt->state == self::FINISHED) {
            $this->attempt->sumgrades = $this->quba->get_total_mark();
        }
        if ($becomingoverdue) {
            $this->process_going_overdue($timestamp, true);
        } else {
            $DB->update_record('quiz_attempts', $this->attempt);
        }

        if (!$this->is_preview() && $this->attempt->state == self::FINISHED) {
            quiz_save_best_grade($this->get_quiz(), $this->get_userid());
        }

        $transaction->allow_commit();
    }

    /**
     * Replace a question in an attempt with a new attempt at the same question.
     *
     * Well, for randomised questions, it won't be the same question, it will be
     * a different randomised selection.
     *
     * @param int $slot the question to restart.
     * @param int $timestamp the timestamp to record for this action.
     */
    public function process_redo_question($slot, $timestamp) {
        global $DB;

        if (!$this->can_question_be_redone_now($slot)) {
            throw new coding_exception('Attempt to restart the question in slot ' . $slot .
                    ' when it is not in a state to be restarted.');
        }

        $qubaids = new \mod_quiz\question\qubaids_for_users_attempts(
                $this->get_quizid(), $this->get_userid(), 'all', true);

        $transaction = $DB->start_delegated_transaction();

        // Add the question to the usage. It is important we do this before we choose a variant.
        $newquestionid = qbank_helper::choose_question_for_redo($this->get_quizid(),
                    $this->get_quizobj()->get_context(), $this->slots[$slot]->id, $qubaids);
        $newquestion = question_bank::load_question($newquestionid, $this->get_quiz()->shuffleanswers);
        $newslot = $this->quba->add_question_in_place_of_other($slot, $newquestion);

        // Choose the variant.
        if ($newquestion->get_num_variants() == 1) {
            $variant = 1;
        } else {
            $variantstrategy = new core_question\engine\variants\least_used_strategy(
                    $this->quba, $qubaids);
            $variant = $variantstrategy->choose_variant($newquestion->get_num_variants(),
                    $newquestion->get_variants_selection_seed());
        }

        // Start the question.
        $this->quba->start_question($slot, $variant);
        $this->quba->set_max_mark($newslot, 0);
        $this->quba->set_question_attempt_metadata($newslot, 'originalslot', $slot);
        question_engine::save_questions_usage_by_activity($this->quba);
        $this->fire_attempt_question_restarted_event($slot, $newquestion->id);

        $transaction->allow_commit();
    }

    /**
     * Process all the autosaved data that was part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modified.
     * time in the database for these actions. If null, will use the current time.
     */
    public function process_auto_save($timestamp) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $this->quba->process_all_autosaves($timestamp);
        question_engine::save_questions_usage_by_activity($this->quba);
        $this->fire_attempt_autosaved_event();

        $transaction->allow_commit();
    }

    /**
     * Update the flagged state for all question_attempts in this usage, if their
     * flagged state was changed in the request.
     */
    public function save_question_flags() {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->quba->update_question_flags();
        question_engine::save_questions_usage_by_activity($this->quba);
        $transaction->allow_commit();
    }

    /**
     * Submit the attempt.
     *
     * The separate $timefinish argument should be used when the quiz attempt
     * is being processed asynchronously (for example when cron is submitting
     * attempts where the time has expired).
     *
     * @param int $timestamp the time to record as last modified time.
     * @param bool $processsubmitted if true, and question responses in the current
     *      POST request are stored to be graded, before the attempt is finished.
     * @param ?int $timefinish if set, use this as the finish time for the attempt.
     *      (otherwise use $timestamp as the finish time as well).
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_finish($timestamp, $processsubmitted, $timefinish = null, $studentisonline = false) {
        global $DB; // Access the global database object for database operations.

        // Start a database transaction to ensure atomicity of the operations.
        $transaction = $DB->start_delegated_transaction();

        // If processsubmitted is true, process all submitted actions for the quiz attempt.
        if ($processsubmitted) {
            $this->quba->process_all_actions($timestamp); // Process all question actions submitted by the user
        }

        // Mark all questions in the quiz attempt as finished.
        $this->quba->finish_all_questions($timestamp);

        // Save the state of all questions in the question usage by activity (QUBA).
        question_engine::save_questions_usage_by_activity($this->quba);

        // Update the attempt's last modified time.
        $this->attempt->timemodified = $timestamp;

        // Set the finish time for the attempt. Use $timefinish if provided; otherwise, use $timestamp.
        $this->attempt->timefinish = $timefinish ?? $timestamp;

        // Calculate and store the total grade for the attempt.
        $this->attempt->sumgrades = $this->quba->get_total_mark();

        // Set the state of the attempt to "finished."
        $this->attempt->state = self::FINISHED;

        // Clear the timecheckstate field, as it is no longer needed after the attempt is finished.
        $this->attempt->timecheckstate = null;

        // Clear the graded notification sent time, as it will be updated later if necessary.
        $this->attempt->gradednotificationsenttime = null;

        // ==================== [New for RS] ====================
        // Store the fraction and id of each question in the attempt.
        $this->attempt->sumfractions = $this->quba->get_total_fraction(); // Array of fractions.
        $this->attempt->arrquestionids = $this->quba->get_all_question_ids(); // Array of question ids.

        $sm_userid = $this->get_userid(); // User ID of the student that is taking the quiz.
        $arr_of_rsquestions = []; // To store information about Recommender System questions.

        // Loop through each question id in the attempt.
        // The goal here is to save information about Recommender System questions for later use.
        for ($i = 0; $i < count($this->attempt->arrquestionids); $i++) {
            $questionid = $this->attempt->arrquestionids[$i];
            $fraction = $this->attempt->sumfractions[$i];

            // Get all tag ids associated with the question id.
            $arr_of_taginstances = $DB->get_records('tag_instance', ['itemid' => $questionid], 'id', 'tagid');
            foreach ($arr_of_taginstances as $taginstance) {
                // Get the name of each tag.
                $tag_name = ($DB->get_record('tag', ['id' => $taginstance->tagid], 'name'))->name;
                if (!preg_match('/^rs_/', $tag_name)) {
                    continue; // Skip if the tag name does not start with 'rs_' (not KC).
                }

                // Else, the tag name starts with 'rs_'.
                $tagid = $taginstance->tagid;   // Get the tag id.

                // Save the tag id, name, and fraction in an array with the questionid as key.
                // Plus, add a flag to indicate if the category has been altered.
                $arr_of_rsquestions[$questionid] = [
                    'tagid' => $tagid,
                    'tag_name' => $tag_name,
                    'fraction' => $fraction,
                    'is_categoryaltered' => false,
                ];
            }
        }

        // Do Student Model (KL) manipulation only if there is RS questions in this attempt, if not then skip
        if (count($arr_of_rsquestions) > 0) {
            // Loop through each RS question to do the relevant Student Model (KL) manipulation.
            foreach ($arr_of_rsquestions as $questionid => $rsquestion) {
                // Retrieve the student model for the current question with the matching tag id and user id.
                // $where = 'WHERE userid=' . $sm_userid . ' AND tagid=' . $rsquestion['tagid'];
                // $student_model = $DB->get_record_sql('SELECT * FROM {rs_student_model} ' . $where);

                if ($rsquestion['tag_name'] === 'rs_background') {
                    // This means the current question is the background form.
                    // Therefore, we need to create a new Student Model / Knowledge Level record.

                    // But first we need to check if the student model already exists for this user.
                    $sm_query = $DB->get_record('rs_student_model', ['userid' => $sm_userid], 'id');
                    if ($sm_query != null) {
                        // If there already is a KL record for this user, then we don't need to create a new one.
                        // in other words, this code won't create duplicate KL records for the same student and KC tag.
                        continue;
                    } else {
                        // To create a new SM, we need to get all the (Knowledge Component) tag ids.
                        $arr_of_tagids = $DB->get_records('tag', null, '', 'id, name');
                        $arr_of_tagids = array_filter($arr_of_tagids, function($tag) {
                            // Every KC tag starts with 'rs_'.
                            // But, we need to skip the rs_background and rs_feedback tags (as they are not Knowledge Component).
                            return preg_match('/^rs_/', $tag->name) && (($tag->name !== 'rs_background') && ($tag->name !== 'rs_feedback'));
                        });

                        // [To edit: Pedagogical Logic]
                        // Next, based on the student's index score for the prerequisite course, 
                        // set a pair of appropriate category and score as intial score that this user have for every KC.
                        if ($rsquestion['fraction'] == 1.0) {
                            // For index score of A
                            $sm_klcategory = 'mu';
                            $sm_klscore = 0.5;
                        } else if ($rsquestion['fraction'] == 0.8) {
                            // For index score of AB
                            $sm_klcategory = 'mu';
                            $sm_klscore = 0;
                        } else if ($rsquestion['fraction'] == 0.7) {
                            // For index score of B
                            $sm_klcategory = 'nu';
                            $sm_klscore = 1;
                        } else if ($rsquestion['fraction'] == 0.6) {
                            // For index score of BC
                            $sm_klcategory = 'nu';
                            $sm_klscore = 0.75;
                        } else if ($rsquestion['fraction'] == 0.5) {
                            // For index score of C
                            $sm_klcategory = 'nu';
                            $sm_klscore = 0.5;
                        } else if ($rsquestion['fraction'] == 0.4) {
                            // For index score of D
                            $sm_klcategory = 'nu';
                            $sm_klscore = 0.25;
                        } else {
                            // For index score of E
                            $sm_klcategory = 'nu';
                            $sm_klscore = 0;
                        }
                        
                        // Finally, loop to create and then insert KL record for each KC tag.
                        foreach ($arr_of_tagids as $tagid) {
                            $record = (object) array(
                                'userid' => $sm_userid,
                                'tagid' => $tagid->id,
                                'klcategory' => $sm_klcategory,
                                'klscore' => $sm_klscore,
                            );

                            // Temporary solution to skip the rs_array_of_struct tag id of (43).
                            // [Pedagogical Logic]
                            if ($record->tagid == 43) {
                                if (($record->klcategory != 'mu') || ($record->klscore != 0.5)) {
                                    $record->klscore = 0;
                                }
                                $record->klcategory = 'nu';
                            }

                            // Insert the record into the rs_student_model table.
                            $DB->insert_record('rs_student_model', $record);
                        }
                    } 
                } elseif ($rsquestion['tag_name'] === 'rs_feedback') {
                    /** 
                     * This means the current question is the feedback form.
                     * Therefore, if needed,
                     * we need to update the KL record for the previous questions that the student has answered.
                     * By if needed, it means that the user needs to answer the feedback that:
                     * the questions are either too hard (current fraction=0.5) or too easy (current fraction=1.0).
                     */

                    // Get all the tag ids associated with all of the questions that the student has answered in this quiz.
                    // Exclude the rs_background and rs_feedback tags.
                    $filtered_rsquestions = array_filter($arr_of_rsquestions, function($rsquestion) {
                        return $rsquestion['tag_name'] !== 'rs_background' && $rsquestion['tag_name'] !== 'rs_feedback';
                    });
                    // Get the tag ids of the filtered questions.
                    $arr_of_kc = array_map(function($rsquestion) {
                        return [
                            'tagid' => $rsquestion['tagid'],
                            'fraction' => $rsquestion['fraction'],
                            'is_categoryaltered' => $rsquestion['is_categoryaltered'],
                        ];
                    }, $filtered_rsquestions);

                    // Loop through each tag id and update the KL record for the student.
                    foreach ($arr_of_kc as $kc) {
                        // Get the current KL record for the current tag id that will be updated.
                        $current_klrecord = $DB->get_record('rs_student_model', ['userid' => $sm_userid, 'tagid' => $kc['tagid']], 'klcategory, klscore');

                        // [To edit:] Pedagogical Logic
                        // If the KL category has been upgraded or downgraded naturally (else), don't upgrade or downgrade it again.
                        // This is to prevent the KL category from being double upgraded or downgraded.
                        error_log("Is category altered: " . ($kc['is_categoryaltered'] ? 'true' : 'false'));
                        if (!$kc['is_categoryaltered']) {
                            // Here, rsquestion['fraction'] is the answer of the feedback form, where 1.0 is too easy and 0.5 is too hard.
                            // $kc['fraction'] is the fraction of the programming question that the student has answered.
                            if (($rsquestion['fraction'] == 0.5) && ($kc['fraction'] <= 0.5)) {
                                // The student thinks that the question is too hard
                                // Their feedback is valid only if:
                                // they have answered the question with only two or less correct test case.
                                if ($current_klrecord->klcategory == 'wu') {
                                    // Downgrade the KL category from 'well understood' to 'moderately understood'.
                                    $DB->set_field('rs_student_model', 'klcategory', 'mu', ['userid' => $sm_userid, 'tagid' => $kc['tagid']]);
                                } elseif ($current_klrecord->klcategory == 'mu') {
                                    // Downgrade the KL category from 'moderately understood' to 'not understood'.
                                    $DB->set_field('rs_student_model', 'klcategory', 'nu', ['userid' => $sm_userid, 'tagid' => $kc['tagid']]);
                                }
                                // Not possible to upgrade the KL category if it is already 'not understood'.
                            } elseif (($rsquestion['fraction'] == 1.0) && ($kc['fraction'] == 1.0)) {
                                // The student thinks that the question is too easy
                                // Their feedback is valid only if they have answered the question correctly.
                                if ($current_klrecord->klcategory == 'nu') {
                                    // Upgrade the KL category from 'not understood' to 'moderately understood'.
                                    $DB->set_field('rs_student_model', 'klcategory', 'mu', ['userid' => $sm_userid, 'tagid' => $kc['tagid']]);
                                } elseif ($current_klrecord->klcategory == 'mu') {
                                    // Upgrade the KL category from 'moderately understood' to 'well understood'.
                                    $DB->set_field('rs_student_model', 'klcategory', 'wu', ['userid' => $sm_userid, 'tagid' => $kc['tagid']]);
                                }
                                // Not possible to upgrade the KL category if it is already 'well understood'.
                            }
                        }
                    }
                } else {
                    // This means the current question is an RS programming question.
                    $current_klrecord = $DB->get_record('rs_student_model', ['userid' => $sm_userid, 'tagid' => $rsquestion['tagid']], 'klcategory, klscore');

                    // [To edit:] Pedagogical Logic
                    // Update the KL score based on the score that he/she got for the question.
                    // Current system:
                    // Every question have 4 test cases, each test case will have 0.25 mark, klscore will be updated as follows:
                    if ($rsquestion['fraction'] == 0) {
                        // None of the test cases passed (-0.5).
                        $current_klrecord->klscore = $current_klrecord->klscore - 0.5;
                    } else if ($rsquestion['fraction'] == 0.25) {
                        // Only one test case passed (-0.25).
                        $current_klrecord->klscore = $current_klrecord->klscore - 0.25;
                    } else if ($rsquestion['fraction'] == 0.75) {
                        // Two test cases passed (+0.5).
                        $current_klrecord->klscore = $current_klrecord->klscore + 0.5;
                    } else if ($rsquestion['fraction'] == 1.0) {
                        // All test cases passed (+1.0).
                        $current_klrecord->klscore = $current_klrecord->klscore + 1.0;
                    }

                    // The range of KL score is between 0 and 1, if it went above 100 or below 0, then:
                    // we need to adjust the KL category and KL score.
                    if ($current_klrecord->klscore > 1.0) {
                        // If KL score is greater than 1, then:
                        $rsquestion['is_categoryaltered'] = true; // Set the flag to true.
                        if ($current_klrecord->klcategory == 'nu') {
                            // Upgrade the KL category from 'not understood' to 'moderately understood'.
                            $current_klrecord->klcategory = 'mu';
                            $current_klrecord->klscore = $current_klrecord->klscore - 1.0;
                        } elseif ($current_klrecord->klcategory == 'mu') {
                            // Upgrade the KL category from 'moderately understood' to 'well understood'.
                            $current_klrecord->klcategory = 'wu';
                            $current_klrecord->klscore = $current_klrecord->klscore - 1.0;
                        } else {
                            // KL category is already at max, 'well understood', so we need to set KL score to 100.
                            $current_klrecord->klscore = 1.0;
                        }
                    } else if ($current_klrecord->klscore < 0) {
                        // If KL score is less than 0, then:
                        $rsquestion['is_categoryaltered'] = true; // Set the flag to true.
                        if ($current_klrecord->klcategory == 'wu') {
                            // Downgrade the KL category from 'well understood' to 'moderately understood'.
                            $current_klrecord->klcategory = 'mu';
                            $current_klrecord->klscore = $current_klrecord->klscore + 1.0;
                        } elseif ($current_klrecord->klcategory == 'mu') {
                            // Downgrade the KL category from 'moderately understood' to 'not understood'.
                            $current_klrecord->klcategory = 'nu';
                            $current_klrecord->klscore = $current_klrecord->klscore + 1.0;
                        } else {
                            // KL category is already at min, 'not understood', so we need to set KL score to 0.
                            $current_klrecord->klscore = 0;
                        }
                    }

                    // error_log('Question id: ' . $questionid);
                    // error_log('Tag id: ' . $rsquestion['tagid']);
                    // error_log('KL category: ' . $current_klrecord->klcategory);
                    // error_log('KL score: ' . $current_klrecord->klscore);
                    // error_log('Fraction: ' . $rsquestion['fraction']);
                    // error_log('Is category altered: ' . $rsquestion['is_categoryaltered']);

                    // Finally, update the KL record for the current tag id.
                    $DB->set_field('rs_student_model', 'klscore', $current_klrecord->klscore, ['userid' => $sm_userid, 'tagid' => $rsquestion['tagid']]);
                    if ($rsquestion['is_categoryaltered'] === true) {
                        // If the KL category has been altered, then update the KL category as well.
                        $DB->set_field('rs_student_model', 'klcategory', $current_klrecord->klcategory, ['userid' => $sm_userid, 'tagid' => $rsquestion['tagid']]);
                    }
                }
            }
        }

        // Check if manual grading is required or if the user has the capability to receive graded notifications.
        if (!$this->requires_manual_grading() ||
                !has_capability('mod/quiz:emailnotifyattemptgraded', $this->get_quizobj()->get_context(),
                        $this->get_userid())) {

            // If no manual grading is required or the user cannot receive notifications, set the graded notification sent time.
            $this->attempt->gradednotificationsenttime = $this->attempt->timefinish;
        }

        // If no manual grading is required or the user cannot receive notifications, set the graded notification sent time.
        $DB->update_record('quiz_attempts', $this->attempt);

        // If this is not a preview attempt, perform additional actions.
        if (!$this->is_preview()) {
            // Save the best grade for the user in the quiz.
            quiz_save_best_grade($this->get_quiz(), $this->attempt->userid);

            // Trigger an event to indicate that the attempt has been submitted.
            $this->fire_state_transition_event('\mod_quiz\event\attempt_submitted', $timestamp, $studentisonline);

            // Notify any access rules that the current attempt has been finished.
            $this->get_access_manager($timestamp)->current_attempt_finished();
        }

        // Commit the transaction to save all changes to the database.
        $transaction->allow_commit();
    }

    /**
     * Update this attempt timecheckstate if necessary.
     *
     * @param int|null $time the timestamp to set.
     */
    public function update_timecheckstate($time) {
        global $DB;
        if ($this->attempt->timecheckstate !== $time) {
            $this->attempt->timecheckstate = $time;
            $DB->set_field('quiz_attempts', 'timecheckstate', $time, array('id' => $this->attempt->id));
        }
    }

    /**
     * Mark this attempt as now overdue.
     *
     * @param int $timestamp the time to deem as now.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_going_overdue($timestamp, $studentisonline) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $timestamp;
        $this->attempt->state = self::OVERDUE;
        // If we knew the attempt close time, we could compute when the graceperiod ends.
        // Instead we'll just fix it up through cron.
        $this->attempt->timecheckstate = $timestamp;
        $DB->update_record('quiz_attempts', $this->attempt);

        $this->fire_state_transition_event('\mod_quiz\event\attempt_becameoverdue', $timestamp, $studentisonline);

        $transaction->allow_commit();

        quiz_send_overdue_message($this);
    }

    /**
     * Mark this attempt as abandoned.
     *
     * @param int $timestamp the time to deem as now.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_abandon($timestamp, $studentisonline) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $timestamp;
        $this->attempt->state = self::ABANDONED;
        $this->attempt->timecheckstate = null;
        $DB->update_record('quiz_attempts', $this->attempt);

        $this->fire_state_transition_event('\mod_quiz\event\attempt_abandoned', $timestamp, $studentisonline);

        $transaction->allow_commit();
    }

    /**
     * Fire a state transition event.
     *
     * @param string $eventclass the event class name.
     * @param int $timestamp the timestamp to include in the event.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    protected function fire_state_transition_event($eventclass, $timestamp, $studentisonline) {
        global $USER;
        $quizrecord = $this->get_quiz();
        $params = array(
            'context' => $this->get_quizobj()->get_context(),
            'courseid' => $this->get_courseid(),
            'objectid' => $this->attempt->id,
            'relateduserid' => $this->attempt->userid,
            'other' => array(
                'submitterid' => CLI_SCRIPT ? null : $USER->id,
                'quizid' => $quizrecord->id,
                'studentisonline' => $studentisonline
            )
        );
        $event = $eventclass::create($params);
        $event->add_record_snapshot('quiz', $this->get_quiz());
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    // Private methods =========================================================

    /**
     * Get a URL for a particular question on a particular page of the quiz.
     * Used by {@link attempt_url()} and {@link review_url()}.
     *
     * @param string $script. Used in the URL like /mod/quiz/$script.php.
     * @param int $slot identifies the specific question on the page to jump to.
     *      0 to just use the $page parameter.
     * @param int $page -1 to look up the page number from the slot, otherwise
     *      the page number to go to.
     * @param bool|null $showall if true, return a URL with showall=1, and not page number.
     *      if null, then an intelligent default will be chosen.
     * @param int $thispage the page we are currently on. Links to questions on this
     *      page will just be a fragment #q123. -1 to disable this.
     * @return moodle_url The requested URL.
     */
    protected function page_and_question_url($script, $slot, $page, $showall, $thispage) {

        $defaultshowall = $this->get_default_show_all($script);
        if ($showall === null && ($page == 0 || $page == -1)) {
            $showall = $defaultshowall;
        }

        // Fix up $page.
        if ($page == -1) {
            if ($slot !== null && !$showall) {
                $page = $this->get_question_page($slot);
            } else {
                $page = 0;
            }
        }

        if ($showall) {
            $page = 0;
        }

        // Add a fragment to scroll down to the question.
        $fragment = '';
        if ($slot !== null) {
            if ($slot == reset($this->pagelayout[$page]) && $thispage != $page) {
                // Changing the page, go to top.
                $fragment = '#';
            } else {
                // Link to the question container.
                $qa = $this->get_question_attempt($slot);
                $fragment = '#' . $qa->get_outer_question_div_unique_id();
            }
        }

        // Work out the correct start to the URL.
        if ($thispage == $page) {
            return new moodle_url($fragment);

        } else {
            $url = new moodle_url('/mod/quiz/' . $script . '.php' . $fragment,
                    array('attempt' => $this->attempt->id, 'cmid' => $this->get_cmid()));
            if ($page == 0 && $showall != $defaultshowall) {
                $url->param('showall', (int) $showall);
            } else if ($page > 0) {
                $url->param('page', $page);
            }
            return $url;
        }
    }

    /**
     * Process responses during an attempt at a quiz.
     *
     * @param  int $timenow time when the processing started.
     * @param  bool $finishattempt whether to finish the attempt or not.
     * @param  bool $timeup true if form was submitted by timer.
     * @param  int $thispage current page number.
     * @return string the attempt state once the data has been processed.
     * @since  Moodle 3.1
     */
    public function process_attempt($timenow, $finishattempt, $timeup, $thispage) {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        // Get key times.
        $accessmanager = $this->get_access_manager($timenow);
        $timeclose = $accessmanager->get_end_time($this->get_attempt());
        $graceperiodmin = get_config('quiz', 'graceperiodmin');

        // Don't enforce timeclose for previews.
        if ($this->is_preview()) {
            $timeclose = false;
        }

        // Check where we are in relation to the end time, if there is one.
        $toolate = false;
        if ($timeclose !== false) {
            if ($timenow > $timeclose - QUIZ_MIN_TIME_TO_CONTINUE) {
                // If there is only a very small amount of time left, there is no point trying
                // to show the student another page of the quiz. Just finish now.
                $timeup = true;
                if ($timenow > $timeclose + $graceperiodmin) {
                    $toolate = true;
                }
            } else {
                // If time is not close to expiring, then ignore the client-side timer's opinion
                // about whether time has expired. This can happen if the time limit has changed
                // since the student's previous interaction.
                $timeup = false;
            }
        }

        // If time is running out, trigger the appropriate action.
        $becomingoverdue = false;
        $becomingabandoned = false;
        if ($timeup) {
            if ($this->get_quiz()->overduehandling === 'graceperiod') {
                if ($timenow > $timeclose + $this->get_quiz()->graceperiod + $graceperiodmin) {
                    // Grace period has run out.
                    $finishattempt = true;
                    $becomingabandoned = true;
                } else {
                    $becomingoverdue = true;
                }
            } else {
                $finishattempt = true;
            }
        }

        if (!$finishattempt) {
            // Just process the responses for this page and go to the next page.
            if (!$toolate) {
                try {
                    $this->process_submitted_actions($timenow, $becomingoverdue);
                    $this->fire_attempt_updated_event();
                } catch (question_out_of_sequence_exception $e) {
                    throw new moodle_exception('submissionoutofsequencefriendlymessage', 'question',
                            $this->attempt_url(null, $thispage));

                } catch (Exception $e) {
                    // This sucks, if we display our own custom error message, there is no way
                    // to display the original stack trace.
                    $debuginfo = '';
                    if (!empty($e->debuginfo)) {
                        $debuginfo = $e->debuginfo;
                    }
                    throw new moodle_exception('errorprocessingresponses', 'question',
                            $this->attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
                }

                if (!$becomingoverdue) {
                    foreach ($this->get_slots() as $slot) {
                        if (optional_param('redoslot' . $slot, false, PARAM_BOOL)) {
                            $this->process_redo_question($slot, $timenow);
                        }
                    }
                }

            } else {
                // The student is too late.
                $this->process_going_overdue($timenow, true);
            }

            $transaction->allow_commit();

            return $becomingoverdue ? self::OVERDUE : self::IN_PROGRESS;
        }

        // Update the quiz attempt record.
        try {
            if ($becomingabandoned) {
                $this->process_abandon($timenow, true);
            } else {
                if (!$toolate || $this->get_quiz()->overduehandling === 'graceperiod') {
                    // Normally, we record the accurate finish time when the student is online.
                    $finishtime = $timenow;
                } else {
                    // But, if there is no grade period, and the final responses were too
                    // late to be processed, record the close time, to reduce confusion.
                    $finishtime = $timeclose;
                }
                $this->process_finish($timenow, !$toolate, $finishtime, true);
            }

        } catch (question_out_of_sequence_exception $e) {
            throw new moodle_exception('submissionoutofsequencefriendlymessage', 'question',
                    $this->attempt_url(null, $thispage));

        } catch (Exception $e) {
            // This sucks, if we display our own custom error message, there is no way
            // to display the original stack trace.
            $debuginfo = '';
            if (!empty($e->debuginfo)) {
                $debuginfo = $e->debuginfo;
            }
            throw new moodle_exception('errorprocessingresponses', 'question',
                    $this->attempt_url(null, $thispage), $e->getMessage(), $debuginfo);
        }

        // Send the user to the review page.
        $transaction->allow_commit();

        return $becomingabandoned ? self::ABANDONED : self::FINISHED;
    }

    /**
     * Check a page read access to see if is an out of sequence access.
     *
     * If allownext is set then we also check whether access to the page
     * after the current one should be permitted.
     *
     * @param int $page page number.
     * @param bool $allownext in case of a sequential navigation, can we go to next page ?
     * @return boolean false is an out of sequence access, true otherwise.
     * @since Moodle 3.1
     */
    public function check_page_access(int $page, bool $allownext = true): bool {
        if ($this->get_navigation_method() != QUIZ_NAVMETHOD_SEQ) {
            return true;
        }
        // Sequential access: allow access to the summary, current page or next page.
        // Or if the user review his/her attempt, see MDLQA-1523.
        return $page == -1
            || $page == $this->get_currentpage()
            || $allownext && ($page == $this->get_currentpage() + 1);
    }

    /**
     * Update attempt page.
     *
     * @param  int $page page number.
     * @return boolean true if everything was ok, false otherwise (out of sequence access).
     * @since Moodle 3.1
     */
    public function set_currentpage($page) {
        global $DB;

        if ($this->check_page_access($page)) {
            $DB->set_field('quiz_attempts', 'currentpage', $page, array('id' => $this->get_attemptid()));
            return true;
        }
        return false;
    }

    /**
     * Trigger the attempt_viewed event.
     *
     * @since Moodle 3.1
     */
    public function fire_attempt_viewed_event() {
        $params = array(
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => array(
                'quizid' => $this->get_quizid(),
                'page' => $this->get_currentpage()
            )
        );
        $event = \mod_quiz\event\attempt_viewed::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Trigger the attempt_updated event.
     *
     * @return void
     */
    public function fire_attempt_updated_event(): void {
        $params = [
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => [
                'quizid' => $this->get_quizid(),
                'page' => $this->get_currentpage()
            ]
        ];
        $event = \mod_quiz\event\attempt_updated::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Trigger the attempt_autosaved event.
     *
     * @return void
     */
    public function fire_attempt_autosaved_event(): void {
        $params = [
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => [
                'quizid' => $this->get_quizid(),
                'page' => $this->get_currentpage()
            ]
        ];
        $event = \mod_quiz\event\attempt_autosaved::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Trigger the attempt_question_restarted event.
     *
     * @param int $slot Slot number
     * @param int $newquestionid New question id.
     * @return void
     */
    public function fire_attempt_question_restarted_event(int $slot, int $newquestionid): void {
        $params = [
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => [
                'quizid' => $this->get_quizid(),
                'page' => $this->get_currentpage(),
                'slot' => $slot,
                'newquestionid' => $newquestionid
            ]
        ];
        $event = \mod_quiz\event\attempt_question_restarted::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Trigger the attempt_summary_viewed event.
     *
     * @since Moodle 3.1
     */
    public function fire_attempt_summary_viewed_event() {

        $params = array(
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => array(
                'quizid' => $this->get_quizid()
            )
        );
        $event = \mod_quiz\event\attempt_summary_viewed::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Trigger the attempt_reviewed event.
     *
     * @since Moodle 3.1
     */
    public function fire_attempt_reviewed_event() {

        $params = array(
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => array(
                'quizid' => $this->get_quizid()
            )
        );
        $event = \mod_quiz\event\attempt_reviewed::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Trigger the attempt manual grading completed event.
     */
    public function fire_attempt_manual_grading_completed_event() {
        $params = [
            'objectid' => $this->get_attemptid(),
            'relateduserid' => $this->get_userid(),
            'courseid' => $this->get_courseid(),
            'context' => context_module::instance($this->get_cmid()),
            'other' => [
                'quizid' => $this->get_quizid()
            ]
        ];

        $event = \mod_quiz\event\attempt_manual_grading_completed::create($params);
        $event->add_record_snapshot('quiz_attempts', $this->get_attempt());
        $event->trigger();
    }

    /**
     * Update the timemodifiedoffline attempt field.
     *
     * This function should be used only when web services are being used.
     *
     * @param int $time time stamp.
     * @return boolean false if the field is not updated because web services aren't being used.
     * @since Moodle 3.2
     */
    public function set_offline_modified_time($time) {
        // Update the timemodifiedoffline field only if web services are being used.
        if (WS_SERVER) {
            $this->attempt->timemodifiedoffline = $time;
            return true;
        }
        return false;
    }

    /**
     * Get the total number of unanswered questions in the attempt.
     *
     * @return int
     */
    public function get_number_of_unanswered_questions(): int {
        $totalunanswered = 0;
        foreach ($this->get_slots() as $slot) {
            if (!$this->is_real_question($slot)) {
                continue;
            }
            $questionstate = $this->get_question_state($slot);
            if ($questionstate == question_state::$todo || $questionstate == question_state::$invalid) {
                $totalunanswered++;
            }
        }
        return $totalunanswered;
    }
}


/**
 * Represents a heading in the navigation panel.
 *
 * @copyright  2015 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.9
 */
class quiz_nav_section_heading implements renderable {
    /** @var string the heading text. */
    public $heading;

    /**
     * Constructor.
     * @param string $heading the heading text
     */
    public function __construct($heading) {
        $this->heading = $heading;
    }
}


/**
 * Represents a single link in the navigation panel.
 *
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.1
 */
class quiz_nav_question_button implements renderable {
    /** @var string id="..." to add to the HTML for this button. */
    public $id;
    /** @var string number to display in this button. Either the question number of 'i'. */
    public $number;
    /** @var string class to add to the class="" attribute to represnt the question state. */
    public $stateclass;
    /** @var string Textual description of the question state, e.g. to use as a tool tip. */
    public $statestring;
    /** @var int the page number this question is on. */
    public $page;
    /** @var bool true if this question is on the current page. */
    public $currentpage;
    /** @var bool true if this question has been flagged. */
    public $flagged;
    /** @var moodle_url the link this button goes to, or null if there should not be a link. */
    public $url;
    /** @var int QUIZ_NAVMETHOD_FREE or QUIZ_NAVMETHOD_SEQ. */
    public $navmethod;
}


/**
 * Represents the navigation panel, and builds a {@link block_contents} to allow
 * it to be output.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
abstract class quiz_nav_panel_base {
    /** @var quiz_attempt */
    protected $attemptobj;
    /** @var question_display_options */
    protected $options;
    /** @var integer */
    protected $page;
    /** @var boolean */
    protected $showall;

    public function __construct(quiz_attempt $attemptobj,
            question_display_options $options, $page, $showall) {
        $this->attemptobj = $attemptobj;
        $this->options = $options;
        $this->page = $page;
        $this->showall = $showall;
    }

    /**
     * Get the buttons and section headings to go in the quiz navigation block.
     *
     * @return renderable[] the buttons, possibly interleaved with section headings.
     */
    public function get_question_buttons() {
        $buttons = array();
        foreach ($this->attemptobj->get_slots() as $slot) {
            $heading = $this->attemptobj->get_heading_before_slot($slot);
            if (!is_null($heading)) {
                $sections = $this->attemptobj->get_quizobj()->get_sections();
                if (!(empty($heading) && count($sections) == 1)) {
                    $buttons[] = new quiz_nav_section_heading(format_string($heading));
                }
            }

            $qa = $this->attemptobj->get_question_attempt($slot);
            $showcorrectness = $this->options->correctness && $qa->has_marks();

            $button = new quiz_nav_question_button();
            $button->id          = 'quiznavbutton' . $slot;
            $button->number      = $this->attemptobj->get_question_number($slot);
            $button->stateclass  = $qa->get_state_class($showcorrectness);
            $button->navmethod   = $this->attemptobj->get_navigation_method();
            if (!$showcorrectness && $button->stateclass === 'notanswered') {
                $button->stateclass = 'complete';
            }
            $button->statestring = $this->get_state_string($qa, $showcorrectness);
            $button->page        = $this->attemptobj->get_question_page($slot);
            $button->currentpage = $this->showall || $button->page == $this->page;
            $button->flagged     = $qa->is_flagged();
            $button->url         = $this->get_question_url($slot);
            if ($this->attemptobj->is_blocked_by_previous_question($slot)) {
                $button->url = null;
                $button->stateclass = 'blocked';
                $button->statestring = get_string('questiondependsonprevious', 'quiz');
            }
            $buttons[] = $button;
        }

        return $buttons;
    }

    protected function get_state_string(question_attempt $qa, $showcorrectness) {
        if ($qa->get_question(false)->length > 0) {
            return $qa->get_state_string($showcorrectness);
        }

        // Special case handling for 'information' items.
        if ($qa->get_state() == question_state::$todo) {
            return get_string('notyetviewed', 'quiz');
        } else {
            return get_string('viewed', 'quiz');
        }
    }

    /**
     * Hook for subclasses to override.
     *
     * @param mod_quiz_renderer $output the quiz renderer to use.
     * @return string HTML to output.
     */
    public function render_before_button_bits(mod_quiz_renderer $output) {
        return '';
    }

    abstract public function render_end_bits(mod_quiz_renderer $output);

    /**
     * Render the restart preview button.
     *
     * @param mod_quiz_renderer $output the quiz renderer to use.
     * @return string HTML to output.
     */
    protected function render_restart_preview_link($output) {
        if (!$this->attemptobj->is_own_preview()) {
            return '';
        }
        return $output->restart_preview_button(new moodle_url(
                $this->attemptobj->start_attempt_url(), array('forcenew' => true)));
    }

    protected abstract function get_question_url($slot);

    public function user_picture() {
        global $DB;
        if ($this->attemptobj->get_quiz()->showuserpicture == QUIZ_SHOWIMAGE_NONE) {
            return null;
        }
        $user = $DB->get_record('user', array('id' => $this->attemptobj->get_userid()));
        $userpicture = new user_picture($user);
        $userpicture->courseid = $this->attemptobj->get_courseid();
        if ($this->attemptobj->get_quiz()->showuserpicture == QUIZ_SHOWIMAGE_LARGE) {
            $userpicture->size = true;
        }
        return $userpicture;
    }

    /**
     * Return 'allquestionsononepage' as CSS class name when $showall is set,
     * otherwise, return 'multipages' as CSS class name.
     *
     * @return string, CSS class name
     */
    public function get_button_container_class() {
        // Quiz navigation is set on 'Show all questions on one page'.
        if ($this->showall) {
            return 'allquestionsononepage';
        }
        // Quiz navigation is set on 'Show one page at a time'.
        return 'multipages';
    }
}


/**
 * Specialisation of {@link quiz_nav_panel_base} for the attempt quiz page.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class quiz_attempt_nav_panel extends quiz_nav_panel_base {
    public function get_question_url($slot) {
        if ($this->attemptobj->can_navigate_to($slot)) {
            return $this->attemptobj->attempt_url($slot, -1, $this->page);
        } else {
            return null;
        }
    }

    public function render_before_button_bits(mod_quiz_renderer $output) {
        return html_writer::tag('div', get_string('navnojswarning', 'quiz'),
                array('id' => 'quiznojswarning'));
    }

    public function render_end_bits(mod_quiz_renderer $output) {
        if ($this->page == -1) {
            // Don't link from the summary page to itself.
            return '';
        }
        return html_writer::link($this->attemptobj->summary_url(),
                get_string('endtest', 'quiz'), array('class' => 'endtestlink aalink')) .
                $this->render_restart_preview_link($output);
    }
}


/**
 * Specialisation of {@link quiz_nav_panel_base} for the review quiz page.
 *
 * @copyright  2008 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 2.0
 */
class quiz_review_nav_panel extends quiz_nav_panel_base {
    public function get_question_url($slot) {
        return $this->attemptobj->review_url($slot, -1, $this->showall, $this->page);
    }

    public function render_end_bits(mod_quiz_renderer $output) {
        $html = '';
        if ($this->attemptobj->get_num_pages() > 1) {
            if ($this->showall) {
                $html .= html_writer::link($this->attemptobj->review_url(null, 0, false),
                        get_string('showeachpage', 'quiz'));
            } else {
                $html .= html_writer::link($this->attemptobj->review_url(null, 0, true),
                        get_string('showall', 'quiz'));
            }
        }
        $html .= $output->finish_review_link($this->attemptobj);
        $html .= $this->render_restart_preview_link($output);
        return $html;
    }
}
