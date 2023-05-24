<?php
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
 * External Web Service Template
 * @package local
 * @subpackage bs_webservicesuite
 * @author     Brain station 23 ltd <brainstation-23.com>
 * @copyright  2023 Brain station 23 ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->libdir . "/externallib.php");
require_once($CFG->dirroot . "/course/externallib.php");

use core_completion\progress;

class local_bs_webservicesuite_external extends core_course_external {
    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */

    public static function get_topic_list_by_courseid_parameters(){
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'Course ID'),
            )
        );
    }

    /**
     * Get Topic List
     *
     * @param int $courseid ID of the Course
     * @return array of course completion status and warnings
     * @since Moodle 2.9
     * @throws moodle_exception
     */

    public static function get_topic_list_by_courseid($courseid){
        global $CFG, $DB, $USER, $PAGE;

        $params = self::validate_parameters(self::get_topic_list_by_courseid_parameters(),
                        array('courseid' => $courseid));

        $filters = array();

        //retrieve the course
        $course = $DB->get_record('course', array('id' => $params['courseid']), '*', MUST_EXIST);

        $context = context_course::instance($course->id, IGNORE_MISSING);
        
        //create return value
        $coursecontents = array();

        if ($course->visible
                or has_capability('moodle/course:viewhiddencourses', $context)){

            //retrieve sections
            $modinfo = get_fast_modinfo($course);
            $sections = $modinfo->get_section_info_all();
            $courseformat = course_get_format($course);
            $coursenumsections = $courseformat->get_last_section_number();
            $stealthmodules = array();   // Array to keep all the modules available but not visible in a course section/topic.

            $completioninfo = new completion_info($course);

            //for each sections (first displayed to last displayed)
            $modinfosections = $modinfo->get_sections();
            foreach ($sections as $key => $section){

                // This becomes true when we are filtering and we found the value to filter with.
                $sectionfound = false;

                $sectionvalues = array();
                $sectionvalues['id'] = $section->id;
                $sectionvalues['name'] = get_section_name($course, $section);
                $sectionvalues['visible'] = $section->visible;

                $options = (object) array('noclean' => true);
                list($sectionvalues['summary'], $sectionvalues['summaryformat']) =
                        external_format_text($section->summary, $section->summaryformat,
                                $context->id, 'course', 'section', $section->id, $options);
                $sectionvalues['section'] = $section->section;
                $sectionvalues['hiddenbynumsections'] = $section->section > $coursenumsections ? 1 : 0;
                $sectionvalues['uservisible'] = $section->uservisible;
                if (!empty($section->availableinfo)) {
                    $sectionvalues['availabilityinfo'] = \core_availability\info::format_info($section->availableinfo, $course);
                }

                $sectioncontents = array();

                // For each module of the section.
                if (empty($filters['excludemodules']) and !empty($modinfosections[$section->section])){

                    foreach ($modinfosections[$section->section] as $cmid) {

                        $cm = $modinfo->cms[$cmid];
                        $cminfo = cm_info::create($cm);
                        $activitydates = \core\activity_dates::get_dates_for_module($cminfo, $USER->id);

                        // Stop here if the module is not visible to the user on the course main page:
                        // The user can't access the module and the user can't view the module on the course page.
                        if (!$cm->uservisible && !$cm->is_visible_on_course_page()) {
                            continue;
                        }

                        // This becomes true when we are filtering and we found the value to filter with.
                        $modfound = false;

                        $module = array();

                        $modcontext = context_module::instance($cm->id);

                        //common info (for people being able to see the module or availability dates)
                        $module['id'] = $cm->id;
                        $module['name'] = external_format_string($cm->name, $modcontext->id);
                        $module['instance'] = $cm->instance;
                        $module['modname'] = (string) $cm->modname;
                        $module['modicon'] = $cm->get_icon_url()->out(false);

                        //url of the module
                        $url = $cm->url;
                        if ($url) { //labels don't have url
                            $module['url'] = $url->out(false);
                        }

                        //CAN BE REMOVED BY ME
                        $canviewhidden = has_capability('moodle/course:viewhiddenactivities',
                        context_module::instance($cm->id));

                        // Assign result to $sectioncontents, there is an exception,
                        // stealth activities in non-visible sections for students go to a special section.
                        if (!empty($filters['includestealthmodules']) && !$section->uservisible && $cm->is_stealth()) {
                            $stealthmodules[] = $module;
                        } else {
                            $sectioncontents[] = $module;
                        }

                        // If we just did a filtering, break the loop.
                        if ($modfound) {
                            break;
                        }

                    }

                }
                $sectionvalues['modules'] = $sectioncontents;

                // assign result to $coursecontents
                $coursecontents[$key] = $sectionvalues;

                // Break the loop if we are filtering.
                if ($sectionfound) {
                    break;
                }

            }

            // Now that we have iterated over all the sections and activities, check the visibility.
            // We didn't this before to be able to retrieve stealth activities.
            foreach ($coursecontents as $sectionnumber => $sectioncontents) {
                $section = $sections[$sectionnumber];

                if (!$courseformat->is_section_visible($section)) {
                    unset($coursecontents[$sectionnumber]);
                    continue;
                }

                // Remove section and modules information if the section is not visible for the user.
                if (!$section->uservisible) {
                    $coursecontents[$sectionnumber]['modules'] = array();
                    // Remove summary information if the section is completely hidden only,
                    // even if the section is not user visible, the summary is always displayed among the availability information.
                    if (!$section->visible) {
                        $coursecontents[$sectionnumber]['summary'] = '';
                    }
                }
            }

            // Include stealth modules in special section (without any info).
            if (!empty($stealthmodules)) {
                $coursecontents[] = array(
                    'id' => -1,
                    'name' => '',
                    'summary' => '',
                    'summaryformat' => FORMAT_MOODLE,
                    'modules' => $stealthmodules
                );
            }

        }
        return $coursecontents;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */

    public static function get_topic_list_by_courseid_returns(){
        $completiondefinition = \core_completion\external\completion_info_exporter::get_read_structure(VALUE_DEFAULT, []);

        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Section ID'),
                    'name' => new external_value(PARAM_RAW, 'Section name'),
                    'visible' => new external_value(PARAM_INT, 'is the section visible', VALUE_OPTIONAL),
                    'summary' => new external_value(PARAM_RAW, 'Section description'),
                    'summaryformat' => new external_format_value('summary'),
                    'section' => new external_value(PARAM_INT, 'Section number inside the course', VALUE_OPTIONAL),
                    'hiddenbynumsections' => new external_value(PARAM_INT, 'Whether is a section hidden in the course format',
                                                                VALUE_OPTIONAL),
                    'uservisible' => new external_value(PARAM_BOOL, 'Is the section visible for the user?', VALUE_OPTIONAL),
                    'availabilityinfo' => new external_value(PARAM_RAW, 'Availability information.', VALUE_OPTIONAL),
                    'modules' => new external_multiple_structure(
                            new external_single_structure(
                                array(
                                    'id'       => new external_value(PARAM_INT, 'activity id'),
                                    'url'      => new external_value(PARAM_URL, 'activity url', VALUE_OPTIONAL),
                                    'name'     => new external_value(PARAM_RAW, 'activity module name'),
                                    'instance' => new external_value(PARAM_INT, 'instance id', VALUE_OPTIONAL),
                                    'modicon' => new external_value(PARAM_URL, 'activity icon url'),
                                    'modname' => new external_value(PARAM_PLUGIN, 'activity module type'),
                                )
                            ), 'list of module'
                    )
                )
            )
        );
        
    }
}



