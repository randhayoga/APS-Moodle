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
 * Strings for component 'qtype_random', language 'en', branch 'MOODLE_20_STABLE'
 *
 * @package    qtype
 * @subpackage random
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['configselectmanualquestions'] = 'Can the random question type select a manually graded question when it is making its random choice of a question from a category?';
$string['includingsubcategories'] = 'Including subcategories';
$string['pluginname'] = 'Recommender System';
$string['pluginname_help'] = 'An RS provided question is not a question type as such, but is a way of inserting a recommender system chosen question into an activity.';
# $string['pluginname_help'] = 'A random question is not a question type as such, but is a way of inserting a randomly-chosen question from a specified category into an activity.';
$string['pluginnameediting'] = 'Editing an RS provided question';
$string['privacy:metadata'] = 'The Random question type plugin does not store any personal data.';
$string['randomqname'] = 'RS provided ({$a})';
$string['randomqnamefromtop'] = 'Faulty random question! Please delete this question.';
$string['randomqnamefromtoptags'] = 'Faulty random question! Please delete this question.';
$string['randomqnametags'] = 'RS provided ({$a->category}, tags: {$a->tags})';
$string['randomqplusname'] = 'RS provided ({$a} and subcategories)';
$string['randomqplusnamecourse'] = 'RS provided (Any category in this course)';
$string['randomqplusnamecoursecat'] = 'RS provided (Any category inside course category {$a})';
$string['randomqplusnamecoursecattags'] = 'RS provided (Any category inside course category {$a->category}, tags: {$a->tags})';
$string['randomqplusnamecoursetags'] = 'RS provided (Any category in this course, tags: {$a->tags})';
$string['randomqplusnamemodule'] = 'RS provided (Any category of this quiz)';
$string['randomqplusnamemoduletags'] = 'RS provided (Any category of this quiz, tags: {$a->tags})';
$string['randomqplusnamesystem'] = 'RS provided (Any system-level category)';
$string['randomqplusnamesystemtags'] = 'RS provided (Any system-level category, tags: {$a->tags})';
$string['randomqplusnametags'] = 'RS provided ({$a->category} and subcategories, tags: {$a->tags})';
$string['selectedby'] = '{$a->questionname} selected by {$a->randomname}';
$string['selectmanualquestions'] = 'RS provided questions can use manually graded questions';
$string['taskunusedrandomscleanup'] = 'Remove unused random questions';
