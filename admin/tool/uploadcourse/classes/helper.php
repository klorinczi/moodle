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
 * File containing the helper class.
 *
 * @package    tool_uploadcourse
 * @copyright  2013 Frédéric Massart, 2017 Konrad Lorinczi (implemented automatic category creation from CSV)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/coursecatlib.php');
require_once($CFG->dirroot . '/cache/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Class containing a set of helpers.
 *
 * @package    tool_uploadcourse
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploadcourse_helper {

    /** @var object course object from course class */
    protected static $course_obj;

    /** @var int virtual counter for simulating category creation */
    protected static $virtual_id_counter;

    /** @var object virtual category table to simulate category creation */
    protected static $virtual_category_table;
    
    /**
     * Save course object
     *
     * @param int $course_obj course object to reuse in this class
     */
    public static function set_course_obj($course_obj) {
        self::$course_obj = $course_obj;
    }
    

    /**
     * Generate a shortname based on a template.
     *
     * @param array|object $data course data.
     * @param string $templateshortname template of shortname.
     * @return null|string shortname based on the template, or null when an error occured.
     */
    public static function generate_shortname($data, $templateshortname) {
        if (empty($templateshortname) && !is_numeric($templateshortname)) {
            return null;
        }
        if (strpos($templateshortname, '%') === false) {
            return $templateshortname;
        }

        $course = (object) $data;
        $fullname   = isset($course->fullname) ? $course->fullname : '';
        $idnumber   = isset($course->idnumber) ? $course->idnumber  : '';

        $callback = partial(array('tool_uploadcourse_helper', 'generate_shortname_callback'), $fullname, $idnumber);
        $result = str_replace_callback('/(?<!%)%([+~-])?(\d)*([fi])/', $callback, $templateshortname);

        if (!is_null($result)) {
            $result = clean_param($result, PARAM_TEXT);
        }

        if (empty($result) && !is_numeric($result)) {
            $result = null;
        }

        return $result;
    }

    /**
     * Callback used when generating a shortname based on a template.
     *
     * @param string $fullname full name.
     * @param string $idnumber ID number.
     * @param array $block result from str_replace_callback.
     * @return string
     */
    public static function generate_shortname_callback($fullname, $idnumber, $block) {
        switch ($block[3]) {
            case 'f':
                $repl = $fullname;
                break;
            case 'i':
                $repl = $idnumber;
                break;
            default:
                return $block[0];
        }

        switch ($block[1]) {
            case '+':
                $repl = core_text::strtoupper($repl);
                break;
            case '-':
                $repl = core_text::strtolower($repl);
                break;
            case '~':
                $repl = core_text::strtotitle($repl);
                break;
        }

        if (!empty($block[2])) {
            $repl = core_text::substr($repl, 0, $block[2]);
        }

        return $repl;
    }

    /**
     * Return the available course formats.
     *
     * @return array
     */
    public static function get_course_formats() {
        return array_keys(core_component::get_plugin_list('format'));
    }

    /**
     * Extract enrolment data from passed data.
     *
     * Constructs an array of methods, and their options:
     * array(
     *     'method1' => array(
     *         'option1' => value,
     *         'option2' => value
     *     ),
     *     'method2' => array(
     *         'option1' => value,
     *         'option2' => value
     *     )
     * )
     *
     * @param array $data data to extract the enrolment data from.
     * @return array
     */
    public static function get_enrolment_data($data) {
        $enrolmethods = array();
        $enroloptions = array();
        foreach ($data as $field => $value) {

            // Enrolmnent data.
            $matches = array();
            if (preg_match('/^enrolment_(\d+)(_(.+))?$/', $field, $matches)) {
                $key = $matches[1];
                if (!isset($enroloptions[$key])) {
                    $enroloptions[$key] = array();
                }
                if (empty($matches[3])) {
                    $enrolmethods[$key] = $value;
                } else {
                    $enroloptions[$key][$matches[3]] = $value;
                }
            }
        }

        // Combining enrolment methods and their options in a single array.
        $enrolmentdata = array();
        if (!empty($enrolmethods)) {
            $enrolmentplugins = self::get_enrolment_plugins();
            foreach ($enrolmethods as $key => $method) {
                if (!array_key_exists($method, $enrolmentplugins)) {
                    // Error!
                    continue;
                }
                $enrolmentdata[$enrolmethods[$key]] = $enroloptions[$key];
            }
        }
        return $enrolmentdata;
    }

    /**
     * Return the enrolment plugins.
     *
     * The result is cached for faster execution.
     *
     * @return array
     */
    public static function get_enrolment_plugins() {
        $cache = cache::make('tool_uploadcourse', 'helper');
        if (($enrol = $cache->get('enrol')) === false) {
            $enrol = enrol_get_plugins(false);
            $cache->set('enrol', $enrol);
        }
        return $enrol;
    }

    /**
     * Get the restore content tempdir.
     *
     * The tempdir is the sub directory in which the backup has been extracted.
     *
     * This caches the result for better performance, but $CFG->keeptempdirectoriesonbackup
     * needs to be enabled, otherwise the cache is ignored.
     *
     * @param string $backupfile path to a backup file.
     * @param string $shortname shortname of a course.
     * @param array $errors will be populated with errors found.
     * @return string|false false when the backup couldn't retrieved.
     */
    public static function get_restore_content_dir($backupfile = null, $shortname = null, &$errors = array()) {
        global $CFG, $DB, $USER;

        $cachekey = null;
        if (!empty($backupfile)) {
            $backupfile = realpath($backupfile);
            if (empty($backupfile) || !is_readable($backupfile)) {
                $errors['cannotreadbackupfile'] = new lang_string('cannotreadbackupfile', 'tool_uploadcourse');
                return false;
            }
            $cachekey = 'backup_path:' . $backupfile;
        } else if (!empty($shortname) || is_numeric($shortname)) {
            $cachekey = 'backup_sn:' . $shortname;
        }

        if (empty($cachekey)) {
            return false;
        }

        // If $CFG->keeptempdirectoriesonbackup is not set to true, any restore happening would
        // automatically delete the backup directory... causing the cache to return an unexisting directory.
        $usecache = !empty($CFG->keeptempdirectoriesonbackup);
        if ($usecache) {
            $cache = cache::make('tool_uploadcourse', 'helper');
        }

        // If we don't use the cache, or if we do and not set, or the directory doesn't exist any more.
        if (!$usecache || (($backupid = $cache->get($cachekey)) === false || !is_dir("$CFG->tempdir/backup/$backupid"))) {

            // Use null instead of false because it would consider that the cache key has not been set.
            $backupid = null;

            if (!empty($backupfile)) {
                // Extracting the backup file.
                $packer = get_file_packer('application/vnd.moodle.backup');
                $backupid = restore_controller::get_tempdir_name(SITEID, $USER->id);
                $path = "$CFG->tempdir/backup/$backupid/";
                $result = $packer->extract_to_pathname($backupfile, $path);
                if (!$result) {
                    $errors['invalidbackupfile'] = new lang_string('invalidbackupfile', 'tool_uploadcourse');
                }
            } else if (!empty($shortname) || is_numeric($shortname)) {
                // Creating restore from an existing course.
                $courseid = $DB->get_field('course', 'id', array('shortname' => $shortname), IGNORE_MISSING);
                if (!empty($courseid)) {
                    $bc = new backup_controller(backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE,
                        backup::INTERACTIVE_NO, backup::MODE_IMPORT, $USER->id);
                    $bc->execute_plan();
                    $backupid = $bc->get_backupid();
                    $bc->destroy();
                } else {
                    $errors['coursetorestorefromdoesnotexist'] =
                        new lang_string('coursetorestorefromdoesnotexist', 'tool_uploadcourse');
                }
            }

            if ($usecache) {
                $cache->set($cachekey, $backupid);
            }
        }

        if ($backupid === null) {
            $backupid = false;
        }
        return $backupid;
    }

    /**
     * Return the role IDs.
     *
     * The result is cached for faster execution.
     *
     * @return array
     */
    public static function get_role_ids() {
        $cache = cache::make('tool_uploadcourse', 'helper');
        if (($roles = $cache->get('roles')) === false) {
            $roles = array();
            $rolesraw = get_all_roles();
            foreach ($rolesraw as $role) {
                $roles[$role->shortname] = $role->id;
            }
            $cache->set('roles', $roles);
        }
        return $roles;
    }

    /**
     * Get the role renaming data from the passed data.
     *
     * @param array $data data to extract the names from.
     * @param array $errors will be populated with errors found.
     * @return array where the key is the role_<id>, the value is the new name.
     */
    public static function get_role_names($data, &$errors = array()) {
        $rolenames = array();
        $rolesids = self::get_role_ids();
        $invalidroles = array();
        foreach ($data as $field => $value) {

            $matches = array();
            if (preg_match('/^role_(.+)?$/', $field, $matches)) {
                if (!isset($rolesids[$matches[1]])) {
                    $invalidroles[] = $matches[1];
                    continue;
                }
                $rolenames['role_' . $rolesids[$matches[1]]] = $value;
            }

        }

        if (!empty($invalidroles)) {
            $errors['invalidroles'] = new lang_string('invalidroles', 'tool_uploadcourse', implode(', ', $invalidroles));
        }

        // Roles names.
        return $rolenames;
    }

    /**
     * Helper to increment an ID number.
     *
     * This first checks if the ID number is in use.
     *
     * @param string $idnumber ID number to increment.
     * @return string new ID number.
     */
    public static function increment_idnumber($idnumber) {
        global $DB;
        while ($DB->record_exists('course', array('idnumber' => $idnumber))) {
            $matches = array();
            if (!preg_match('/(.*?)([0-9]+)$/', $idnumber, $matches)) {
                $newidnumber = $idnumber . '_2';
            } else {
                $newidnumber = $matches[1] . ((int) $matches[2] + 1);
            }
            $idnumber = $newidnumber;
        }
        return $idnumber;
    }

    /**
     * Helper to increment a shortname.
     *
     * This considers that the shortname passed has to be incremented.
     *
     * @param string $shortname shortname to increment.
     * @return string new shortname.
     */
    public static function increment_shortname($shortname) {
        global $DB;
        do {
            $matches = array();
            if (!preg_match('/(.*?)([0-9]+)$/', $shortname, $matches)) {
                $newshortname = $shortname . '_2';
            } else {
                $newshortname = $matches[1] . ($matches[2]+1);
            }
            $shortname = $newshortname;
        } while ($DB->record_exists('course', array('shortname' => $shortname)));
        return $shortname;
    }

    /**
     * Resolve a category based on the data passed.
     *
     * Key accepted are:
     * - category, which is supposed to be a category ID.
     * - category_idnumber
     * - category_path, array of categories from parent to child.
     *
     * @param array $data to resolve the category from.
     * @param array $errors will be populated with errors found.
     * @return int category ID.
     */
    public static function resolve_category($data, &$errors = array()) {
        $catid = null;

        if (!empty($data['category'])) {
            $category = coursecat::get((int) $data['category'], IGNORE_MISSING);
            if (!empty($category) && !empty($category->id)) {
                $catid = $category->id;
            } else {
                $errors['couldnotresolvecatgorybyid'] =
                    new lang_string('couldnotresolvecatgorybyid', 'tool_uploadcourse');
            }
        }

        if (empty($catid) && !empty($data['category_idnumber'])) {
            $catid = self::resolve_category_by_idnumber($data['category_idnumber']);
            if (empty($catid)) {
                $errors['couldnotresolvecatgorybyidnumber'] =
                    new lang_string('couldnotresolvecatgorybyidnumber', 'tool_uploadcourse');
            }
        }
        if (empty($catid) && !empty($data['category_path'])) {
            $result = self::resolve_category_by_path(explode(' / ', $data['category_path']));
            // $result has 3 return states: 1) false: couldnotresolvecatgorybypath, 2) $result['id'] is set: all categories exists in path, deepest id returned, 3) array: means, at least one category was not resolved

            if ($result == false) {
                $catid = false;
            } else if (isset($result['id'])) {
                $catid = $result['id'];
            } else {
                $errors_array = array();
                foreach ($result as $cat => $values) {
                    $catid = $values->id;
                }
            }
        }
        return $catid;
    }

    /**
     * Resolve a category by ID number.
     *
     * @param string $idnumber category ID number.
     * @return int category ID.
     */
    public static function resolve_category_by_idnumber($idnumber) {
        global $DB;
        $cache = cache::make('tool_uploadcourse', 'helper');
        $cachekey = 'cat_idn_' . $idnumber;
        if (($id = $cache->get($cachekey)) === false) {
            $params = array('idnumber' => $idnumber);
            $id = $DB->get_field_select('course_categories', 'id', 'idnumber = :idnumber', $params, IGNORE_MISSING);

            // Little hack to be able to differenciate between the cache not set and a category not found.
            if ($id === false) {
                $id = -1;
            }

            $cache->set($cachekey, $id);
        }

        // Little hack to be able to differenciate between the cache not set and a category not found.
        if ($id == -1) {
            $id = false;
        }

        return $id;
    }

    /**
     * Resolve a category by path.
     *
     * @param array $path category names indexed from parent to children.
     * @return array of result or errors or false
     */
    public static function resolve_category_by_path(array $path) {
        global $DB;
        $cache = cache::make('tool_uploadcourse', 'helper');
        $serialized_path = serialize($path);
        $path_orig = $path;
        $path_orig_flat = trim(join(' / ', $path));
        $result = array();
        $cachekey = 'cat_path_' . serialize($path);
        if (($id = $cache->get($cachekey)) === false) {
            $virtual_id_base = 9000000000; // use an unlikely big number as base id number
            $virtual_id = self::$virtual_id_counter; // use last used virtual number
            $parent = 0;
            $id_last = 0;
            $path_current = array();
            
            $sql = 'name = :name AND parent = :parent';
            while ($name = array_shift($path)) {
                $path_current[] = $name;
                $path_current_flat = trim(join(' / ', $path_current));
                $parent = $id_last;
                $params = array('name' => $name, 'parent' => $parent);
                
                if ($records = $DB->get_records_select('course_categories', $sql, $params, null, 'id, parent')) {
                    // CATEGORY EXISTS.
                    if (count($records) > 1) {
                        // Too many category records with the same name!
                        $id = -1;
                        break;
                    }
                    // Only 1 category exists. Great! Save it labeled as db type.
                    $record = reset($records);
                    $record->name = $name;
                    $record->db_type = 'db';
                    $record->category_path = $path_current_flat;
                    self::$virtual_category_table[$path_current_flat] = $record;
                    $id = $record->id;
                    $id_last = $id;
                } else {
                    // CATEGORY DOESN'T EXIST!
                    $allowcategoryautocreate = self::$course_obj->can_create_category();
                    $is_preview_mode = self::$course_obj->get_preview_mode();
                    if ($allowcategoryautocreate) {
                        // Category doesn't exist, but we are allowed to create it.
                        if ($is_preview_mode) {
                            // If we are in PREVIEW mode, so we don't create category, just using a virtual id in virtual_category_table variable to simulate creation
                            // initialize self::$virtual_category_table variable, when needed
                            self::$virtual_category_table = (isset(self::$virtual_category_table)) ? self::$virtual_category_table : array();

                            // check, if category exists already in virtual_category_table
                            $cat_exists_already = array_key_exists($path_current_flat, self::$virtual_category_table);
                            if ($cat_exists_already) {
                                // we already have this category in virtual table
                                $virtual_id = self::$virtual_category_table[$path_current_flat]->{'id'};
                            } else {
                                // we don't have this category in virtual table, so create one
                                self::$virtual_id_counter++;
                                $virtual_id = $virtual_id_base + self::$virtual_id_counter;
                                $options= array(
                                    'autocaton' => get_string('autocaton', 'tool_uploadcourse'),
                                    'category_path' => $path_current_flat,
                                    'categorywillbecreatedmessage' => get_string('categorywillbecreatedmessage', 'tool_uploadcourse'),
                                );
                                $record = array(
                                    'id' => $virtual_id,
                                    'parent' => $id_last,
                                    'name' => $name,
                                    'db_type' => 'virtual',
                                    'category_path' => $path_current_flat,
                                    'status' => new lang_string('categorydoesnotexistwillbecreated', 'tool_uploadcourse', $options)
                                );
                                self::$virtual_category_table[$path_current_flat] = (object) $record;
                                self::$course_obj->status('categorydoesnotexistwillbecreated', new lang_string('categorydoesnotexistwillbecreated', 'tool_uploadcourse', $options));
                                $result[$path_current_flat] = (object) $record;
                            }
                            $id = $virtual_id;
                            $id_last = $virtual_id;
                        } else {
                            $params['parent'] = $id_last;
                            $result_cat_obj = coursecat::create($params);
                            if (!$result_cat_obj) {
                                throw new moodle_exception(get_string('cannotcreatecategory', 'tool_uploadcourse', $path));
                            }
                            self::$course_obj->status('categorycreated', new lang_string('categorycreated', 'tool_uploadcourse', $path_current_flat));
                            $id = $result_cat_obj->id;
                            $id_last = $result_cat_obj->id;
                            $parent = $result_cat_obj->{'parent'};
                        }
                    } else {
                        // WE ARE NOT ALLOWED TO CREATE CATEGORY! SO RETURN INVALID ID.
                        $id = -1;
                        $record = array(
                            'id' => $id,
                            'parent' => $id,
                            'name' => $name,
                            'db_type' => 'no_create_permission',
                            'category_path' => $path_current_flat,
                            'error' => new lang_string('couldnotresolvecatgorybypath', 'tool_uploadcourse')
                        );
                        $result[$path_current_flat] = (object) $record;
                        self::$course_obj->error('couldnotresolvecatgorybypath', new lang_string('couldnotresolvecatgorybypath', 'tool_uploadcourse'));
                        break;
                    }
                }
            }
            $cache->set($cachekey, $id);
        }

        // We save -1 when the category has not been found to be able to know if the cache was set.
        if ($id == -1) {
            return false;
        }
        
        // Return id key of deepest category found in the path (if all categories in the path exists)
        if (count(array_keys($result)) === 0 and $id > 0) {
            $result['id'] = $id;
        }
        
        // Debug message showing the virtual_category_table
        $debug_msg = str_replace(array("\r\n", "\n", "\r"), '<br />', var_export(self::$virtual_category_table, true));
        $debug_msg = str_replace(array("  "), '&nbsp;&nbsp;', $debug_msg);
        debugging('Debug: self::$virtual_category_table (admin/tool/uploadcourse/classes/helper.php): ' . $debug_msg . "<br>\n", DEBUG_DEVELOPER);
        
        return $result;
    }
    
    
    /**
     * Reset virtual counter, which is used for simulating category creation
     *
     */
    public static function reset_virtual_id_counter() {
        self::$virtual_id_counter = 0;
    }

}
