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

defined('MOODLE_INTERNAL') || die();

require_once ($CFG->libdir . '/accesslib.php');

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
        global $DB; // Ensures use of Moodle data-manipulation api.

        /* Get array of Role IDs.
         * ---------------------- */
        $roleslist = array();
        $roleslist = $DB->get_records('role');
        $roles = array();
        foreach ($roleslist as $r) {
            $roles[$r->shortname] = $r->id;
        }
//        print_r($roles);

        /* Get array of Category IDs.
         * -------------------------- */
        $categories = array();
        $categories = $DB->get_records('course_categories');
//        print_r($categories);
        $catid = array();
        foreach ($categories as $category) {
            $catid[$category->idnumber] = $category->id;
        }
//        print_r ($catid);

        /* Get array of contexts.
         * ---------------------- */
        $contexts = array();
        $sql = 'SELECT * FROM {context} WHERE contextlevel = ' . CONTEXT_COURSECAT;
        $contexts = $DB->get_records_sql($sql);
//        print_r($contexts);
        $catcontext = array();
        foreach ($contexts as $context) {
            $catcontext[$context->instanceid] = $context->id;
        }
//        print_r($catcontext);

        /* Get Academic Leads from usr_data_categorymembers.
         * ------------------------------------------------- */
        $sourcetable1 = 'usr_data_categorymembers';
        /***************************************
         * usr_data_categorymembers            *
         *     id                              *
         *     staffnumber                     *
         *     category_idnumber               *
         *     role                            *
         ***************************************/
        $sql = 'SELECT * FROM ' . $sourcetable1;
        $acleads = array();
        $acleads = $DB->get_records_sql($sql);
//        print_r($acleads);
        foreach ($acleads as $aclead) {
//            print_r($aclead);
            $useridnumber = $aclead->staffnumber;
            $sqluser = "SELECT * FROM {user} WHERE username = '".$useridnumber."'";
//            echo $sqluser;
            $userid = '';
            if ($DB->get_record_sql($sqluser)) {
                $userid = $DB->get_record_sql($sqluser);  // User ID.
            }
            print_r ($userid);
            if ($userid->deleted == 1) {
                continue;
            }
//            echo 'userid:'.$userid->id.' ';
            $role = $aclead->role;
            $roleid = '';
            if ($roles[$role]) {
                $roleid = $roles[$role];  // Role ID.
            }
//            echo 'roleid:'.$roleid.' ';
            $catidnumber = $aclead->category_idnumber;
//            echo $catidnumber;
            $categoryid = '';
            $catcontextid = '';
            if ($catid[$catidnumber]) {
                $categoryid = $catid[$catidnumber];
//                echo 'categoryid:'.$categoryid.' ';
                $catcontextid = $catcontext[$categoryid];  //Context ID.
            }

            if ($userid !== '' && $roleid !== '' && $catcontextid !== '') {
//                echo '<p>' . $userid->id . ' : ' . $roleid . ' : ' . $catcontextid . '</p>';
                role_assign($roleid, $userid->id, $catcontextid);
            }
        }

        /* Get all Staff Users within a subject community.
         * ----------------------------------------------- */

        /* Get all course pages within a subject community or lower category. */
        // Get all categories with an idnumber SUB.....
        // FOREACH SUB parse for sub-categories


        /* Get all users with given role/capability?? OR
         * Get all users with set email pattern. --PROBABLY THIS
         */
    }
}
