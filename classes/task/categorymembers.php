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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_categorymembers
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_categorymembers\task;
use stdClass;
use has_capability;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/accesslib.php');

/**
 * A scheduled task for scripted database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class categorymembers extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('categorymembers', 'local_categorymembers');
    }

    /**
     * Run sync.
     */
    public function execute() {
        global $CFG, $DB; // Ensures use of Moodle data-manipulation api.

        /* Get array of Role IDs.
         * ---------------------- */
        $roleslist = array();
        $roleslist = $DB->get_records('role');
        $roles = array();
        foreach ($roleslist as $r) {
            $roles[$r->shortname] = $r->id;
        }

        /* Get array of Category IDs.
         * -------------------------- */
        $categories = array();
        $categories = $DB->get_records('course_categories');
        $catid = array();
        $subcommcats = array();
        $subcommidnum = array();
        foreach ($categories as $category) {
            $catid[$category->idnumber] = $category->id;  // Get ALL categories.
            if (strpos($category->idnumber, 'SUB-') === false || strpos($category->idnumber, 'SUB-') > 0) {
                continue;
            }
            $subcommcats[$category->idnumber] = $category->id;  // Get Subject Communities ids.
            $subcommidnum[$category->id] = $category->idnumber;  // Get Subject Community idnumbers.
        }

        /* Get array of contexts for categories.
         * ------------------------------------- */
        $contexts = array();
        $sql = 'SELECT * FROM {context} WHERE contextlevel = ' . CONTEXT_COURSECAT;
        $contexts = $DB->get_records_sql($sql);
        $catcontext = array();
        $subjcommcontext = array();
        foreach ($contexts as $context) {
            $catcontext[$context->instanceid] = $context->id; // Get context for ALL categories.
            if (in_array($context->instanceid, $subcommcats)) { // Get context for Subject Communities.
                $subjcommcontext[$context->instanceid] = $context->id;
            }
        }

        /*****************************************************
         * Get Academic Leads from usr_data_categorymembers. *
         *****************************************************/
        $sourcetable1 = 'usr_data_categorymembers';
        $sql = 'SELECT * FROM ' . $sourcetable1;
        $acleads = array();
        $acleads = $DB->get_records_sql($sql);
        foreach ($acleads as $aclead) {

            $useridnumber = $aclead->staffnumber;
            $sqluser = "SELECT * FROM {user} WHERE username = '".$useridnumber."'";
            $userid = '';
            if ($DB->get_record_sql($sqluser)) {
                $userid = $DB->get_record_sql($sqluser);  // User ID.
            }
            if ($userid->deleted == 1) {
                continue;
            }

            $role = $aclead->role;
            $roleid = '';
            if ($roles[$role]) {
                $roleid = $roles[$role];  // Role ID.
            }

            $catidnumber = $aclead->category_idnumber;
            $categoryid = '';
            $catcontextid = '';
            if ($catid[$catidnumber]) {
                $categoryid = $catid[$catidnumber];
                $catcontextid = $catcontext[$categoryid];  // Context ID.
            }

            if ($userid !== '' && $roleid !== '' && $catcontextid !== '') {
                role_assign($roleid, $userid->id, $catcontextid);
            }
        }

        /***************************************************
         * Get all Staff Users within a subject community. *
         ***************************************************/
        // Set constants for all staff.
        // ----------------------------
        // Get category non editing role id.
        $catnonedrole = "categorynoneditor";
        $catnoned = $DB->get_record('role', array('shortname' => $catnonedrole));
        $catnonedid = $catnoned->id;

        // Get module site editing roles.
        $modeditselect = "shortname = 'moduletutor'
                           OR shortname = 'co-editingtutor'
                           OR shortname = 'editor'";
        $modeditrole = $DB->get_records_select('role', $modeditselect);
        // Split multidimensional array to get single array.
        $modeditid = array();
        foreach ($modeditrole as $me) {
            $modeditid[] = $me->id;
        }

        // Set context levels.
        $contextcatlevel = CONTEXT_COURSECAT;
        $contextcourselevel = CONTEXT_COURSE;

        // Get all courses in each subject community (and sub-category).
        // -------------------------------------------------------------

        // Loop through each subj comm.
        foreach ($subcommcats as $sc) {  // For each subj comunity by id.
            $staff = array();  // Reset staff list array for each subj comm loop.
            $scomm = $DB->get_record('context', array('instanceid' => $sc, 'contextlevel' => $contextcatlevel));

            $scidnumber = $subcommidnum[$sc]; // Get subj community idnumber
            $sccont = $scomm->id;  // Get subj community context.

            // Get all courses in subj comm category (and sub categories).
            $course = array();
            $crssql = "SELECT mdl_course.id
                            FROM mdl_course
                            JOIN mdl_course_categories
                            WHERE mdl_course.category = mdl_course_categories.id
                                AND (
                                    mdl_course_categories.path LIKE '%/$sc/%'
                                    OR mdl_course_categories.path LIKE '%/$sc'
                                )";
            $course[$sc] = $DB->get_records_sql($crssql);

            // Get course ID and Context.
            $crscontsql = array();
            foreach ($course[$sc] as $crs) {
                $c = $crs->id; // Get id of each course in subj comm.

                $crscontsql = "SELECT * FROM mdl_context
                                WHERE contextlevel = $contextcourselevel
                                AND instanceid = $c";
                $crscont = $DB->get_record_sql($crscontsql);
                $cc = $crscont->id; // Get context of each course in subj comm.

                // Get staff on course.
                // Currently by Role - should be by capability: Future refinement.
                $modids = implode(",", $modeditid); // Create list of module ids from array.
                $enrolmentssql = "SELECT DISTINCT ue.id,ue.userid,ra.roleid FROM mdl_user_enrolments ue
                                JOIN mdl_enrol e ON e.id = ue.enrolid
                                JOIN mdl_role_assignments ra ON ue.userid = ra.userid
                                WHERE e.courseid = $c
                                AND ra.roleid IN ('".$modids."')";
                $enrolments = $DB->get_records_sql($enrolmentssql);

                // Add staff to Subj Comm category.
                foreach ($enrolments as $enrols) {
                    $user = $DB->get_record('user', array('id' => $enrols->userid));
                    if (!$user->deleted) {  // Check status of user to ensure not deleted.
                        role_assign($catnonedid, $enrols->userid, $sccont);
                    }
                }
            }
        }

    }
}
