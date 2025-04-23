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
 * A class for efficiently finds questions at random from the question bank.
 *
 * @package   core_question
 * @copyright 2015 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core_question\local\bank;

/**
 * This class efficiently finds questions at random from the question bank.
 *
 * You can ask for questions at random one at a time. Each time you ask, you
 * pass a category id, and whether to pick from that category and all subcategories
 * or just that category.
 *
 * The number of teams each question has been used is tracked, and we will always
 * return a question from among those elegible that has been used the fewest times.
 * So, if there are questions that have not been used yet in the category asked for,
 * one of those will be returned. However, within one instantiation of this class,
 * we will never return a given question more than once, and we will never return
 * questions passed into the constructor as $usedquestions.
 *
 * @copyright 2015 The Open University
 * @author    2021 Safat Shahin <safatshahin@catalyst-au.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class random_question_loader {
    /** @var \qubaid_condition which usages to consider previous attempts from. */
    protected $qubaids;

    /** @var array qtypes that cannot be used by random questions. */
    protected $excludedqtypes;

    /** @var array categoryid & include subcategories => num previous uses => questionid => 1. */
    protected $availablequestionscache = [];

    /**
     * @var array questionid => num recent uses. Questions that have been used,
     * but that is not yet recorded in the DB.
     */
    protected $recentlyusedquestions;

    /**
     * Constructor.
     *
     * @param \qubaid_condition $qubaids the usages to consider when counting previous uses of each question.
     * @param array $usedquestions questionid => number of times used count. If we should allow for
     *      further existing uses of a question in addition to the ones in $qubaids.
     */
    public function __construct(\qubaid_condition $qubaids, array $usedquestions = []) {
        // Store the condition object that defines which question usages to consider.
        $this->qubaids = $qubaids;

        // Initialize the recently used questions with the provided array.
        // This tracks questions that have been used recently but are not yet recorded in the database.
        // [For RS]: recentlyusedquestions = [id, id, id, id]
        // $this->recentlyusedquestions = $usedquestions; // Idk if previous attempt will mess up the RS
        $this->recentlyusedquestions = []; // Safer bet to ignore previous attempts

        // Iterate over all available question types in the question bank.
        foreach (\question_bank::get_all_qtypes() as $qtype) {
            // Check if the question type is not usable by random questions.
            if (!$qtype->is_usable_by_random()) {
                // Add the name of the excluded question type to the $excludedqtypes array.
                // This ensures that these question types are filtered out during random question selection.
                $this->excludedqtypes[] = $qtype->name();
            }
        }
    }

    /**
     * Pick a question at random from the given category, from among those with the fewest uses.
     * If an array of tag ids are specified, then only the questions that are tagged with ALL those tags will be selected.
     *
     * It is up the the caller to verify that the cateogry exists. An unknown category
     * behaves like an empty one.
     *
     * @param int $categoryid the id of a category in the question bank.
     * @param bool $includesubcategories wether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param array $tagids An array of tag ids. A question has to be tagged with all the provided tagids (if any)
     *      in order to be eligible for being picked.
     * @return int|null the id of the question picked, or null if there aren't any.
     */
    public function get_next_question_id($categoryid, $includesubcategories, $tagids = []): ?int {
        // [Modify] Ensure that questions for the given category, subcategories, and tags are loaded into the cache.
        $this->ensure_questions_for_category_loaded($categoryid, $includesubcategories, $tagids); // Modify this for the RS

        // Generate a unique key for the cache based on the category, subcategories, and tags.
        $categorykey = $this->get_category_key($categoryid, $includesubcategories, $tagids);

        // Check if there are any available questions in the cache for the given category key.
        if (empty($this->availablequestionscache[$categorykey])) {
            // If no questions are available, return null.
            return null;
        }

        // Reset the internal pointer of the array to the first element.
        reset($this->availablequestionscache[$categorykey]);
         
        // [Legacy] for the randomizer
        // // Get the usage count (key) of the group of questions with the fewest uses.
        // $lowestcount = key($this->availablequestionscache[$categorykey]);
        // // Reset the internal pointer of the array for the group with the fewest uses.
        // reset($this->availablequestionscache[$categorykey][$lowestcount]);
        // // Get the ID of the first question in the group with the fewest uses.
        // $questionid = key($this->availablequestionscache[$categorykey][$lowestcount]);

        // [Modify] Get the question ID of the pointer
        $questionid = current($this->availablequestionscache[$categorykey]);

        // Mark the selected question as "used" to ensure it is not selected again in the same session.
        $this->use_question($questionid, $categorykey);

        // API testing
        // $url = "http://127.0.0.1:5000/predict";  // Flask API URL
        // $data = array("input" => array(6)); // Example input: 6
        // $options = array(
        //     "http" => array(
        //         "header"  => "Content-Type: application/json",
        //         "method"  => "POST",
        //         "content" => json_encode($data)
        //     )
        // );
        // $context  = stream_context_create($options);
        // $result = file_get_contents($url, false, $context);
        // if ($result === FALSE) {
        //     die("Error calling API");
        // }
        // $response = json_decode($result, true);
        // error_log("Prediction: " . $response["prediction"][0]);

        // Return the ID of the selected question.
        return $questionid;
    }

    /**
     * Get the key into {@see $availablequestionscache} for this combination of options.
     *
     * @param int $categoryid the id of a category in the question bank.
     * @param bool $includesubcategories wether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param array $tagids an array of tag ids.
     * @return string the cache key.
     */
    protected function get_category_key($categoryid, $includesubcategories, $tagids = []): string {
        // Check if subcategories should be included.
        if ($includesubcategories) {
            // If yes, append '|1' to the category ID to indicate inclusion of subcategories.
            $key = $categoryid . '|1';
        } else {
            // If no, append '|0' to the category ID to indicate exclusion of subcategories.
            $key = $categoryid . '|0';
        }

        // Check if there are any tag IDs provided.
        if (!empty($tagids)) {
            // If tags are provided, append them to the key, separated by '|'.
            $key .= '|' . implode('|', $tagids);
        }

        // Return the generated key as a string.
        return $key;
    }

    /**
     * Populate {@see $availablequestionscache} for this combination of options.
     *
     * @param int $categoryid The id of a category in the question bank.
     * @param bool $includesubcategories Whether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param array $tagids An array of tag ids. If an array is provided, then
     *      only the questions that are tagged with ALL the provided tagids will be loaded.
     */
    protected function ensure_questions_for_category_loaded($categoryid, $includesubcategories, $tagids = []): void {
        global $DB; // Access the global database object for executing queries.

        // Generate a unique cache key for this combination of category, subcategories, and tags.
        $categorykey = $this->get_category_key($categoryid, $includesubcategories, $tagids);

        // Check if the data for this key is already in the cache.
        if (isset($this->availablequestionscache[$categorykey])) {
            // Data is already in the cache, nothing to do.
            return;
        }

        // Load the list of category IDs based on whether subcategories are included.
        if ($includesubcategories) {
            // If subcategories are included, get the list of all category IDs in the hierarchy.
            $categoryids = question_categorylist($categoryid);
        } else {
            // If subcategories are not included, only use the specified category ID.
            $categoryids = [$categoryid];
        }

        // Prepare SQL conditions to exclude question types that are not usable by random questions.
        list($extraconditions, $extraparams) = $DB->get_in_or_equal(
            $this->excludedqtypes, // List of excluded question types.
            SQL_PARAMS_NAMED, // Use named parameters for the SQL query.
            'excludedqtype', // Exclude the specified question types.
            false);

        // Retrieve questions and its usage count from the database that match the specified criteria.
        $questionidsandcounts = \question_bank::get_finder()->get_questions_from_categories_and_tags_with_usage_counts(
                $categoryids, // List of category IDs to search in. 
                $this->qubaids, // Condition object for filtering based on previous usage.
                'q.qtype ' . $extraconditions, // SQL condition for excluding question types.
                $extraparams, // Parameters for the SQL query.
                $tagids); // List of tag IDs to filter questions by.

        // Check if no questions were found for the given criteria.
        if (!$questionidsandcounts) {
            // If no questions are found, store an empty array in the cache for this key.
            $this->availablequestionscache[$categorykey] = [];
            return;
        }

        // [New for RS] remove usage counts as we do not need them. Transform $questionidsandcounts into just the ids.
        $questionids = []; // Initialize an empty array to store question IDs.
        foreach ($questionidsandcounts as $questionid => $prevusecount) { 
            // Iterate over the question IDs.
            $questionids[] = $questionid; // Store only the question ID, ignoring the usage count.
        } 

        // [Not needed for RS] Group questions by their usage count.
        // $idsbyusecount = []; // Initialize an empty array to store questions grouped by usage count.
        // foreach ($questionidsandcounts as $questionid => $prevusecount) {
        //     // Skip questions that are in the recently used questions list.
        //     if (isset($this->recentlyusedquestions[$questionid])) {
        //         // Recently used questions are never returned.
        //         continue; // Skip this question (recently used) and move to the next one.
        //     }
        //     // Add the question ID to the group corresponding to its usage count.
        //     $idsbyusecount[$prevusecount][] = $questionid;
        // }

        // [Not needed for RS] Now put that grouped questions data into our cache. For each count, we need to shuffle
        // questionids, and make those the keys of an array.
        // $this->availablequestionscache[$categorykey] = []; // Initialize the cache for this key.
        // foreach ($idsbyusecount as $prevusecount => $questionids) {
        //     // Shuffle the question IDs to randomize their order.
        //     shuffle($questionids);

        //     // Store the shuffled question IDs in the cache, with the usage count as the key.
        //     $this->availablequestionscache[$categorykey][$prevusecount] = array_combine(
        //             $questionids, // Keys: Question IDs.
        //             array_fill(0, count($questionids), 1)); // Values: All set to 1.
        // }

        // [Not needed for RS] Sort the cache by usage count to ensure questions with the fewest uses are accessed first.
        // ksort($this->availablequestionscache[$categorykey]);

        // [New for RS] store valid question IDs in the cache.
        foreach ($questionids as $questionid) {
            // Skip questions that are in the recently used questions list.
            if (in_array($questionid, $this->recentlyusedquestions)) {
                // error_log('Skipped questionid: ' . $questionid); // Debugging log for skipped question IDs.
                continue; // Skip this question (recently used) and move to the next one.
            }
           
            // error_log('Added questionid: ' . $questionid); // Debugging log for added question IDs.

            // Store the question IDs in the cache
            $this->availablequestionscache[$categorykey][] = $questionid;
        } 
    }

    /**
     * Update the internal data structures to indicate that a given question has
     * been used one more time.
     *
     * @param int $questionid the question that is being used.
     * @param int $categorykey the category key for the question. [Added for RS]
     */
    protected function use_question($questionid, $categorykey): void {
        // [Legacy] Check if the question ID is already in the recently used questions list.
        // if (isset($this->recentlyusedquestions[$questionid])) {
        //     // If it is, increment the usage count for that question.
        //     $this->recentlyusedquestions[$questionid] += 1;
        // } else {
        //     // If it is not, add it to the recently used questions list with a usage count of 1.
        //     $this->recentlyusedquestions[$questionid] = 1;
        // }

        // [Legacy] Iterate over all categories in the available questions cache.
        // foreach ($this->availablequestionscache as $categorykey => $questionsforcategory) {
        //     // Iterate over each usage count group within the current category.
        //     foreach ($questionsforcategory as $numuses => $questionids) {
        //         // Check if the question ID is in the current usage count group.
        //         if (!isset($questionids[$questionid])) {
        //             // If the question ID is not in this group, skip to the next group.
        //             continue;
        //         }

        //         // Remove the question ID from the current usage count group.
        //         unset($this->availablequestionscache[$categorykey][$numuses][$questionid]);

        //         // Check if the current usage count group is now empty.
        //         if (empty($this->availablequestionscache[$categorykey][$numuses])) {
        //             // If the group is empty, remove it from the cache.
        //             unset($this->availablequestionscache[$categorykey][$numuses]);
        //         }
        //     }
        // }

        // For the RS, simpler logic
        // Add the question ID to the recently used questions list.
        $this->recentlyusedquestions[] = $questionid;

        // Remove the question ID from the available questions cache.
        $this->availablequestionscache[$categorykey] = array_values(array_filter(
            $this->availablequestionscache[$categorykey], 
            fn($id) => $id !== $questionid
        ));

        // error_log('Used questionid: ' . $questionid);
        // error_log('Recently used questions: ' . implode(', ', $this->recentlyusedquestions)); // Debugging log for recently used questions.
        // error_log('Available questions cache: ' . json_encode($this->availablequestionscache)); // Debugging log for available questions cache.
    }

    /**
     * Get the list of available question ids for the given criteria.
     *
     * @param int $categoryid The id of a category in the question bank.
     * @param bool $includesubcategories Whether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param array $tagids An array of tag ids. If an array is provided, then
     *      only the questions that are tagged with ALL the provided tagids will be loaded.
     * @return int[] The list of question ids
     */
    protected function get_question_ids($categoryid, $includesubcategories, $tagids = []): array {
        // Ensure that questions for the given category, subcategories, and tags are loaded into the cache.
        $this->ensure_questions_for_category_loaded($categoryid, $includesubcategories, $tagids);

        // Generate a unique key for the cache based on the category, subcategories, and tags.
        $categorykey = $this->get_category_key($categoryid, $includesubcategories, $tagids);

        // Retrieve the cached values (that contains question ids) for the given category key.
        $cachedvalues = $this->availablequestionscache[$categorykey] ?? [];

        // Return the list of question IDs.
        return $cachedvalues;
    }

    /**
     * Check whether a given question is available in a given category. If so, mark it used.
     * If an optional list of tag ids are provided, then the question must be tagged with
     * ALL of the provided tags to be considered as available.
     *
     * @param int $categoryid the id of a category in the question bank.
     * @param bool $includesubcategories wether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param int $questionid the question that is being used.
     * @param array $tagids An array of tag ids. Only the questions that are tagged with all the provided tagids can be available.
     * @return bool whether the question is available in the requested category.
     */
    public function is_question_available($categoryid, $includesubcategories, $questionid, $tagids = []): bool {
        // Ensure that questions for the given category, subcategories, and tags are loaded into the cache.
        $this->ensure_questions_for_category_loaded($categoryid, $includesubcategories, $tagids);

        // Generate a unique key for the cache based on the category, subcategories, and tags.
        $categorykey = $this->get_category_key($categoryid, $includesubcategories, $tagids);

        // Iterate over all usage count groups in the cache for the given category key.
        foreach ($this->availablequestionscache[$categorykey] as $questionids) {
            // Check if the question ID exists in the current usage count group.
            if (isset($questionids[$questionid])) {
                // If the question is available, mark it as used.
                $this->use_question($questionid, $categorykey);

                // Return true to indicate that the question is available.
                return true;
            }
        }

        // If the question is not found in any usage count group, return false.
        return false;
    }

    /**
     * Get the list of available questions for the given criteria.
     *
     * @param int $categoryid The id of a category in the question bank.
     * @param bool $includesubcategories Whether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param array $tagids An array of tag ids. If an array is provided, then
     *      only the questions that are tagged with ALL the provided tagids will be loaded.
     * @param int $limit Maximum number of results to return.
     * @param int $offset Number of items to skip from the begging of the result set.
     * @param string[] $fields The fields to return for each question.
     * @return \stdClass[] The list of question records
     */
    public function get_questions($categoryid, $includesubcategories, $tagids = [], $limit = 100, $offset = 0, $fields = []) {
        global $DB; // Access the global database object for executing queries.

        // Retrieve the list of question IDs that match the given criteria.
        $questionids = $this->get_question_ids($categoryid, $includesubcategories, $tagids);

        // If no question IDs are found, return an empty array.
        if (empty($questionids)) {
            return [];
        }

        // Determine which fields to return for each question.
        if (empty($fields)) {
            // Return all fields.
            $fieldsstring = '*';
        } else {
            // Convert the array of fields into a comma-separated string.
            $fieldsstring = implode(',', $fields);
        }

        // Create the query to get the questions (validate that at least we have a question id. If not, do not execute the sql).
        $hasquestions = false;
        if (!empty($questionids)) {
            $hasquestions = true;
        }

        // If there are question IDs, build and execute the SQL query.
        if ($hasquestions) {
            // Generate the SQL condition for filtering by question IDs.
            list($condition, $param) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED, 'questionid');
            $condition = 'WHERE q.id ' . $condition;

            // Build the SQL query to retrieve the question records.
            $sql = "SELECT {$fieldsstring}
                      FROM (SELECT q.*, qbe.questioncategoryid as category
                      FROM {question} q
                      JOIN {question_versions} qv ON qv.questionid = q.id
                      JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                      {$condition}) q ORDER BY q.id";

            // Execute the SQL query and return the results.
            return $DB->get_records_sql($sql, $param, $offset, $limit);
        } else {
            // If no question IDs are available, return an empty array.
            return [];
        }
    }

    /**
     * Count the number of available questions for the given criteria.
     *
     * @param int $categoryid The id of a category in the question bank.
     * @param bool $includesubcategories Whether to pick a question from exactly
     *      that category, or that category and subcategories.
     * @param array $tagids An array of tag ids. If an array is provided, then
     *      only the questions that are tagged with ALL the provided tagids will be loaded.
     * @return int The number of questions matching the criteria.
     */
    public function count_questions($categoryid, $includesubcategories, $tagids = []): int {
        $questionids = $this->get_question_ids($categoryid, $includesubcategories, $tagids);
        return count($questionids);
    }
}
