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
 * Library of functions used by the quiz module.
 *
 * This contains functions that are called from within the quiz module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_quiz
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/accessmanager.php');
require_once($CFG->dirroot . '/mod/quiz/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/quiz/renderer.php');
require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');

use mod_quiz\question\bank\qbank_helper;
use qbank_previewquestion\question_preview_options;

/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the quiz close date. (1 hour)
 */
define('QUIZ_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the quiz, then do not take them to the next page of the quiz. Instead
 * close the quiz immediately.
 */
define('QUIZ_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in quiz settings.
 */
define('QUIZ_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in quiz settings.
 */
define('QUIZ_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in quiz settings.
 */
define('QUIZ_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a quiz
 *
 * Creates an attempt object to represent an attempt at the quiz by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $quizobj the quiz object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param stdClass|null $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $quiz->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int $userid  the id of the user attempting this quiz.
 *
 * @return object the newly created attempt object.
 */
function quiz_create_attempt(quiz $quizobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $quiz = $quizobj->get_quiz();
    if ($quiz->sumgrades < 0.000005 && $quiz->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'quiz',
                new moodle_url('/mod/quiz/view.php', array('q' => $quiz->id)),
                    array('grade' => quiz_format_grade($quiz, $quiz->grade)));
    }

    if ($attemptnumber == 1 || !$quiz->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->quiz = $quiz->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            throw new \moodle_exception('cannotfindprevattempt', 'quiz');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->timemodifiedoffline = 0;
    $attempt->state = quiz_attempt::IN_PROGRESS;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;
    $attempt->gradednotificationsenttime = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $quizobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}
/**
 * Start a normal, new, quiz attempt.
 *
 * @param quiz      $quizobj            the quiz object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {

    // Retrieve the question usage IDs for this user's previous quiz attempts.
    $qubaids = new \mod_quiz\question\qubaids_for_users_attempts(
            $quizobj->get_quizid(), $attempt->userid);

    // Preload all the questions in the quiz to optimize performance.
    $quizobj->preload_questions();

    // Load all the questions into memory for processing.
    $quizobj->load_questions();

    // Initialize variables for processing questions.
    $randomfound = false; // Flag to indicate if random questions are present.
    $slot = 0; // Slot counter for questions
    $questions = array(); // Array to store processed questions.
    $maxmark = array(); // Array to store maximum marks for each question.
    $page = array(); // Array to store page numbers for each question.

    // Iterate through all questions in the quiz.
    foreach ($quizobj->get_questions() as $questiondata) {
        $slot += 1; // Increment the slot counter.
        $maxmark[$slot] = $questiondata->maxmark; // Store the maximum mark for the question.
        $page[$slot] = $questiondata->page; // Store the page number for the question

        // Check if the question is in draft status and throw an error if it is.
        if ($questiondata->status == \core_question\local\bank\question_version_status::QUESTION_STATUS_DRAFT) {
            throw new moodle_exception('questiondraftonly', 'mod_quiz', '', $questiondata->name);
        }

        // If the question is of type "random", set the flag and skip further processing for now.
        if ($questiondata->qtype == 'random') {
            $randomfound = true;
            continue;
        }

        // If the quiz does not allow shuffling answers, disable shuffling for this question.
        if (!$quizobj->get_quiz()->shuffleanswers) {
            $questiondata->options->shuffleanswers = false;
        }

        // Create a question object and store it in the questions array.
        $questions[$slot] = question_bank::make_question($questiondata);
    }

    // Then find a question to go in place of each random question.
    // If random questions are found, resolve them.
    if ($randomfound) {
        $slot = 0; // Reset the slot counter.
        $usedquestionids = array(); // Array to track used question IDs.

        // [Not needed for RS] Count the number of times each question ID is used.
        // foreach ($questions as $question) {
        //     if ($question->id && isset($usedquestions[$question->id])) {
        //         $usedquestionids[$question->id] += 1;
        //     } else {
        //         $usedquestionids[$question->id] = 1;
        //     }
        // }

        // [RS] Make the array simpler by only storing the question ID.
        foreach ($questions as $question) {
            if ($question->id) {
                $usedquestionids[] = $question->id;
            }
        }

        // throw new moodle_exception('Missing Student Model! This is caused by admin error. Make sure that background form is filled first. Or if you have created a new KC tags during production, delete previous attempts and instruct your student to fill them again.'); // Test exception for debugging.

        // Create a random question loader to handle random question selection.
        $randomloader = new \core_question\local\bank\random_question_loader($qubaids, $usedquestionids);

        // Iterate through all questions again to resolve random questions.
        foreach ($quizobj->get_questions() as $questiondata) {
            $slot += 1; // Increment the slot counter.

            // Skip non-random questions.
            if ($questiondata->qtype != 'random') {
                continue;
            }

            // Get the tag IDs associated with the question slot.
            $tagids = qbank_helper::get_tag_ids_for_slot($questiondata);

            // Handle forced random choices for testing purposes.
            if (isset($questionids[$quba->next_slot_number()])) {
                if ($randomloader->is_question_available($questiondata->category,
                        (bool) $questiondata->questiontext, $questionids[$quba->next_slot_number()], $tagids)) {

                    // Load the forced question and add it to the questions array.
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $quizobj->get_quiz()->shuffleanswers);
                    continue;
                } else {
                    // Throw an error if the forced question ID is not available.
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one at random.
            $questionid = $randomloader->get_next_question_id($questiondata->category,
                    $questiondata->randomrecurse, $tagids);
            
            // Throw an error if there are not enough random questions available.
            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'quiz',
                                           $quizobj->view_url(), $questiondata);
            }

            // [WIP Later, outside of KISS] Check if the picked question's KC is initialized in the student model.
            // This is to avoid the situation where the question's KC tag is not initialized in the student model.
            // This can happen when new tags are added during production.

            // Load the selected random question and add it to the questions array.
            $questions[$slot] = question_bank::load_question($questionid,
                    $quizobj->get_quiz()->shuffleanswers);
        }
    }

    // Sort the questions by slot number to ensure correct order.
    ksort($questions);

    // Add all questions to the question usage object.
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]); // Add the question to the usage.

        if ($newslot != $slot) {
            // Throw an error if the slot numbers are inconsistent.
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions using a variant strategy.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    // Handle forced variants for testing purposes.
    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    // Start all questions with the selected variant strategy.
    $quba->start_all_questions($variantstrategy, $timenow, $attempt->userid);

    // Work out the attempt layout.
    $sections = $quizobj->get_sections(); // Get the sections of the quiz.
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            // Set the last slot for the current section.
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            // Set the last slot for the final section.
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = array(); // Initialize the layout array.
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            // Shuffle questions within the section if required.
            $questionsinthissection = array();
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection); // Shuffle the questions.
            $questionsonthispage = 0; // Reset the page counter.
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $quizobj->get_quiz()->questionsperpage) {
                    $layout[] = 0; // Add a page break.
                    $questionsonthispage = 0;
                }
                $layout[] = $slot; // Add the question slot to the layout.
                $questionsonthispage += 1;
            }

        } else {
            // Handle non-shuffled sections.
            $currentpage = $page[$section->firstslot]; // Get the current page number.
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;  // Add a page break if the page changes.
                }
                $layout[] = $slot; // Add the question slot to the layout.
                $currentpage = $page[$slot]; // Update the current page number.
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }

    // Set the layout for the attempt.
    $attempt->layout = implode(',', $layout);

    // Return the modified attempt object.
    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param object                        $attempt      this attempt
 * @param object                        $lastattempt  last attempt
 * @return object                       modified attempt object
 *
 */
function quiz_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $question = $oldqa->get_question(false);
        if ($question->status == \core_question\local\bank\question_version_status::QUESTION_STATUS_DRAFT) {
            throw new moodle_exception('questiondraftonly', 'mod_quiz', '', $question->name);
        }
        $newslot = $quba->add_question($question, $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * The save started question usage and quiz attempt in db and log the started attempt.
 *
 * @param quiz                       $quizobj
 * @param question_usage_by_activity $quba
 * @param object                     $attempt
 * @return object                    attempt object with uniqueid and id set.
 */
function quiz_attempt_save_started($quizobj, $quba, $attempt) {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->id = $DB->insert_record('quiz_attempts', $attempt);

    // Params used by the events below.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $quizobj->get_courseid(),
        'context' => $quizobj->get_context()
    );
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = array(
            'quizid' => $quizobj->get_quizid()
        );
        $event = \mod_quiz\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_quiz\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('quiz', $quizobj->get_quiz());
    $event->add_record_snapshot('quiz_attempts', $attempt);
    $event->trigger();

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given quiz. This function does not return preview attempts.
 *
 * @param int $quizid the id of the quiz.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function quiz_get_user_attempt_unfinished($quizid, $userid) {
    $attempts = quiz_get_user_attempts($quizid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a quiz attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the quiz_attempts table).
 * @param object $quiz the quiz object.
 */
function quiz_delete_attempt($attempt, $quiz) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('quiz_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->quiz != $quiz->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to quiz $attempt->quiz " .
                "but was passed quiz $quiz->id.");
        return;
    }

    if (!isset($quiz->cmid)) {
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        $quiz->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('quiz_attempts', array('id' => $attempt->id));

    // Log the deletion of the attempt if not a preview.
    if (!$attempt->preview) {
        $params = array(
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'context' => context_module::instance($quiz->cmid),
            'other' => array(
                'quizid' => $quiz->id
            )
        );
        $event = \mod_quiz\event\attempt_deleted::create($params);
        $event->add_record_snapshot('quiz_attempts', $attempt);
        $event->trigger();

        $callbackclasses = \core_component::get_plugin_list_with_class('quiz', 'quiz_attempt_deleted');
        foreach ($callbackclasses as $callbackclass) {
            component_class_callback($callbackclass, 'callback', [$quiz->id]);
        }
    }

    // Search quiz_attempts for other instances by this user.
    // If none, then delete record for this quiz, this user from quiz_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('quiz_attempts', array('userid' => $userid, 'quiz' => $quiz->id))) {
        $DB->delete_records('quiz_grades', array('userid' => $userid, 'quiz' => $quiz->id));
    } else {
        quiz_save_best_grade($quiz, $userid);
    }

    quiz_update_grades($quiz, $userid);
}

/**
 * Delete all the preview attempts at a quiz, or possibly all the attempts belonging
 * to one user.
 * @param object $quiz the quiz object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function quiz_delete_previews($quiz, $userid = null) {
    global $DB;
    $conditions = array('quiz' => $quiz->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('quiz_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        quiz_delete_attempt($attempt, $quiz);
    }
}

/**
 * @param int $quizid The quiz id.
 * @return bool whether this quiz has any (non-preview) attempts.
 */
function quiz_has_attempts($quizid) {
    global $DB;
    return $DB->record_exists('quiz_attempts', array('quiz' => $quizid, 'preview' => 0));
}

// Functions to do with quiz layout and pages //////////////////////////////////

/**
 * Repaginate the questions in a quiz
 * @param int $quizid the id of the quiz to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function quiz_repaginate_questions($quizid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $sections = $DB->get_records('quiz_sections', array('quizid' => $quizid), 'firstslot ASC');
    $firstslots = array();
    foreach ($sections as $section) {
        if ((int)$section->firstslot === 1) {
            continue;
        }
        $firstslots[] = $section->firstslot;
    }

    $slots = $DB->get_records('quiz_slots', array('quizid' => $quizid),
            'slot');
    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if (($firstslots && in_array($slot->slot, $firstslots)) ||
            ($slotsonthispage && $slotsonthispage == $slotsperpage)) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('quiz_slots', 'page', $currentpage, array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();

    // Log quiz re-paginated event.
    $cm = get_coursemodule_from_instance('quiz', $quizid);
    $event = \mod_quiz\event\quiz_repaginated::create([
        'context' => \context_module::instance($cm->id),
        'objectid' => $quizid,
        'other' => [
            'slotsperpage' => $slotsperpage
        ]
    ]);
    $event->trigger();

}

// Functions to do with quiz grades ////////////////////////////////////////////

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this quiz.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $quiz the quiz object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function quiz_rescale_grade($rawgrade, $quiz, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($quiz->sumgrades >= 0.000005) {
        $grade = $rawgrade * $quiz->grade / $quiz->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = quiz_format_question_grade($quiz, $grade);
    } else if ($format) {
        $grade = quiz_format_grade($quiz, $grade);
    }
    return $grade;
}

/**
 * Get the feedback object for this grade on this quiz.
 *
 * @param float $grade a grade on this quiz.
 * @param object $quiz the quiz settings.
 * @return false|stdClass the record object or false if there is not feedback for the given grade
 * @since  Moodle 3.1
 */
function quiz_feedback_record_for_grade($grade, $quiz) {
    global $DB;

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('quiz_feedback',
            'quizid = ? AND mingrade <= ? AND ? < maxgrade', array($quiz->id, $grade, $grade));

    return $feedback;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this quiz. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this quiz.
 * @param object $quiz the quiz settings.
 * @param object $context the quiz context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function quiz_feedback_for_grade($grade, $quiz, $context) {

    if (is_null($grade)) {
        return '';
    }

    $feedback = quiz_feedback_record_for_grade($grade, $quiz);

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_quiz', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $quiz the quiz database row.
 * @return bool Whether this quiz has any non-blank feedback text.
 */
function quiz_has_feedback($quiz) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($quiz->id, $cache)) {
        $cache[$quiz->id] = quiz_has_grades($quiz) &&
                $DB->record_exists_select('quiz_feedback', "quizid = ? AND " .
                    $DB->sql_isnotempty('quiz_feedback', 'feedbacktext', false, true),
                array($quiz->id));
    }
    return $cache[$quiz->id];
}

/**
 * Update the sumgrades field of the quiz. This needs to be called whenever
 * the grading structure of the quiz is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link quiz_delete_previews()} before you call this function.
 *
 * @param object $quiz a quiz.
 */
function quiz_update_sumgrades($quiz) {
    global $DB;

    $sql = 'UPDATE {quiz}
            SET sumgrades = COALESCE((
                SELECT SUM(maxmark)
                FROM {quiz_slots}
                WHERE quizid = {quiz}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($quiz->id));
    $quiz->sumgrades = $DB->get_field('quiz', 'sumgrades', array('id' => $quiz->id));

    if ($quiz->sumgrades < 0.000005 && quiz_has_attempts($quiz->id)) {
        // If the quiz has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        quiz_set_grade(0, $quiz);
    }

    $callbackclasses = \core_component::get_plugin_list_with_class('quiz', 'quiz_structure_modified');
    foreach ($callbackclasses as $callbackclass) {
        component_class_callback($callbackclass, 'callback', [$quiz->id]);
    }
}

/**
 * Update the sumgrades field of the attempts at a quiz.
 *
 * @param object $quiz a quiz.
 */
function quiz_update_all_attempt_sumgrades($quiz) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {quiz_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE quiz = :quizid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'quizid' => $quiz->id,
            'finishedstate' => quiz_attempt::FINISHED));
}

/**
 * The quiz grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in quiz_grades and quiz_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * quiz_update_all_attempt_sumgrades, quiz_update_all_final_grades and
 * quiz_update_grades.
 *
 * @param float $newgrade the new maximum grade for the quiz.
 * @param object $quiz the quiz we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function quiz_set_grade($newgrade, $quiz) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($quiz->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $quiz->grade;
    $quiz->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the quiz table.
    $DB->set_field('quiz', 'grade', $newgrade, array('id' => $quiz->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        quiz_update_all_final_grades($quiz);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {quiz_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE quiz = ?
        ", array($newgrade/$oldgrade, $timemodified, $quiz->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the quiz_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {quiz_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE quizid = ?
        ", array($factor, $factor, $quiz->id));
    }

    // Update grade item and send all grades to gradebook.
    quiz_grade_item_update($quiz);
    quiz_update_grades($quiz);

    $transaction->allow_commit();

    // Log quiz grade updated event.
    // We use $num + 0 as a trick to remove the useless 0 digits from decimals.
    $cm = get_coursemodule_from_instance('quiz', $quiz->id);
    $event = \mod_quiz\event\quiz_grade_updated::create([
        'context' => \context_module::instance($cm->id),
        'objectid' => $quiz->id,
        'other' => [
            'oldgrade' => $oldgrade + 0,
            'newgrade' => $newgrade + 0
        ]
    ]);
    $event->trigger();
    return true;
}

/**
 * Save the overall grade for a user at a quiz in the quiz_grades table
 *
 * @param object $quiz The quiz for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function quiz_save_best_grade($quiz, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = quiz_get_user_attempts($quiz->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = quiz_calculate_best_grade($quiz, $attempts);
    $bestgrade = quiz_rescale_grade($bestgrade, $quiz, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('quiz_grades', array('quiz' => $quiz->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('quiz_grades',
            array('quiz' => $quiz->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('quiz_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->quiz = $quiz->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('quiz_grades', $grade);
    }

    quiz_update_grades($quiz, $userid);
}

/**
 * Calculate the overall grade for a quiz given a number of attempts by a particular user.
 *
 * @param object $quiz    the quiz settings object.
 * @param array $attempts an array of all the user's attempts at this quiz in order.
 * @return float          the overall grade
 */
function quiz_calculate_best_grade($quiz, $attempts) {

    switch ($quiz->grademethod) {

        case QUIZ_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case QUIZ_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case QUIZ_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case QUIZ_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this quiz for all students.
 *
 * This function is equivalent to calling quiz_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $quiz the quiz settings.
 */
function quiz_update_all_final_grades($quiz) {
    global $DB;

    if (!$quiz->sumgrades) {
        return;
    }

    $param = array('iquizid' => $quiz->id, 'istatefinished' => quiz_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                iquiza.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {quiz_attempts} iquiza

            WHERE
                iquiza.state = :istatefinished AND
                iquiza.preview = 0 AND
                iquiza.quiz = :iquizid

            GROUP BY iquiza.userid
        ) first_last_attempts ON first_last_attempts.userid = quiza.userid";

    switch ($quiz->grademethod) {
        case QUIZ_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quiza.attempt = first_last_attempts.firstattempt AND';
            break;

        case QUIZ_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quiza.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quiza.attempt = first_last_attempts.lastattempt AND';
            break;

        case QUIZ_GRADEAVERAGE:
            $select = 'AVG(quiza.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case QUIZ_GRADEHIGHEST:
            $select = 'MAX(quiza.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($quiz->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($quiz->grade / $quiz->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['quizid'] = $quiz->id;
    $param['quizid2'] = $quiz->id;
    $param['quizid3'] = $quiz->id;
    $param['quizid4'] = $quiz->id;
    $param['statefinished'] = quiz_attempt::FINISHED;
    $param['statefinished2'] = quiz_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT quiza.userid, $finalgrade AS newgrade
            FROM {quiz_attempts} quiza
            $join
            WHERE
                $where
                quiza.state = :statefinished AND
                quiza.preview = 0 AND
                quiza.quiz = :quizid3
            GROUP BY quiza.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {quiz_grades} qg
                WHERE quiz = :quizid
            UNION
                SELECT DISTINCT userid
                FROM {quiz_attempts} quiza2
                WHERE
                    quiza2.state = :statefinished2 AND
                    quiza2.preview = 0 AND
                    quiza2.quiz = :quizid2
            ) users

            LEFT JOIN {quiz_grades} qg ON qg.userid = users.userid AND qg.quiz = :quizid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->quiz = $quiz->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('quiz_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('quiz_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('quiz_grades', 'quiz = ? AND userid ' . $test,
                array_merge(array($quiz->id), $params));
    }
}

/**
 * Return summary of the number of settings override that exist.
 *
 * To get a nice display of this, see the quiz_override_summary_links()
 * quiz renderer method.
 *
 * @param stdClass $quiz the quiz settings. Only $quiz->id is used at the moment.
 * @param stdClass|cm_info $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *      (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return array like 'group' => 3, 'user' => 12] where 3 is the number of group overrides,
 *      and 12 is the number of user ones.
 */
function quiz_override_summary(stdClass $quiz, stdClass $cm, int $currentgroup = 0): array {
    global $DB;

    if ($currentgroup) {
        // Currently only interested in one group.
        $groupcount = $DB->count_records('quiz_overrides', ['quiz' => $quiz->id, 'groupid' => $currentgroup]);
        $usercount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {quiz_overrides} o
                  JOIN {groups_members} gm ON o.userid = gm.userid
                 WHERE o.quiz = ?
                   AND gm.groupid = ?
                    ", [$quiz->id, $currentgroup]);
        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'onegroup'];
    }

    $quizgroupmode = groups_get_activity_groupmode($cm);
    $accessallgroups = ($quizgroupmode == NOGROUPS) ||
            has_capability('moodle/site:accessallgroups', context_module::instance($cm->id));

    if ($accessallgroups) {
        // User can see all groups.
        $groupcount = $DB->count_records_select('quiz_overrides',
                'quiz = ? AND groupid IS NOT NULL', [$quiz->id]);
        $usercount = $DB->count_records_select('quiz_overrides',
                'quiz = ? AND userid IS NOT NULL', [$quiz->id]);
        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'allgroups'];

    } else {
        // User can only see groups they are in.
        $groups = groups_get_activity_allowed_groups($cm);
        if (!$groups) {
            return ['group' => 0, 'user' => 0, 'mode' => 'somegroups'];
        }

        list($groupidtest, $params) = $DB->get_in_or_equal(array_keys($groups));
        $params[] = $quiz->id;

        $groupcount = $DB->count_records_select('quiz_overrides',
                "groupid $groupidtest AND quiz = ?", $params);
        $usercount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {quiz_overrides} o
                  JOIN {groups_members} gm ON o.userid = gm.userid
                 WHERE gm.groupid $groupidtest
                   AND o.quiz = ?
               ", $params);

        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'somegroups'];
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      quizid   => (array|int) attempts in given quiz(s)
 *                      groupid  => (array|int) quizzes with some override for given group(s)
 *
 */
function quiz_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("quiza.state IN ('inprogress', 'overdue')");
    $iwheres = array("iquiza.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz IN (SELECT q.id FROM {quiz} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz IN (SELECT q.id FROM {quiz} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.userid $incond";
    }

    if (isset($conditions['quizid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['quizid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['quizid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quiza.quiz IN (SELECT qo.quiz FROM {quiz_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquiza.quiz IN (SELECT qo.quiz FROM {quiz_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $quizausersql = quiz_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN quizauser.usertimelimit = 0 AND quizauser.usertimeclose = 0 THEN NULL
               WHEN quizauser.usertimelimit = 0 THEN quizauser.usertimeclose
               WHEN quizauser.usertimeclose = 0 THEN quiza.timestart + quizauser.usertimelimit
               WHEN quiza.timestart + quizauser.usertimelimit < quizauser.usertimeclose THEN quiza.timestart + quizauser.usertimelimit
               ELSE quizauser.usertimeclose END +
          CASE WHEN quiza.state = 'overdue' THEN quiz.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {quiz_attempts} quiza
                        JOIN {quiz} quiz ON quiz.id = quiza.quiz
                        JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id
                         SET quiza.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {quiz_attempts} quiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {quiz} quiz, ( $quizausersql ) quizauser
                       WHERE quiz.id = quiza.quiz
                         AND quizauser.id = quiza.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE quiza
                         SET timecheckstate = $timecheckstatesql
                        FROM {quiz_attempts} quiza
                        JOIN {quiz} quiz ON quiz.id = quiza.quiz
                        JOIN ( $quizausersql ) quizauser ON quizauser.id = quiza.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {quiz_attempts} quiza
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {quiz} quiz, ( $quizausersql ) quizauser
                            WHERE quiz.id = quiza.quiz
                              AND quizauser.id = quiza.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 * The query used herein is very similar to the one in function quiz_get_user_timeclose, so, in case you
 * would change either one of them, make sure to apply your changes to both.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias iquiza for the quiz attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function quiz_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $quizausersql = "
          SELECT iquiza.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), iquiz.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), iquiz.timelimit) AS usertimelimit

           FROM {quiz_attempts} iquiza
           JOIN {quiz} iquiz ON iquiz.id = iquiza.quiz
      LEFT JOIN {quiz_overrides} quo ON quo.quiz = iquiza.quiz AND quo.userid = iquiza.userid
      LEFT JOIN {groups_members} gm ON gm.userid = iquiza.userid
      LEFT JOIN {quiz_overrides} qgo1 ON qgo1.quiz = iquiza.quiz AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {quiz_overrides} qgo2 ON qgo2.quiz = iquiza.quiz AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {quiz_overrides} qgo3 ON qgo3.quiz = iquiza.quiz AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {quiz_overrides} qgo4 ON qgo4.quiz = iquiza.quiz AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY iquiza.id, iquiz.id, iquiz.timeclose, iquiz.timelimit";
    return $quizausersql;
}

/**
 * Return the attempt with the best grade for a quiz
 *
 * Which attempt is the best depends on $quiz->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $quiz    The quiz for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the quiz
 */
function quiz_calculate_best_attempt($quiz, $attempts) {

    switch ($quiz->grademethod) {

        case QUIZ_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case QUIZ_GRADEAVERAGE: // We need to do something with it.
        case QUIZ_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case QUIZ_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the quiz grade
 *      from the individual attempt grades.
 */
function quiz_get_grading_options() {
    return array(
        QUIZ_GRADEHIGHEST => get_string('gradehighest', 'quiz'),
        QUIZ_GRADEAVERAGE => get_string('gradeaverage', 'quiz'),
        QUIZ_ATTEMPTFIRST => get_string('attemptfirst', 'quiz'),
        QUIZ_ATTEMPTLAST  => get_string('attemptlast', 'quiz')
    );
}

/**
 * @param int $option one of the values QUIZ_GRADEHIGHEST, QUIZ_GRADEAVERAGE,
 *      QUIZ_ATTEMPTFIRST or QUIZ_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function quiz_get_grading_option_name($option) {
    $strings = quiz_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue quiz
 *      attempts.
 */
function quiz_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'quiz'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'quiz'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'quiz'),
    );
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function quiz_get_user_image_options() {
    return array(
        QUIZ_SHOWIMAGE_NONE  => get_string('shownoimage', 'quiz'),
        QUIZ_SHOWIMAGE_SMALL => get_string('showsmallimage', 'quiz'),
        QUIZ_SHOWIMAGE_LARGE => get_string('showlargeimage', 'quiz'),
    );
}

/**
 * Return an user's timeclose for all quizzes in a course, hereby taking into account group and user overrides.
 *
 * @param int $courseid the course id.
 * @return object An object with of all quizids and close unixdates in this course, taking into account the most lenient
 * overrides, if existing and 0 if no close date is set.
 */
function quiz_get_user_timeclose($courseid) {
    global $DB, $USER;

    // For teacher and manager/admins return timeclose.
    if (has_capability('moodle/course:update', context_course::instance($courseid))) {
        $sql = "SELECT quiz.id, quiz.timeclose AS usertimeclose
                  FROM {quiz} quiz
                 WHERE quiz.course = :courseid";

        $results = $DB->get_records_sql($sql, array('courseid' => $courseid));
        return $results;
    }

    $sql = "SELECT q.id,
  COALESCE(v.userclose, v.groupclose, q.timeclose, 0) AS usertimeclose
  FROM (
      SELECT quiz.id as quizid,
             MAX(quo.timeclose) AS userclose, MAX(qgo.timeclose) AS groupclose
       FROM {quiz} quiz
  LEFT JOIN {quiz_overrides} quo on quiz.id = quo.quiz AND quo.userid = :userid
  LEFT JOIN {groups_members} gm ON gm.userid = :useringroupid
  LEFT JOIN {quiz_overrides} qgo on quiz.id = qgo.quiz AND qgo.groupid = gm.groupid
      WHERE quiz.course = :courseid
   GROUP BY quiz.id) v
       JOIN {quiz} q ON q.id = v.quizid";

    $results = $DB->get_records_sql($sql, array('userid' => $USER->id, 'useringroupid' => $USER->id, 'courseid' => $courseid));
    return $results;

}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function quiz_questions_per_page_options() {
    $pageoptions = array();
    $pageoptions[0] = get_string('neverallononepage', 'quiz');
    $pageoptions[1] = get_string('everyquestion', 'quiz');
    for ($i = 2; $i <= QUIZ_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'quiz', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for a quiz attempt state.
 * @param string $state one of the state constants like {@link quiz_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function quiz_attempt_state_name($state) {
    switch ($state) {
        case quiz_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'quiz');
        case quiz_attempt::OVERDUE:
            return get_string('stateoverdue', 'quiz');
        case quiz_attempt::FINISHED:
            return get_string('statefinished', 'quiz');
        case quiz_attempt::ABANDONED:
            return get_string('stateabandoned', 'quiz');
        default:
            throw new coding_exception('Unknown quiz attempt state.');
    }
}

// Other quiz functions ////////////////////////////////////////////////////////

/**
 * @param object $quiz the quiz.
 * @param int $cmid the course_module object for this quiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param int $variant which question variant to preview (optional).
 * @param bool $random if question is random, true.
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function quiz_question_action_icons($quiz, $cmid, $question, $returnurl, $variant = null) {
    $html = '';
    if ($question->qtype !== 'random') {
        $html = quiz_question_preview_button($quiz, $question, false, $variant);
    }
    $html .= quiz_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this quiz.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function quiz_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit') ||
                    question_has_capability_on($question, 'move'))) {
        $action = $stredit;
        $icon = 't/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view')) {
        $action = $strview;
        $icon = 'i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/bank/editquestion/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton">' .
                $OUTPUT->pix_icon($icon, $action) . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $quiz the quiz settings
 * @param object $question the question
 * @param int $variant which question variant to preview (optional).
 * @param int $restartversion version of the question to use when restarting the preview.
 * @return moodle_url to preview this question with the options from this quiz.
 */
function quiz_question_preview_url($quiz, $question, $variant = null, $restartversion = null) {
    // Get the appropriate display options.
    $displayoptions = mod_quiz_display_options::make_from_quiz($quiz,
            mod_quiz_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return \qbank_previewquestion\helper::question_preview_url($question->id, $quiz->preferredbehaviour,
            $maxmark, $displayoptions, $variant, null, null, $restartversion);
}

/**
 * @param object $quiz the quiz settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @param int $variant which question variant to preview (optional).
 * @param bool $random if question is random, true.
 * @return string the HTML for a preview question icon.
 */
function quiz_question_preview_button($quiz, $question, $label = false, $variant = null, $random = null) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use')) {
        return '';
    }
    $structure = quiz::create($quiz->id)->get_structure();
    if (!empty($question->slot)) {
        $requestedversion = $structure->get_slot_by_number($question->slot)->requestedversion
                ?? question_preview_options::ALWAYS_LATEST;
    } else {
        $requestedversion = question_preview_options::ALWAYS_LATEST;
    }
    return $PAGE->get_renderer('mod_quiz', 'edit')->question_preview_icon(
            $quiz, $question, $label, $variant, $requestedversion);
}

/**
 * @param object $attempt the attempt.
 * @param object $context the quiz context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function quiz_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this quiz attempt is in - in the sense used by
 * quiz_get_review_options, not in the sense of $attempt->state.
 * @param object $quiz the quiz settings
 * @param object $attempt the quiz_attempt database row.
 * @return int one of the mod_quiz_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function quiz_attempt_state($quiz, $attempt) {
    if ($attempt->state == quiz_attempt::IN_PROGRESS) {
        return mod_quiz_display_options::DURING;
    } else if ($quiz->timeclose && time() >= $quiz->timeclose) {
        return mod_quiz_display_options::AFTER_CLOSE;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_quiz_display_options::IMMEDIATELY_AFTER;
    } else {
        return mod_quiz_display_options::LATER_WHILE_OPEN;
    }
}

/**
 * The the appropraite mod_quiz_display_options object for this attempt at this
 * quiz right now.
 *
 * @param stdClass $quiz the quiz instance.
 * @param stdClass $attempt the attempt in question.
 * @param context $context the quiz context.
 *
 * @return mod_quiz_display_options
 */
function quiz_get_review_options($quiz, $attempt, $context) {
    $options = mod_quiz_display_options::make_from_quiz($quiz, quiz_attempt_state($quiz, $attempt));

    $options->readonly = true;
    $options->flags = quiz_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/quiz/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == quiz_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/quiz:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/quiz/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/quiz:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;
        $options->userinfoinhistory = $attempt->userid;

    }

    return $options;
}

/**
 * Combines the review options from a number of different quiz attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = quiz_get_combined_reviewoptions(...)
 *
 * @param object $quiz the quiz instance.
 * @param array $attempts an array of attempt objects.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function quiz_get_combined_reviewoptions($quiz, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    // This shouldn't happen, but we need to prevent reveal information.
    if (empty($attempts)) {
        return array($someoptions, $someoptions);
    }

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_quiz_display_options::make_from_quiz($quiz,
                quiz_attempt_state($quiz, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 * @param bool $studentisonline is the student currently interacting with Moodle?
 *
 * @return int|false as for {@link message_send()}.
 */
function quiz_send_confirmation($recipient, $a, $studentisonline) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'quiz', $a);

    if ($studentisonline) {
        $eventdata->fullmessage = get_string('emailconfirmbody', 'quiz', $a);
    } else {
        $eventdata->fullmessage = get_string('emailconfirmbodyautosubmit', 'quiz', $a);
    }

    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'quiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;
    $eventdata->customdata        = [
        'cmid' => $a->quizcmid,
        'instance' => $a->quizid,
        'attemptid' => $a->attemptid,
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function quiz_send_notification($recipient, $submitter, $a) {
    global $PAGE;

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'quiz', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'quiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'quiz', $a);
    $eventdata->contexturl        = $a->quizreviewurl;
    $eventdata->contexturlname    = $a->quizname;
    $userpicture = new user_picture($submitter);
    $userpicture->size = 1; // Use f1 size.
    $userpicture->includetoken = $recipient->id; // Generate an out-of-session token for the user receiving the message.
    $eventdata->customdata        = [
        'cmid' => $a->quizcmid,
        'instance' => $a->quizid,
        'attemptid' => $a->attemptid,
        'notificationiconurl' => $userpicture->get_url($PAGE)->out(false),
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a quiz attempt is submitted.
 *
 * @param object $course the course
 * @param object $quiz the quiz
 * @param object $attempt this attempt just finished
 * @param object $context the quiz context
 * @param object $cm the coursemodule for this quiz
 * @param bool $studentisonline is the student currently interacting with Moodle?
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function quiz_send_notification_messages($course, $quiz, $attempt, $context, $cm, $studentisonline) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($quiz) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $quiz, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/quiz:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang,
            u.timezone, u.mailformat, u.maildisplay, u.auth, u.suspended, u.deleted, ';
    $userfieldsapi = \core_user\fields::for_name();
    $notifyfields .= $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $groups = groups_get_all_groups($course->id, $submitter->id, $cm->groupingid);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the quiz is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/quiz:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->courseid        = $course->id;
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Quiz info.
    $a->quizname        = $quiz->name;
    $a->quizreporturl   = $CFG->wwwroot . '/mod/quiz/report.php?id=' . $cm->id;
    $a->quizreportlink  = '<a href="' . $a->quizreporturl . '">' .
            format_string($quiz->name) . ' report</a>';
    $a->quizurl         = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id;
    $a->quizlink        = '<a href="' . $a->quizurl . '">' . format_string($quiz->name) . '</a>';
    $a->quizid          = $quiz->id;
    $a->quizcmid        = $cm->id;
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->quizreviewurl   = $CFG->wwwroot . '/mod/quiz/review.php?attempt=' . $attempt->id;
    $a->quizreviewlink  = '<a href="' . $a->quizreviewurl . '">' .
            format_string($quiz->name) . ' review</a>';
    $a->attemptid       = $attempt->id;
    // Student who sat the quiz info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && quiz_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && quiz_send_confirmation($submitter, $a, $studentisonline);
    }

    return $allok;
}

/**
 * Send the notification message when a quiz attempt becomes overdue.
 *
 * @param quiz_attempt $attemptobj all the data about the quiz attempt.
 */
function quiz_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', array('id' => $attemptobj->get_userid()), '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/quiz:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $quizname = format_string($attemptobj->get_quiz_name());

    $deadlines = array();
    if ($attemptobj->get_quiz()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_quiz()->timelimit;
    }
    if ($attemptobj->get_quiz()->timeclose) {
        $deadlines[] = $attemptobj->get_quiz()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_quiz()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_course()->id;
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // Quiz info.
    $a->quizname           = $quizname;
    $a->quizurl            = $attemptobj->view_url();
    $a->quizlink           = '<a href="' . $a->quizurl . '">' . $quizname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $quizname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_quiz';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'quiz', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'quiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'quiz', $a);
    $eventdata->contexturl        = $a->quizurl;
    $eventdata->contexturlname    = $a->quizname;
    $eventdata->customdata        = [
        'cmid' => $attemptobj->get_cmid(),
        'instance' => $attemptobj->get_quizid(),
        'attemptid' => $attemptobj->get_attemptid(),
    ];

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the quiz_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function quiz_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $attempt = $event->get_record_snapshot('quiz_attempts', $event->objectid);
    $quiz    = $event->get_record_snapshot('quiz', $attempt->quiz);
    $cm      = get_coursemodule_from_id('quiz', $event->get_context()->instanceid, $event->courseid);
    $eventdata = $event->get_data();

    if (!($course && $quiz && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) &&
        ($quiz->completionattemptsexhausted || $quiz->completionminattempts)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return quiz_send_notification_messages($course, $quiz, $attempt,
            context_module::instance($cm->id), $cm, $eventdata['other']['studentisonline']);
}

/**
 * Send the notification message when a quiz attempt has been manual graded.
 *
 * @param quiz_attempt $attemptobj Some data about the quiz attempt.
 * @param object $userto
 * @return int|false As for message_send.
 */
function quiz_send_notify_manual_graded_message(quiz_attempt $attemptobj, object $userto): ?int {
    global $CFG;

    $quizname = format_string($attemptobj->get_quiz_name());

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_courseid();
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    // Quiz info.
    $a->quizname           = $quizname;
    $a->quizurl            = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $attemptobj->get_cmid();

    // Attempt info.
    $a->attempttimefinish  = userdate($attemptobj->get_attempt()->timefinish);
    // Student's info.
    $a->studentidnumber    = $userto->idnumber;
    $a->studentname        = fullname($userto);

    $eventdata = new \core\message\message();
    $eventdata->component = 'mod_quiz';
    $eventdata->name = 'attempt_grading_complete';
    $eventdata->userfrom = core_user::get_noreply_user();
    $eventdata->userto = $userto;

    $eventdata->subject = get_string('emailmanualgradedsubject', 'quiz', $a);
    $eventdata->fullmessage = get_string('emailmanualgradedbody', 'quiz', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';

    $eventdata->notification = 1;
    $eventdata->contexturl = $a->quizurl;
    $eventdata->contexturlname = $a->quizname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle groups_member_added event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_member_added()}.
 */
function quiz_groups_member_added_handler($event) {
    debugging('quiz_groups_member_added_handler() is deprecated, please use ' .
        '\mod_quiz\group_observers::group_member_added() instead.', DEBUG_DEVELOPER);
    quiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_member_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_member_removed()}.
 */
function quiz_groups_member_removed_handler($event) {
    debugging('quiz_groups_member_removed_handler() is deprecated, please use ' .
        '\mod_quiz\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    quiz_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_group_deleted event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_deleted()}.
 */
function quiz_groups_group_deleted_handler($event) {
    global $DB;
    debugging('quiz_groups_group_deleted_handler() is deprecated, please use ' .
        '\mod_quiz\group_observers::group_deleted() instead.', DEBUG_DEVELOPER);
    quiz_process_group_deleted_in_course($event->courseid);
}

/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function quiz_process_group_deleted_in_course($courseid) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all quizzes with orphaned group overrides.
    $sql = "SELECT o.id, o.quiz, o.groupid
              FROM {quiz_overrides} o
              JOIN {quiz} quiz ON quiz.id = o.quiz
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE quiz.course = :courseid
               AND o.groupid IS NOT NULL
               AND grp.id IS NULL";
    $params = array('courseid' => $courseid);
    $records = $DB->get_records_sql($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('quiz_overrides', 'id', array_keys($records));
    $cache = cache::make('mod_quiz', 'overrides');
    foreach ($records as $record) {
        $cache->delete("{$record->quiz}_g_{$record->groupid}");
    }
    quiz_update_open_attempts(['quizid' => array_unique(array_column($records, 'quiz'))]);
}

/**
 * Handle groups_members_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quiz\group_observers::group_member_removed()}.
 */
function quiz_groups_members_removed_handler($event) {
    debugging('quiz_groups_members_removed_handler() is deprecated, please use ' .
        '\mod_quiz\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    if ($event->userid == 0) {
        quiz_update_open_attempts(array('courseid'=>$event->courseid));
    } else {
        quiz_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
    }
}

/**
 * Get the information about the standard quiz JavaScript module.
 * @return array a standard jsmodule structure.
 */
function quiz_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_quiz',
        'fullpath' => '/mod/quiz/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'quiz'),
            array('startattempt', 'quiz'),
            array('timesup', 'quiz'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the quiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quiz_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * quiz attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the quiz settings, and a time constant.
     * @param object $quiz the quiz settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_quiz_display_options set up appropriately.
     */
    public static function make_from_quiz($quiz, $when) {
        $options = new self();

        $options->attempt = self::extract($quiz->reviewattempt, $when, true, false);
        $options->correctness = self::extract($quiz->reviewcorrectness, $when);
        $options->marks = self::extract($quiz->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($quiz->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($quiz->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($quiz->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($quiz->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;
        $options->manualcomment = $options->feedback;

        if ($quiz->questiondecimalpoints != -1) {
            $options->markdp = $quiz->questiondecimalpoints;
        } else {
            $options->markdp = $quiz->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular quiz.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_quiz extends qubaid_join {
    public function __construct($quizid, $includepreviews = true, $onlyfinished = false) {
        $where = 'quiza.quiz = :quizaquiz';
        $params = array('quizaquiz' => $quizid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state = :statefinished';
            $params['statefinished'] = quiz_attempt::FINISHED;
        }

        parent::__construct('{quiz_attempts} quiza', 'quiza.uniqueid', $where, $params);
    }
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to a particular user and quiz combination.
 *
 * @copyright  2018 Andrew Nicols <andrwe@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_quiz_user extends qubaid_join {
    /**
     * Constructor for this qubaid.
     *
     * @param   int     $quizid The quiz to search.
     * @param   int     $userid The user to filter on
     * @param   bool    $includepreviews Whether to include preview attempts
     * @param   bool    $onlyfinished Whether to only include finished attempts or not
     */
    public function __construct($quizid, $userid, $includepreviews = true, $onlyfinished = false) {
        $where = 'quiza.quiz = :quizaquiz AND quiza.userid = :quizauserid';
        $params = [
            'quizaquiz' => $quizid,
            'quizauserid' => $userid,
        ];

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state = :statefinished';
            $params['statefinished'] = quiz_attempt::FINISHED;
        }

        parent::__construct('{quiz_attempts} quiza', 'quiza.uniqueid', $where, $params);
    }
}

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @param bool $showidnumber If true, show the question's idnumber, if any. False by default.
 * @param core_tag_tag[]|bool $showtags if array passed, show those tags. Else, if true, get and show tags,
 *       else, don't show tags (which is the default).
 * @return string HTML fragment.
 */
function quiz_question_tostring($question, $showicon = false, $showquestiontext = true,
        $showidnumber = false, $showtags = false) {
    global $OUTPUT;
    $result = '';

    // Question name.
    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    // Question idnumber.
    if ($showidnumber && $question->idnumber !== null && $question->idnumber !== '') {
        $result .= ' ' . html_writer::span(
                html_writer::span(get_string('idnumber', 'question'), 'accesshide') .
                ' ' . s($question->idnumber), 'badge badge-primary');
    }

    // Question tags.
    if (is_array($showtags)) {
        $tags = $showtags;
    } else if ($showtags) {
        $tags = core_tag_tag::get_item_tags('core_question', 'question', $question->id);
    } else {
        $tags = [];
    }
    if ($tags) {
        $result .= $OUTPUT->tag_list($tags, null, 'd-inline', 0, null, true);
    }

    // Question text.
    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, ['noclean' => true, 'para' => false, 'filter' => false]);
        $questiontext = shorten_text($questiontext, 50);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function quiz_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Verify that the question exists, and the user has permission to use it.
 *
 * @deprecated in 4.1 use mod_quiz\structure::has_use_capability(...) instead.
 *
 * @param object $quiz the quiz settings.
 * @param int $slot which question in the quiz to test.
 * @return bool whether the user can use this question.
 */
function quiz_has_question_use($quiz, $slot) {
    global $DB;

    debugging('Deprecated. Please use mod_quiz\structure::has_use_capability instead.');

    $sql = 'SELECT q.*
              FROM {quiz_slots} slot
              JOIN {question_references} qre ON qre.itemid = slot.id
              JOIN {question_bank_entries} qbe ON qbe.id = qre.questionbankentryid
              JOIN {question_versions} qve ON qve.questionbankentryid = qbe.id
              JOIN {question} q ON q.id = qve.questionid
             WHERE slot.quizid = ?
               AND slot.slot = ?
               AND qre.component = ?
               AND qre.questionarea = ?';

    $question = $DB->get_record_sql($sql, [$quiz->id, $slot, 'mod_quiz', 'slot']);

    if (!$question) {
        return false;
    }
    return question_has_capability_on($question, 'use');
}

/**
 * Add a question to a quiz
 *
 * Adds a question to a quiz by updating $quiz as well as the
 * quiz and quiz_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $quiz The extended quiz object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in quiz to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the quiz
 */
function quiz_add_quiz_question($questionid, $quiz, $page = 0, $maxmark = null) {
    global $DB;

    if (!isset($quiz->cmid)) {
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        $quiz->cmid = $cm->id;
    }

    // Make sue the question is not of the "random" type.
    $questiontype = $DB->get_field('question', 'qtype', array('id' => $questionid));
    if ($questiontype == 'random') {
        throw new coding_exception(
                'Adding "random" questions via quiz_add_quiz_question() is deprecated. Please use quiz_add_random_questions().'
        );
    }

    $trans = $DB->start_delegated_transaction();

    $sql = "SELECT qbe.id
              FROM {quiz_slots} slot
              JOIN {question_references} qr ON qr.itemid = slot.id
              JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
             WHERE slot.quizid = ?
               AND qr.component = ?
               AND qr.questionarea = ?
               AND qr.usingcontextid = ?";

    $questionslots = $DB->get_records_sql($sql, [$quiz->id, 'mod_quiz', 'slot',
            context_module::instance($quiz->cmid)->id]);

    $currententry = get_question_bank_entry($questionid);

    if (array_key_exists($currententry->id, $questionslots)) {
        $trans->allow_commit();
        return false;
    }

    $sql = "SELECT slot.slot, slot.page, slot.id
              FROM {quiz_slots} slot
             WHERE slot.quizid = ?
          ORDER BY slot.slot";

    $slots = $DB->get_records_sql($sql, [$quiz->id]);

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new instance.
    $slot = new stdClass();
    $slot->quizid = $quiz->id;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('quiz_slots', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

        quiz_update_section_firstslots($quiz->id, 1, max($lastslotbefore, 1));

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($quiz->questionsperpage && $numonlastpage >= $quiz->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $slotid = $DB->insert_record('quiz_slots', $slot);

    // Update or insert record in question_reference table.
    $sql = "SELECT DISTINCT qr.id, qr.itemid
              FROM {question} q
              JOIN {question_versions} qv ON q.id = qv.questionid
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_references} qr ON qbe.id = qr.questionbankentryid AND qr.version = qv.version
              JOIN {quiz_slots} qs ON qs.id = qr.itemid
             WHERE q.id = ?
               AND qs.id = ?
               AND qr.component = ?
               AND qr.questionarea = ?";
    $qreferenceitem = $DB->get_record_sql($sql, [$questionid, $slotid, 'mod_quiz', 'slot']);

    if (!$qreferenceitem) {
        // Create a new reference record for questions created already.
        $questionreferences = new \StdClass();
        $questionreferences->usingcontextid = context_module::instance($quiz->cmid)->id;
        $questionreferences->component = 'mod_quiz';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slotid;
        $questionreferences->questionbankentryid = get_question_bank_entry($questionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);

    } else if ($qreferenceitem->itemid === 0 || $qreferenceitem->itemid === null) {
        $questionreferences = new \StdClass();
        $questionreferences->id = $qreferenceitem->id;
        $questionreferences->itemid = $slotid;
        $DB->update_record('question_references', $questionreferences);
    } else {
        // If the reference record exits for another quiz.
        $questionreferences = new \StdClass();
        $questionreferences->usingcontextid = context_module::instance($quiz->cmid)->id;
        $questionreferences->component = 'mod_quiz';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slotid;
        $questionreferences->questionbankentryid = get_question_bank_entry($questionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);
    }

    $trans->allow_commit();

    // Log slot created event.
    $cm = get_coursemodule_from_instance('quiz', $quiz->id);
    $event = \mod_quiz\event\slot_created::create([
        'context' => context_module::instance($cm->id),
        'objectid' => $slotid,
        'other' => [
            'quizid' => $quiz->id,
            'slotnumber' => $slot->slot,
            'page' => $slot->page
        ]
    ]);
    $event->trigger();
}

/**
 * Move all the section headings in a certain slot range by a certain offset.
 *
 * @param int $quizid the id of a quiz
 * @param int $direction amount to adjust section heading positions. Normally +1 or -1.
 * @param int $afterslot adjust headings that start after this slot.
 * @param int|null $beforeslot optionally, only adjust headings before this slot.
 */
function quiz_update_section_firstslots($quizid, $direction, $afterslot, $beforeslot = null) {
    global $DB;
    $where = 'quizid = ? AND firstslot > ?';
    $params = [$direction, $quizid, $afterslot];
    if ($beforeslot) {
        $where .= ' AND firstslot < ?';
        $params[] = $beforeslot;
    }
    $firstslotschanges = $DB->get_records_select_menu('quiz_sections',
            $where, $params, '', 'firstslot, firstslot + ?');
    update_field_with_unique_index('quiz_sections', 'firstslot', $firstslotschanges, ['quizid' => $quizid]);
}

/**
 * Add a random question (slot) to the quiz at a given point. Used for the button that says "Add random question"
 * @param stdClass $quiz the quiz settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 * @param int[] $tagids Array of tagids. The question that will be picked randomly should be tagged with all these tags.
 */
function quiz_add_random_questions($quiz, $addonpage, $categoryid, $number,
        $includesubcategories, $tagids = []) {
    global $DB;

    // Retrieve the question category record from the database using the provided category ID.
    $category = $DB->get_record('question_categories', ['id' => $categoryid]);
    if (!$category) {
        // Throw an exception if the category does not exist.
        new moodle_exception('invalidcategoryid');
    }

    // Get the context of the question category.
    $catcontext = context::instance_by_id($category->contextid);

    // Ensure the user has the capability to use questions from this category.
    require_capability('moodle/question:useall', $catcontext);

    // Prepare tags for filtering random questions.
    $tags = \core_tag_tag::get_bulk($tagids, 'id, name'); // Retrieve tag details for the provided tag IDs.
    $tagstrings = []; // Initialize an array to store tag strings.
    foreach ($tags as $tag) {
        // Format each tag as "id,name" and add it to the array.
        $tagstrings[] = "{$tag->id},{$tag->name}";
    }

    // Loop to create the specified number of random questions.
    for ($i = 0; $i < $number; $i++) {
        // Set the filter conditions for selecting random questions.
        $filtercondition = new stdClass();
        $filtercondition->questioncategoryid = $categoryid; // Set the category ID.
        $filtercondition->includingsubcategories = $includesubcategories ? 1 : 0; // Include subcategories if specified.
        if (!empty($tagstrings)) {
            // Add tags to the filter condition if they are provided.
            $filtercondition->tags = $tagstrings;
        }

        // Ensure the quiz object has a valid course module ID (cmid).
        if (!isset($quiz->cmid)) {
            // Retrieve the course module for the quiz and set its cmid.
            $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
            $quiz->cmid = $cm->id;
        }

        // Prepare data for the random question slot.
        $randomslotdata = new stdClass();
        $randomslotdata->quizid = $quiz->id; // Set the quiz ID.
        $randomslotdata->usingcontextid = context_module::instance($quiz->cmid)->id; // Set the context ID of the quiz.
        $randomslotdata->questionscontextid = $category->contextid; // Set the context ID of the question category.
        $randomslotdata->maxmark = 1; // Set the default maximum mark for the random question.

        // Create a new random slot object.
        $randomslot = new \mod_quiz\local\structure\slot_random($randomslotdata);

        // Associate the random slot with the quiz.
        $randomslot->set_quiz($quiz);

        // Set the filter condition for the random slot.
        $randomslot->set_filter_condition($filtercondition);

        // Insert the random slot into the quiz at the specified page.
        $randomslot->insert($addonpage);
    }
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $quiz       quiz object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.1
 */
function quiz_view($quiz, $course, $cm, $context) {

    $params = array(
        'objectid' => $quiz->id,
        'context' => $context
    );

    $event = \mod_quiz\event\course_module_viewed::create($params);
    $event->add_record_snapshot('quiz', $quiz);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Validate permissions for creating a new attempt and start a new preview attempt if required.
 *
 * @param  quiz $quizobj quiz object
 * @param  quiz_access_manager $accessmanager quiz access manager
 * @param  bool $forcenew whether was required to start a new preview attempt
 * @param  int $page page to jump to in the attempt
 * @param  bool $redirect whether to redirect or throw exceptions (for web or ws usage)
 * @return array an array containing the attempt information, access error messages and the page to jump to in the attempt
 * @throws moodle_quiz_exception
 * @since Moodle 3.1
 */
function quiz_validate_new_attempt(quiz $quizobj, quiz_access_manager $accessmanager, $forcenew, $page, $redirect) {
    global $DB, $USER;
    $timenow = time();

    if ($quizobj->is_preview_user() && $forcenew) {
        $accessmanager->current_attempt_finished();
    }

    // Check capabilities.
    if (!$quizobj->is_preview_user()) {
        $quizobj->require_capability('mod/quiz:attempt');
    }

    // Check to see if a new preview was requested.
    if ($quizobj->is_preview_user() && $forcenew) {
        // To force the creation of a new preview, we mark the current attempt (if any)
        // as abandoned. It will then automatically be deleted below.
        $DB->set_field('quiz_attempts', 'state', quiz_attempt::ABANDONED,
                array('quiz' => $quizobj->get_quizid(), 'userid' => $USER->id));
    }

    // Look for an existing attempt.
    $attempts = quiz_get_user_attempts($quizobj->get_quizid(), $USER->id, 'all', true);
    $lastattempt = end($attempts);

    $attemptnumber = null;
    // If an in-progress attempt exists, check password then redirect to it.
    if ($lastattempt && ($lastattempt->state == quiz_attempt::IN_PROGRESS ||
            $lastattempt->state == quiz_attempt::OVERDUE)) {
        $currentattemptid = $lastattempt->id;
        $messages = $accessmanager->prevent_access();

        // If the attempt is now overdue, deal with that.
        $quizobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

        // And, if the attempt is now no longer in progress, redirect to the appropriate place.
        if ($lastattempt->state == quiz_attempt::ABANDONED || $lastattempt->state == quiz_attempt::FINISHED) {
            if ($redirect) {
                redirect($quizobj->review_url($lastattempt->id));
            } else {
                throw new moodle_quiz_exception($quizobj, 'attemptalreadyclosed');
            }
        }

        // If the page number was not explicitly in the URL, go to the current page.
        if ($page == -1) {
            $page = $lastattempt->currentpage;
        }

    } else {
        while ($lastattempt && $lastattempt->preview) {
            $lastattempt = array_pop($attempts);
        }

        // Get number for the next or unfinished attempt.
        if ($lastattempt) {
            $attemptnumber = $lastattempt->attempt + 1;
        } else {
            $lastattempt = false;
            $attemptnumber = 1;
        }
        $currentattemptid = null;

        $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

        if ($page == -1) {
            $page = 0;
        }
    }
    return array($currentattemptid, $attemptnumber, $lastattempt, $messages, $page);
}

/**
 * Prepare and start a new attempt deleting the previous preview attempts.
 *
 * @param quiz $quizobj quiz object
 * @param int $attemptnumber the attempt number
 * @param object $lastattempt last attempt object
 * @param bool $offlineattempt whether is an offline attempt or not
 * @param array $forcedrandomquestions slot number => question id. Used for random questions,
 *      to force the choice of a particular actual question. Intended for testing purposes only.
 * @param array $forcedvariants slot number => variant. Used for questions with variants,
 *      to force the choice of a particular variant. Intended for testing purposes only.
 * @param int $userid Specific user id to create an attempt for that user, null for current logged in user
 * @return object the new attempt
 * @since  Moodle 3.1
 */
function quiz_prepare_and_start_new_attempt(quiz $quizobj, $attemptnumber, $lastattempt,
        $offlineattempt = false, $forcedrandomquestions = [], $forcedvariants = [], $userid = null) {
    global $DB, $USER;

    if ($userid === null) {
        $userid = $USER->id;
        $ispreviewuser = $quizobj->is_preview_user();
    } else {
        $ispreviewuser = has_capability('mod/quiz:preview', $quizobj->get_context(), $userid);
    }
    // Delete any previous preview attempts belonging to this user.
    quiz_delete_previews($quizobj->get_quiz(), $userid);

    $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
    $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

    // Create the new attempt and initialize the question sessions
    $timenow = time(); // Update time now, in case the server is running really slowly.
    $attempt = quiz_create_attempt($quizobj, $attemptnumber, $lastattempt, $timenow, $ispreviewuser, $userid);

    if (!($quizobj->get_quiz()->attemptonlast && $lastattempt)) {
        $attempt = quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow,
                $forcedrandomquestions, $forcedvariants);
    } else {
        $attempt = quiz_start_attempt_built_on_last($quba, $attempt, $lastattempt);
    }

    $transaction = $DB->start_delegated_transaction();

    // Init the timemodifiedoffline for offline attempts.
    if ($offlineattempt) {
        $attempt->timemodifiedoffline = $attempt->timemodified;
    }
    $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt);

    $transaction->allow_commit();

    return $attempt;
}

/**
 * Check if the given calendar_event is either a user or group override
 * event for quiz.
 *
 * @param calendar_event $event The calendar event to check
 * @return bool
 */
function quiz_is_overriden_calendar_event(\calendar_event $event) {
    global $DB;

    if (!isset($event->modulename)) {
        return false;
    }

    if ($event->modulename != 'quiz') {
        return false;
    }

    if (!isset($event->instance)) {
        return false;
    }

    if (!isset($event->userid) && !isset($event->groupid)) {
        return false;
    }

    $overrideparams = [
        'quiz' => $event->instance
    ];

    if (isset($event->groupid)) {
        $overrideparams['groupid'] = $event->groupid;
    } else if (isset($event->userid)) {
        $overrideparams['userid'] = $event->userid;
    }

    return $DB->record_exists('quiz_overrides', $overrideparams);
}

/**
 * Retrieves tag information for the given list of quiz slot ids.
 * Currently the only slots that have tags are random question slots.
 *
 * Example:
 * If we have 3 slots with id 1, 2, and 3. The first slot has two tags, the second
 * has one tag, and the third has zero tags. The return structure will look like:
 * [
 *      1 => [
 *          quiz_slot_tags.id => { ...tag data... },
 *          quiz_slot_tags.id => { ...tag data... },
 *      ],
 *      2 => [
 *          quiz_slot_tags.id => { ...tag data... },
 *      ],
 *      3 => [],
 * ]
 *
 * @param int[] $slotids The list of id for the quiz slots.
 * @return array[] List of quiz_slot_tags records indexed by slot id.
 * @deprecated since Moodle 4.0
 * @todo Final deprecation on Moodle 4.4 MDL-72438
 */
function quiz_retrieve_tags_for_slot_ids($slotids) {
    debugging('Method quiz_retrieve_tags_for_slot_ids() is deprecated, ' .
        'see filtercondition->tags from the question_set_reference table.', DEBUG_DEVELOPER);
    global $DB;
    if (empty($slotids)) {
        return [];
    }

    $slottags = $DB->get_records_list('quiz_slot_tags', 'slotid', $slotids);
    $tagsbyid = core_tag_tag::get_bulk(array_filter(array_column($slottags, 'tagid')), 'id, name');
    $tagsbyname = false; // It will be loaded later if required.
    $emptytagids = array_reduce($slotids, function($carry, $slotid) {
        $carry[$slotid] = [];
        return $carry;
    }, []);

    return array_reduce(
        $slottags,
        function($carry, $slottag) use ($slottags, $tagsbyid, $tagsbyname) {
            if (isset($tagsbyid[$slottag->tagid])) {
                // Make sure that we're returning the most updated tag name.
                $slottag->tagname = $tagsbyid[$slottag->tagid]->name;
            } else {
                if ($tagsbyname === false) {
                    // We were hoping that this query could be avoided, but life
                    // showed its other side to us!
                    $tagcollid = core_tag_area::get_collection('core', 'question');
                    $tagsbyname = core_tag_tag::get_by_name_bulk(
                        $tagcollid,
                        array_column($slottags, 'tagname'),
                        'id, name'
                    );
                }
                if (isset($tagsbyname[$slottag->tagname])) {
                    // Make sure that we're returning the current tag id that matches
                    // the given tag name.
                    $slottag->tagid = $tagsbyname[$slottag->tagname]->id;
                } else {
                    // The tag does not exist anymore (neither the tag id nor the tag name
                    // matches an existing tag).
                    // We still need to include this row in the result as some callers might
                    // be interested in these rows. An example is the editing forms that still
                    // need to display tag names even if they don't exist anymore.
                    $slottag->tagid = null;
                }
            }

            $carry[$slottag->slotid][$slottag->id] = $slottag;
            return $carry;
        },
        $emptytagids
    );
}

/**
 * Get quiz attempt and handling error.
 *
 * @param int $attemptid the id of the current attempt.
 * @param int|null $cmid the course_module id for this quiz.
 * @return quiz_attempt $attemptobj all the data about the quiz attempt.
 * @throws moodle_exception
 */
function quiz_create_attempt_handling_errors($attemptid, $cmid = null) {
    try {
        $attempobj = quiz_attempt::create($attemptid);
    } catch (moodle_exception $e) {
        if (!empty($cmid)) {
            list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'quiz');
            $continuelink = new moodle_url('/mod/quiz/view.php', array('id' => $cmid));
            $context = context_module::instance($cm->id);
            if (has_capability('mod/quiz:preview', $context)) {
                throw new moodle_exception('attempterrorcontentchange', 'quiz', $continuelink);
            } else {
                throw new moodle_exception('attempterrorcontentchangeforuser', 'quiz', $continuelink);
            }
        } else {
            throw new moodle_exception('attempterrorinvalid', 'quiz');
        }
    }
    if (!empty($cmid) && $attempobj->get_cmid() != $cmid) {
        throw new moodle_exception('invalidcoursemodule');
    } else {
        return $attempobj;
    }
}
