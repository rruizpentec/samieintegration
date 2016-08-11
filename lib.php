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
 * Database enrolment plugin.
 *
 * This plugin synchronises enrolment and roles with external database table.
 *
 * @package    enrol_samieintegration
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @copyright  2013 Iñaki Arenaza {@link http://www.mondragon.edu/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * SAMIE Integration plugin implementation.
 * @author  Blanquers - based on code by Petr Skoda - based on code by Martin Dougiamas, Martin Langhoff and others
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_samieintegration_plugin extends enrol_plugin {
    private $conn;
    private $samiemode;
    private $sepealuid;
    private $sepetutid;
    private $sepeadmid;
    private $baseurl;
    private $errorsfound;

    /**
     * Launch synchronization processes for courses, users, enrolments and statistics data.
     */
    public function start_sync() {
        $this->errorsfound = 0;
        $this->conn = $this->db_init();
        if ($this->conn) {
            $this->samiemode = $this->get_samie_config('GEN_MODALIDAD', '');
            if ($this->is_sepe_mode()) {
                $this->prepare_sepe_users();
            }
            $this->sync_courses();
            $this->sync_users();
            $this->sync_enrolments();
            $this->baseurl = $this->get_config('baseurl');
            if ($this->baseurl != "") {
                if (substr($this->baseurl, -1, 1) != '/') {
                    $this->baseurl .= '/';
                }
                $this->send_access_data_to_samie();
                $this->send_participants_data_to_samie();
                $this->send_usage_data_to_samie();
            } else {
                echo 'SAMIE url not found in config';
            }
        } else {
            $this->errorsfound++;
            echo 'Cannot connect to SAMIE database\n';
        }
        if ($this->errorsfound > 0) {
            echo "There were ".$this->errorsfound." error(s) found.";
        } else {
            echo "Process completed successfully.";
        }
    }

    /**
     * Indicates if SEPE mode is active
     *
     * @return bool True if SEPE mode is active
     */
    private function is_sepe_mode() {
        return ($this->samiemode == 'A' || $this->samiemode == 'S');
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function instance_deleteable($instance) {
        return true;
    }

    /**
     * Does this plugin allow manual unenrolment of a specific user?
     * Yes, but only if user suspended...
     *
     * @param stdClass $instance course enrol instance
     * @param stdClass $ue record from user_enrolments table
     *
     * @return bool true means user with 'enrol/xxx:unenrol' may unenrol this user,
     * false means nobody may touch this user enrolment
     */
    public function allow_unenrol_user(stdClass $instance, stdClass $ue) {
        if ($ue->status == ENROL_USER_SUSPENDED) {
            return true;
        }
        return false;
    }

    /**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    private function db_init() {
        $servidorsamie = $this->get_config('dbhost');
        $dbnombresamie = $this->get_config('dbname');
        $dbusuariosamie = $this->get_config('dbuser');
        $dbcontrasenasamie = $this->get_config('dbpass');
        $conn = new PDO("mysql:host=$servidorsamie;dbname=$dbnombresamie", "$dbusuariosamie", "$dbcontrasenasamie",
                array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8'")); // Conexion del SAMIE.
        return $conn;
    }

    /**
     * If traininggroup is a professional certificate, creates it as a moodle subcategoy, otherwise it will be a standard course.
     *
     * @param object $traininggroup Training group object
     */
    private function create_moodle_course($traininggroup) {
        // If traininggroup is a professional certificate, create it as a subcategoy, otherwise it will be a standard course.
        if ($traininggroup['afa_es_cncp'] == 0) {
            $this->create_standard_course($traininggroup);
        } else {
            $this->create_course_cat($traininggroup);
        }
    }

    /**
     * Creates a training group as a standard moodle course
     *
     * @param object $traininggroup Training group object
     */
    private function create_standard_course($traininggroup) {
        global $DB;
        $shortname = $traininggroup['afg_denominacion'].' ('.$traininggroup['afg_id'].')';
        if (!$DB->record_exists('course', array('shortname' => $shortname))) {
            // Create moodle course.
            $newcourse = new stdClass();
            // Format: Course name (course code).
            $newcourse->fullname = $traininggroup['afg_denominacion'].' ('.$traininggroup['afg_id'].')';
            $newcourse->shortname = $shortname;
            $newcourse->category = 1;
            $newcourse->summary = $traininggroup['afa_informacion_general'];
            $newcourse->newitems = 0;
            echo $newcourse->fullname."\n";
            $mdlcourse = create_course($newcourse);
            $this->update_default_course_sections($mdlcourse->id, 0);
            // Update single section name.
            $this->insert_course_section($mdlcourse, $traininggroup['afg_denominacion'], '', 0);
            // Update SAMIE database to link both courses.
            $this->update_afg_link($traininggroup['afg_id'], $mdlcourse->id);
        } else {
            $this->errorsfound++;
            echo "Cannot create standard course ".$shortname."\n";
        }
    }

    /**
     * Updates default course sections value.
     *
     * @param int $courseid Course ID
     * @return bool Operation result
     */
    private function update_default_course_sections($courseid, $numsections = 0) {
        global $DB;
        $DB->execute("UPDATE {course_format_options} SET value = :numsections WHERE name = :name AND courseid = :courseid",
            array('numsections' => $numsections, 'name' => 'numsections', 'courseid' => $courseid));
    }

    /**
     * Updates the link between a training group and an lms object
     *
     * @param int @afgid Training group ID
     * @param int @lmsid Lms object ID
     * @return bool Operation result
     */
    private function update_afg_link($afgid, $lmsid) {
        $sql = "UPDATE st_accionesformativas_grupos SET afg_id_lms = ".$lmsid." WHERE afg_id = ".$afgid;
        return $this->update_sql($sql);
    }

    /**
     * Updates the link between a training activity specialty and an lms object
     *
     * @param int @aesid Training activity specialty ID
     * @param int @lmsid Lms object ID
     * @return bool Operation result
     */
    private function update_aes_link($aesid, $lmsid) {
        $sql = "UPDATE st_acciones_especialidades SET aes_id_lms = ".$lmsid." WHERE aes_id = ".$aesid;
        return $this->update_sql($sql);
    }

    /**
     * Updates the link between an auxiliary SEPE user enrolment and a moodle enrolment
     *
     * @param int @userid SEPE user ID
     * @param int @courseid Moodle course ID
     * @param int @roleid Role ID
     * @return bool Operation result
     */
    private function update_sepe_enrol_link($userid, $courseid, $roleid) {
        $sql = "INSERT INTO st_matriculas_sepe (mas_per_id_lms, mas_aes_id_lms, mas_rolid)
                VALUES ('".$userid."', '".$courseid."', '".$roleid."')";
        return $this->update_sql($sql);
    }

    /**
     * Updates the link between a SAMIE enrolment and a Moodle object
     *
     * @param int $samieenrolid SAMIE enrolment ID
     * @param int $lmsid Moodle object ID
     * @param string $type SAMIE enrolment type
     * @result bool Operation result
     */
    private function update_samieenrol_link($samieenrolid, $lmsid, $type) {
        $sql = '';
        if ($samieenrolid > 0) {
            switch($type) {
                // For students in:.
                case 'CNCP': // Professional certification courses.
                    $sql = "UPDATE st_acciones_alumnos_especialidades SET aae_id_lms = ".$lmsid." WHERE aae_id = ".$samieenrolid;
                    break;
                case 'PROPIA': // Ordinary courses.
                    $sql = "UPDATE st_acciones_alumnos SET aal_id_lms = ".$lmsid." WHERE aal_id = ".$samieenrolid;
                    break;
                // For teachers in:.
                case 'CNCP_T': // Professional certification teachers.
                    $sql = "UPDATE st_acciones_especialidades_profesores SET aep_id_lms = ".$lmsid." WHERE aep_id = ".$samieenrolid;
                    break;
                case 'TUTOR': // Tutors (both).
                    $sql = "UPDATE st_tutores_afg SET tag_id_lms = ".$lmsid." WHERE tag_id = ".$samieenrolid;
                    break;
                case 'FORMADOR_ESPECIALIDADES': // Professional certification trainer.
                    $sql = "UPDATE st_acciones_especialidades SET aes_id_formador_lms = ".$lmsid." WHERE aes_id = ".$samieenrolid;
                    break;
                case 'FORMADOR_PROPIAS': // Ordinary courses trainer.
                    $sql = "UPDATE st_accionesformativas_grupos SET afg_id_formador_lms = ".$lmsid." WHERE afg_id = ".$samieenrolid;
                    break;
            }
            return $this->update_sql($sql);
        } else {
            return true;
        }
    }

    /**
     * Updates the link between a SAMIE user and a Moodle user
     *
     * @param int $perid SAMIE user ID
     * @param int $lmsid Moodle user ID
     * @result bool Operation result
     */
    private function update_user_link($perid, $lmsid) {
        $sql = "UPDATE st_personas SET per_id_lms = '".$lmsid."' WHERE per_id = ".$perid;
        return $this->update_sql($sql);
    }

    /**
     * Creates a SAMIE training group as a Moodle course category
     *
     * @param object $traininggroup Training group
     */
    private function create_course_cat($traininggroup) {
        $subcategory = new stdClass();
        $subcategory->name = $traininggroup['afg_denominacion'];
        $subcategory->idnumber = '';
        $subcategory->description = '';
        // Get parent category from configuration.
        $subcategory->parent = $this->get_config('professionalcertificatecategory');
        $subcategory->depth = 2;
        // Create the subcategory.
        $subcategory = coursecat::create($subcategory);
        // Update the link between SAMIE and Moodle objects.
        if ($this->update_afg_link($traininggroup['afg_id'], $subcategory->id)) {
            echo $subcategory->name."\n";
        } else {
            $this->errorsfound++;
            echo "Cannot create course_cat for ".$traininggroup['afg_denominacion'];
        }
    }

    /**
     * Creates a SAMIE training activity specialty as a Moodle course
     *
     * @param object $module Training activity specialty
     */
    private function create_module ($module) {
        global $DB;
        $shortname = $module['codigo'];
        $idnumber = 'AES_'.$module['aes_id'];
        $existscourseshortname = $DB->record_exists('course', array('shortname' => $shortname));
        $existscourseidnumber = $DB->record_exists('course', array('idnumber' => $idnumber));
        if (!$existscourseshortname && !$existscourseidnumber) {
            echo "\t".$module['codigo'].' '.$module['descripcion']."\n";
            $newcourse = new stdClass();
            $newcourse->fullname = $module['cer_id_codigo'].' '.$module['descripcion'];
            $newcourse->shortname = $module['codigo'];
            $newcourse->category = $module['afg_id_lms'];
            $newcourse->idnumber = $idnumber;
            $newcourse->summary = $module['afa_informacion_general'];
            $newcourse->newitems = 0;

            $mdlcourse = create_course($newcourse);
            $this->update_default_course_sections($mdlcourse->id, 0);
            // Update link between SAMIE and Moodle.
            $this->update_aes_link($module['aes_id'], $mdlcourse->id);

            // Create module sections. Especialidades de los módulos.
            $sql = "SELECT * FROM st_especialidades WHERE esp_id_modulo = '".$module['esp_id']."'";
            $sections = $this->get_records_sql($sql);
            $counter = 0;
            foreach ($sections as $section) {
                // Update sections. The first one is created automaticly.
                $description = $section['esp_descripcion'].' ('. $section['esp_id_codigo'].')';
                $summary = $section['esp_id_codigo'].'-'.$module['aes_id'];
                echo "\t\t".$summary.' '.$description."\n";
                if ($this->insert_course_section($mdlcourse, $description, $summary, $counter)) {
                    $counter++;
                } else {
                    echo "\t\tError Synchronizing section on module.\n";
                }
            }
            // Final test.
            $finaltest = $this->insert_course_section($mdlcourse, 'TEST FINAL '.$module['codigo'],
                    str_replace('MF', 'TF', $module['codigo']), $counter);
            if ($finaltest) {
                $counter++;
            } else {
                echo "\t\tFinal test not inserted.\n";
            }
        } else {
            $this->errorsfound++;
            echo "Cannot create module ".$shortname."\n";
        }
    }

    /**
     * Forces training groups and training action specialties synchronyzation with their corresponding Moodle categories, courses
     * and course sections
     */
    private function sync_courses() {
        global $CFG;
        require_once($CFG->dirroot.'/lib/coursecatlib.php');
        require_once($CFG->dirroot.'/course/lib.php');
        echo "Synchronizing courses...";
        // Training Groups.
        $sql = "SELECT *
                  FROM st_accionesformativas_grupos AFG
            INNER JOIN st_accionesformativas_acciones AFA ON AFA.afa_id = AFG.afg_afa_id
                 WHERE afg_id_lms IS NULL ";
        $traininggroups = $this->get_records_sql($sql);
        foreach ($traininggroups as $traininggroup) {
            $this->create_moodle_course($traininggroup);
        }
        // Create modules as courses. A training group can contain some modules.
        $sql = " SELECT *
                   FROM stv_cursomoodle
                  WHERE aes_id_lms IS NULL AND afg_id_lms IS NOT NULL
               ORDER BY afg_id_lms, aes_id ";
        $modules = $this->get_records_sql($sql);
        foreach ($modules as $module) {
            $this->create_module($module);
        }
        echo "done\n";
    }

    /**
     * Creates a Moodle course section
     * @param object $course Moodle course
     * @param string $name Section name
     * @param string $summary Section summary
     * @param string $section Section number (starts with zero).
     * @return bool Operation result
     */
    private function insert_course_section($course, $name, $summary, $section) {
        global $DB;
        try {
            if ($section == 0) {
                $sql = "UPDATE {course_sections}
                           SET name = :name, summary = :summary
                         WHERE course = :courseid AND section = 0 ";
                return $DB->execute($sql, array('courseid' => $course->id, 'name' => $name, 'summary' => $summary));
            } else {
                $coursesection = new stdClass();
                $coursesection->name = $name;
                $coursesection->summary = $summary;
                $coursesection->course = $course->id;
                $coursesection->section = $section;
                $coursesection->summaryformat = 1;
                if ($DB->insert_record('course_sections', $coursesection)) {
                    $sql = "UPDATE mdl_course_format_options
                               SET value = :value
                             WHERE courseid = :courseid AND name = 'numsections'";
                    return $DB->execute($sql, array('courseid' => $course->id, 'value' => $section));
                }
            }
        } catch (Exception $ex) {
            echo "Exception on insert_course_section: ".$ex;
        }
        return false;
    }

    /**
     * Forces SAMIE users synchronization with Moodle users.
     */
    private function sync_users() {
        global $CFG;
        echo 'Synchronizing users...';
        $counter = 0;
        require_once($CFG->dirroot.'/lib/coursecatlib.php');
        $users = $this->get_records_sql("SELECT * FROM st_personas WHERE per_id_lms IS NULL");
        $userscount = count($users);
        if ($userscount > 0) {
            echo ' ('.$userscount.')...';
            foreach ($users as $user) {
                $lastuserid = $this->sync_user($user);
                $counter++;
            }
        }
        if (($userscount - $counter) == 0) {
            echo "done\n";
        } else {
            echo "remaining users to sync: ".($userscount - $counter)."\n";
        }
        return;
    }

    /**
     * This method is similar to enrol_plugin::enrol_user, but it updates synchronization info on SAMIE platform
     *
     * @param int $roleid Role ID
     * @param int $courseid Course ID
     * @param int $userid User ID
     * @param int $enrolid Enrol ID
     * @param string $samieenroltype Enrolment Type in SAMIE
     * @param int $samieenrolid Enrol ID in SAMIE
     * @return User enrolment ID (or false if it fails)
     */
    private function samie_enrol_user($roleid, $courseid, $userid, $enrolid, $samieenroltype, $samieenrolid) {
        global $DB;
        $instance = new stdClass();
        $instance->id = $enrolid;
        $instance->enrolid = $enrolid;
        $instance->enrol = $this->get_name();
        $instance->courseid = $courseid;
        try {
            $this->enrol_user($instance, $userid, $roleid, (new DateTime())->getTimestamp(), 0);
            if ($lmsid = $DB->get_field('user_enrolments', 'id', array('userid' => $userid, 'enrolid' => $enrolid))) {
                if ($samieenroltype == "SEPE") {
                    if (!$this->update_sepe_enrol_link($userid, $courseid, $roleid)) {
                        $this->errorsfound++;
                        echo 'Cannot link enrolment SEPE User('.$userid.') Course('.$courseid.')';
                    }
                } else {
                    if (!$this->update_samieenrol_link($samieenrolid, $lmsid, $samieenroltype)) {
                        $this->errorsfound++;
                        echo 'Cannot link enrolment User('.$userid.') Course('.$courseid.')';
                    }
                }
            }
            return $lmsid;
        } catch (Exception $ex) {
            return $ex;
        }
    }

    /**
     * Builds an Sql statement to get pending synchronization enrolments
     *
     * @return string Pending synchronization enrolments sql
     */
    private function get_enrol_users_sql() {
        // Student section (rolid = 5).
        $sql = " SELECT per_id_lms, aes_id_lms, 'CNCP' as TIPO, aae_id AS samieenrolid, 5 AS rolid
                   FROM ((st_alumnos INNER JOIN st_personas ON alu_per_id = per_id)
             INNER JOIN st_acciones_alumnos_especialidades ON alu_id = aae_alu_id)
             INNER JOIN st_acciones_especialidades ON aes_afg_id = aae_afg_id AND aes_especialidad_id = aae_esp_id
                  WHERE per_id_lms IS NOT NULL AND aes_id_lms IS NOT NULL AND aae_id_lms IS NULL ";

        $sql .= " UNION SELECT per_id_lms, afg_id_lms, 'PROPIA', aal_id AS samieenrolid, 5 AS rolid
                          FROM ((st_alumnos INNER JOIN st_personas ON alu_per_id = per_id)
                    INNER JOIN st_acciones_alumnos ON alu_id = aal_alu_id)
                    INNER JOIN st_accionesformativas_grupos ON afg_id = aal_afg_id
                    INNER JOIN st_accionesformativas_acciones ON afa_id = afg_afa_id
                         WHERE per_id_lms IS NOT NULL AND afg_id_lms IS NOT NULL AND afa_es_cncp = 0 AND aal_id_lms IS NULL ";

        // Teachers section (rolid = 3).
        $sql .= " UNION SELECT per_id_lms, aes_id_lms, 'CNCP_T', aep_id AS samieenrolid, 3 AS rolid
                    FROM ((st_profesores INNER JOIN st_personas ON pro_per_id = per_id)
              INNER JOIN st_acciones_especialidades_profesores ON pro_id = aep_pro_id)
              INNER JOIN st_acciones_especialidades ON aep_aes_id = aes_id
                   WHERE per_id_lms IS NOT NULL AND aes_id_lms IS NOT NULL AND aep_id_lms IS NULL ";

        $sql .= " UNION SELECT per_id_lms, afg_id_lms, 'TUTOR', tag_id AS samieenrolid, 3 AS rolid
                          FROM ((st_tutores_afg INNER JOIN st_profesores ON pro_id = tag_pro_id)
                    INNER JOIN st_personas ON per_id = pro_per_id)
                    INNER JOIN st_accionesformativas_grupos ON afg_id = tag_afg_id
                         WHERE per_id_lms IS NOT NULL AND afg_id_lms IS NOT NULL AND tag_id_lms IS NULL
                  UNION SELECT per_id_lms, aes_id_lms, 'FORMADOR_ESPECIALIDADES', aes_id AS samieenrolid, 3 AS rolid
                          FROM st_acciones_especialidades
                    INNER JOIN st_accionesformativas_grupos ON afg_id = aes_afg_id
                    INNER JOIN st_profesores ON pro_id = afg_id_formador
                    INNER JOIN st_personas ON per_id = pro_per_id
                    INNER JOIN st_accionesformativas_acciones ON afa_id = afg_afa_id
                         WHERE afa_es_cncp = 1 AND per_id_lms IS NOT NULL AND aes_id_lms IS NOT NULL AND aes_id_formador_lms IS NULL
                  UNION SELECT per_id_lms, afg_id_lms, 'FORMADOR_PROPIAS', afg_id AS samieenrolid, 3 AS rolid
                          FROM st_accionesformativas_grupos
                    INNER JOIN st_profesores ON pro_id = afg_id_formador
                    INNER JOIN st_personas ON per_id = pro_per_id
                    INNER JOIN st_accionesformativas_acciones ON afa_id = afg_afa_id
                         WHERE afa_es_cncp = 0 AND per_id_lms IS NOT NULL
                               AND afg_id_lms IS NOT NULL AND afg_id_formador_lms IS NULL";
        // If SEPE mode is active, we enrol SEPE auxiliary users.
        if ($this->is_sepe_mode()) {
            // SEPE student user.
            if ($sepealuid = $this->get_lms_userid_by_username('sepe_alu')) {
                $sql .= " UNION SELECT ".$sepealuid." AS per_id_lms, aes_id_lms, 'SEPE', 0 AS samieenrolid, 5 AS rolid
                                  FROM st_acciones_especialidades
                             LEFT JOIN st_matriculas_sepe ON mas_per_id_lms = ".$sepealuid." AND mas_aes_id_lms = aes_id_lms
                                       AND mas_rolid = 5
                                 WHERE aes_id_lms IS NOT NULL AND mas_id IS NULL ";
            }
            // SEPE teacher user.
            if ($sepetutid = $this->get_lms_userid_by_username('sepe_tut')) {
                $sql .= " UNION SELECT ".$sepetutid." AS per_id_lms, aes_id_lms, 'SEPE', 0 AS samieenrolid, 3 AS rolid
                                  FROM st_acciones_especialidades
                             LEFT JOIN st_matriculas_sepe ON mas_per_id_lms = ".$sepetutid." AND mas_aes_id_lms = aes_id_lms
                                       AND mas_rolid = 3
                                 WHERE aes_id_lms IS NOT NULL AND mas_id IS NULL ";
            }
            // SEPE admin user.
            if ($sepeadminid = $this->get_lms_userid_by_username('sepe_adm')) {
                $sql .= " UNION SELECT ".$sepeadminid." AS per_id_lms, aes_id_lms, 'SEPE', 0 AS samieenrolid, 1 AS rolid
                                 FROM st_acciones_especialidades
                            LEFT JOIN st_matriculas_sepe ON mas_per_id_lms = ".$sepeadminid." AND mas_aes_id_lms = aes_id_lms
                                      AND mas_rolid = 1
                                WHERE aes_id_lms IS NOT NULL AND mas_id IS NULL ";
            }
        }
        // The following line makes enrol_users check courses existence once per course and not per row.
        $sql .= " ORDER BY aes_id_lms, per_id_lms ";
        return $sql;
    }

    /**
     * Forces SAMIE users enrolments synchronization  with Moodle enrolments (It does not create new courses).
     */
    private function sync_enrolments() {
        global $DB;
        echo 'Synchronizing students enrolment...';
        $counter = 0;
        $sql = $this->get_enrol_users_sql();
        $userstoenrol = $this->get_records_sql($sql);
        $enrolmentscount = count($userstoenrol);
        echo ' ('.$enrolmentscount.')...';
        $lastcourse = -1;
        $coursecontext = false;
        foreach ($userstoenrol as $enrol) {
            try {
                // This avoids getting context, checking course existence and getting enrolid on each enrolment.
                // It's done once per course.
                if ($lastcourse != $enrol['aes_id_lms']) {
                    $lastcourse = $enrol['aes_id_lms'];
                    if (!$DB->record_exists('course', array('id' => $enrol['aes_id_lms']))) {
                        echo "Course: ".$enrol['aes_id_lms']." not exists\n";
                        $enrolmentscount--;
                        continue;
                    }
                    $coursecontext = context_course::instance($enrol['aes_id_lms']);
                    // We will use 'self' enrolment type.
                    $enrolid = $DB->get_field('enrol', 'id', array('courseid' => $enrol['aes_id_lms'], 'enrol' => 'manual'),
                            IGNORE_MULTIPLE);
                }
            } catch (Exception $ex) {
                $coursecontext = false;
                echo "\nException on enrol_students Course ".$enrol['aes_id_lms'].": ".$ex->getMessage()."\n";
            }
            try {
                // If we got a valid context (course exists, etc...) continue the process.
                if ($coursecontext) {
                    $lmsid = $this->samie_enrol_user($enrol['rolid'], $enrol['aes_id_lms'], $enrol['per_id_lms'], $enrolid,
                            $enrol['TIPO'], $enrol['samieenrolid']);
                    if ($lmsid) {
                        $counter++;
                    }
                } else {
                    // If not in a valid context, the enrolment is overcome.
                    $enrolmentscount--;
                }
            } catch (Exception $ex) {
                echo "\nException on enrol_students: ".$ex->getMessage()."\n";
            }
        }
        if (($enrolmentscount - $counter) == 0) {
            echo "done\n";
        } else {
            echo "remaining enrolments ".($enrolmentscount - $counter)."\n";
        }
    }

    /**
     * Looks for Moodle user ID with the username passed by param
     *
     * @param string $username Username
     * @return User ID (or false if not found)
     */
    private static function get_lms_userid_by_username($username) {
        global $DB;
        $user = $DB->get_record('user', array('username' => $username));
        if ($user) {
            return $user->id;
        } else {
            return false;
        }
    }

    /**
     * Creates auxiliary SEPE user
     *
     * @param string $username Auxiliary username
     * @return bool Operation result
     */
    private function create_sepe_user($username) {
        $user = array();
        if ($username == 'sepe_alu') {
            $user['per_username']     = 'sepe_alu';
            $user['per_id_numero']     = 'sepeAlu'; // Contraseña.
            $user['per_nombre']         = 'Alumno';
            $user['per_apellido1']     = 'Sepe';
            $user['per_apellido2']     = '';
            $user['per_email']         = 'example@email.com';
        } else if ($username == 'sepe_tut') {
            $user['per_username']     = 'sepe_tut';
            $user['per_id_numero']     = 'sepeTut'; // Contraseña.
            $user['per_nombre']         = 'Tutor';
            $user['per_apellido1']     = 'Sepe';
            $user['per_apellido2']     = '';
            $user['per_email']         = 'example@email.com';
        } else if ($username == 'sepe_adm') {
            $user['per_username']     = 'sepe_adm';
            $user['per_id_numero']     = 'sepeAdm'; // Contraseña.
            $user['per_nombre']         = 'Admin';
            $user['per_apellido1']     = 'Sepe';
            $user['per_apellido2']     = '';
            $user['per_email']         = 'example@email.com';
        }
        return $this->sync_user($user);
    }

    /**
     * Forces individual SAMIE user synchronization with Moodle user.
     *
     * @param array $user User data
     * @return int Moodle userid (or null if it fails)
     */
    private function sync_user($user) {
        global $CFG, $DB;
        // Utilizo la misma contraseña que tengo almacenada y codificada en SAMIE.
        $password = $user['per_password'];

        try {
            $userid = $this->get_lms_userid_by_username($user['per_username']);
            if ($userid) {
                $this->update_user_link($user['per_id'], $userid);
            } else {
                $moodleuser = new stdClass();
                $moodleuser->username = $user['per_username'];
                $moodleuser->password = $password;
                $moodleuser->firstname = $user['per_nombre'];
                $moodleuser->lastname = $user['per_apellido1']." ".$user['per_apellido2'];
                $moodleuser->email = $user['per_email'];
                $moodleuser->confirmed = 1;
                $moodleuser->lang = 'es';
                $moodleuser->mnethostid = 1;
                $userid = $DB->insert_record('user', $moodleuser);
                $this->update_user_link($user['per_id'], $userid);
            }
            // Update the link between SAMIE and Moodle objects.
        } catch (Exception $ex) {
            echo 'Exception on sync_user: '.$ex;
        }
        return $userid;
    }

    /**
     * Prepares the 3 SEPE auxiliary users creating them on Moodle and synchronizing with SAMIE platform
     */
    private function prepare_sepe_users () {
        global $sepealuid, $sepetutid, $sepeadmid;
        echo 'Preparing SEPE users...';
        $this->sepealuid = self::get_lms_userid_by_username('sepe_alu');
        $this->sepetutid = self::get_lms_userid_by_username('sepe_tut');
        $this->sepeadmid = self::get_lms_userid_by_username('sepe_adm');
        if (!$this->sepealuid) {
            if (!$this->sepealuid = $this->create_sepe_user('sepe_alu')) {
                echo "error creating user sepe_alu\n";
            }
        }
        if (!$this->sepetutid) {
            if (!$this->sepetutid = $this->create_sepe_user('sepe_tut')) {
                echo "error creating user sepe_tut\n";
            }
        }
        if (!$this->sepeadmid) {
            if (!$this->sepeadmid = $this->create_sepe_user('sepe_adm')) {
                echo "error creating user sepe_adm\n";
            }
        }
        echo "done\n";
    }

    /**
     * Sends a POST request to an url passing some data and an action to process the data.
     *
     * @param string $action Action to perform
     * @param object $data Data to send_access_data_to_samie
     * @param string $url URL to send data to
     * @result bool Operation result
     */
    private static function call_curl($action, $data, $url) {
        $ch = curl_init();
        // Set URL on which you want to post the Form and/or data.
        curl_setopt($ch, CURLOPT_URL, $url);

        // Data+Files to be posted.
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // For Debug mode; shows up any error encountered during the operation.
        curl_setopt($ch, CURLOPT_VERBOSE, 1);

        $post = [
            'action' => $action,
            'data' => $data,
        ];
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        $result['VER'] = curl_version();
        $result['EXE'] = curl_exec($ch);
        $result['INF'] = curl_getinfo($ch);
        $result['ERR'] = curl_error($ch);
        return isset($result["EXE"]);
    }

    /**
     * Converts an object fields (stdClass) in a pipe delimited string
     *
     * @param object $object Object to convert
     * @result string Pipe delimited string
     */
    private static function object2string($object) {
        $string = '';
        if (is_object($object)) {
            foreach ($object as $key => $value) {
                if ($string != '') {
                    $string .= '|';
                }
                $string .= $value;
            }
        } else {
            $string = ''.$object;
        }
        return $string;
    }

    /**
     * Sends database records to SAMIE platform to perform an action
     * @param string $sql SQL statement to get the records
     * @param stirng $action Action to perform on SAMIE platform
     * @return bool Operation result
     */
    private function send_sql_data_to_samie($sql, $action) {
        global $DB;
        $datarecoveredfrommoodle = $DB->get_records_sql($sql);
        $datatosend = '';
        if (count($datarecoveredfrommoodle) > 0) {
            foreach ($datarecoveredfrommoodle as $dataobject) {
                $data = self::object2string($dataobject);
                if ($datatosend != '') {
                    $datatosend .= "\n";
                }
                $datatosend .= $data;
            }
            $url = $this->baseurl.'reportdataimporter.php';
            return $this->call_curl($action, $datatosend, $url);
        } else {
            return true;
        }
    }

    /**
     * Sends access statistics to SAMIE platform
     */
    private function send_access_data_to_samie() {
        echo "Updating access data...";
        $pluginconfigobject = get_config('enrol_samieintegration');
        $sqllastdata = "";
        if (isset($pluginconfigobject->jobLastSendaccessDataOKRun)) {
            $sqllastdata = " AND FROM_UNIXTIME(timecreated) > '" .$pluginconfigobject->jobLastSendaccessDataOKRun. "' ";
        }
        $subquery = "SELECT CASE WHEN CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) >= 07
                                      AND CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) <= 14 THEN 0
                                 WHEN CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) >= 15
                                      AND CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) <= 22 THEN 1
                                 ELSE 2 END AS hora,
                            courseid, DATE_FORMAT(FROM_UNIXTIME(timecreated), '%Y%m%d') AS dia,
                            COUNT(distinct userid) AS participantes
                       FROM {logstore_standard_log}
                      WHERE action = 'viewed' ".$sqllastdata."
                            AND courseid <> 1
                            AND userid IN (SELECT ue.userid
                                             FROM {user_enrolments} ue
                                       INNER JOIN {enrol} ON ue.enrolid = {enrol}.id
                                            WHERE {enrol}.roleid = 5
                                                  AND {enrol}.courseid = {logstore_standard_log}.courseid)
                   GROUP BY courseid, hora, dia ";
        $sql = "SELECT courseid, hora, SUM(participantes) AS accesos FROM (".$subquery.") DATA GROUP BY courseid, hora";
        $action = 'access';
        if ($this->send_sql_data_to_samie($sql, $action)) {
            set_config('jobLastSend'. $action . 'DataOKRun', date('Y-m-d H:i:s'), 'enrol_samieintegration');
        }
        echo "done\n";
    }

    /**
     * Sends participants statistics to SAMIE platform
     */
    private function send_participants_data_to_samie() {
        echo "Updating participation data...";
        $pluginconfigobject = get_config('enrol_samieintegration');
        $sqllastdata = "";
        if (isset($pluginconfigobject->jobLastSendparticipantesDataOKRun)) {
            $sqllastdata = " AND FROM_UNIXTIME(timecreated) > '" .$pluginconfigobject->jobLastSendparticipantesDataOKRun. "' ";
        }
        $sql = " SELECT courseid, CASE WHEN CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) >= 07
                                            AND CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) <= 14 THEN 0
                                       WHEN CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) >= 15
                                            AND CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) <= 22 THEN 1
                                       ELSE 2 END AS hora, COUNT(distinct userid) AS participantes
                   FROM {logstore_standard_log}
                  WHERE action = 'viewed' AND courseid <> 1 ".$sqllastdata."
                        AND userid IN (SELECT  ue.userid
                                         FROM {user_enrolments} ue
                                   INNER JOIN {enrol} ON ue.enrolid = {enrol}.id
                                        WHERE {enrol}.roleid = 5
                                              AND {enrol}.courseid = {logstore_standard_log}.courseid)
               GROUP BY courseid, hora ";
        $action = "participantes";
        if ($this->send_sql_data_to_samie($sql, $action)) {
            set_config('jobLastSend'. $action . 'DataOKRun', date('Y-m-d H:i:s'), 'enrol_samieintegration');
        }
        echo "done\n";
    }

    /**
     * Sends usage statistics to SAMIE platform
     */
    private function send_usage_data_to_samie() {
        global $CFG;
        echo "Updating usage data...";
        $action = "use";
        $usedata = $this->get_usage_data();
        if ($usedata != false) {
            $url = $this->baseurl.'reportdataimporter.php';
            if ($this->call_curl($action, $usedata, $url)) {
                set_config('jobLastSend'. $action . 'DataOKRun', date('Y-m-d H:i:s'), 'enrol_samieintegration');
            } else {
                echo "with errors\n";
            }
        }
        echo "done\n";
    }

    /**
     * Auxiliary method to accumulate time spent on a course. Its used by usage statistics methods.
     *
     * @param array $time Time array to accumulate time spent on course
     * @param int $courseid Course ID
     * @param int $start Start time (as unix time)
     * @param int $end End time (as unix time)
     * @param int $turn Turn (0 - morning, 1 - evening, 2 - night)
     */
    private function accumulate_time_spent (&$time, $courseid, $start, $end, $turn) {
        $timespent = 0;
        if ($start > 0) {
            if ($end > 0) { // Cuando detecta logout.
                $timespent = $end - $start;
            }
            if ($timespent > 3600) { // If time spent greater than connetion max time, we assign a third of connection max time.
                $timespent = 1200;
            }
            if (!array_key_exists($courseid, $time)) {
                $time[$courseid] = array();
            }
            if (!array_key_exists($turn, $time[$courseid])) {
                $time [$courseid][$turn] = new studentactionspecialtystat($courseid, 0, $courseid,
                    $turn, $timespent / 60, 0);
            } else {
                $time [$courseid][$turn]->duration += ($timespent) / 60;
            }
        }
    }

    /**
     * Prepares usage statistics
     *
     * @param string $sql SQL statement to calculate statistics
     * @return array Statistics
     */
    private function prepare_usage_data($sql) {
        $time = array();
        try {
            $result = "";
            $currentuser = 0;
            $currentcourse = 0;
            $currentturn = "";
            $starttime = 0;
            $endtime = 0;
            $rows = $this->get_records_sql($sql);
            $rownum = 0;
            if (count($rows) > 0) {
                foreach ($rows as $row) {
                    $rownum++;
                    if ($row['userid'] != $currentuser) {
                        if ($currentcourse != 1 && $currentuser != 0) {
                            $this->accumulate_time_spent($time, $currentcourse, $starttime, $endtime,
                                    $currentturn);
                        }
                        $currentcourse = 1;
                        $currentturn = "";
                        $starttime = 0;
                        $endtime = 0;
                        $currentuser = $row['userid'];
                    }
                    if ($endtime > 0 && $currentcourse != 1) {
                        $this->accumulate_time_spent($time, $currentcourse, $starttime, $endtime, $currentturn);
                        $currentcourse = 1;
                    }
                    if ($row['action'] == 'loggedout' && $currentcourse != 1) {
                        $endtime = $row['timecreated'];
                    } else {
                        $endtime = 0;
                    }
                    if ($row['courseid'] != 1) {
                        if ($currentcourse != 1 ) {
                            $this->accumulate_time_spent($time, $currentcourse, $starttime, $row['timecreated'], $currentturn);
                            $endtime = 0;
                        }
                        $starttime = $row['timecreated'];
                        $currentcourse = $row['courseid'];
                        $currentturn = $row['hora'];
                    }
                }
                if ($currentcourse != 1) {
                    $this->accumulate_time_spent($time, $currentcourse, $starttime, $endtime, $currentturn);
                }
            } else {
                return false;
            }
        } catch (Exception $ex) {
            echo $ex;
        }
        return $time;
    }

    /**
     * Builds an SQL statement to calculate usage statistics
     *
     * @return string SQL statistics statement
     */
    private function get_usage_data_sql() {
        $pluginconfigobject = get_config('enrol_samieintegration');
        $sqllastdata = "";
        if (isset($pluginconfigobject->jobLastSenduseDataOKRun)) {
            $sqllastdata = " AND FROM_UNIXTIME(timecreated) > '" .$pluginconfigobject->jobLastSenduseDataOKRun. "' ";
        }
        $sql = " SELECT *, FROM_UNIXTIME(timecreated) AS time2,
                        CASE WHEN CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) >= 07
                                  AND CAST(SUBSTRING(FROM_UNIXTIME(timecreated), 11, 3) AS UNSIGNED INTEGER) <= 14 THEN '0'
                             WHEN CAST(SUBSTRING(FROM_UNIXTIME(timecreated),11,3) AS UNSIGNED INTEGER) >= 15
                                  AND CAST(SUBSTRING(FROM_UNIXTIME(timecreated), 11, 3) AS UNSIGNED INTEGER) <= 22 THEN '1'
                             ELSE '2' END AS hora
                   FROM {logstore_standard_log}
                  WHERE 1 = 1 ".$sqllastdata." AND userid IN (SELECT  ue.userid
                                     FROM {user_enrolments} ue
                               INNER JOIN {enrol} ON ue.enrolid = {enrol}.id
                                    WHERE {enrol}.roleid = 5
                                          AND {enrol}.courseid = {logstore_standard_log}.courseid)
               ORDER BY userid , timecreated ";
        return $sql;
    }

    /**
     * Prepare usage statistics in order to send to SAMIE platform
     *
     * @return string Usage statistics data with SAMIE platform required format
     */
    private function get_usage_data() {
        $result = "";
        $time = $this->prepare_usage_data($this->get_usage_data_sql());
        if ($time != false) {
            foreach ($time as $courses => $course) {
                foreach ($course as $turns => $turn) {
                    switch ($turn->turn) {
                        case 0: {
                            $turnfield = "aes_uso_manana_duracion";
                            break;
                        }
                        case 1: {
                            $turnfield = "aes_uso_tarde_duracion";
                            break;
                        }
                        case 2: {
                            $turnfield = "aes_uso_noche_duracion";
                            break;
                        }
                    }
                    $turn->duration = ceil($turn->duration);
                    if ($result != "") {
                        $result .= "\n";
                    }
                    $result .= $turn->specialtyid. "|".$turn->turn. "|". $turn->duration;
                }
            }
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Overrides get_config method to access configuration data shared with all package plugins
     *
     * @param string $paramname Parameter name
     * @param string $default Default value if not set
     * @return param value
     */
    public function get_config($paramname, $default = "") {
        return get_config('package_samie', $paramname, $default);
    }

    /**
     * Executes an SQL statement to get records from SAMIE platform database
     *
     * @param string $sql SQL statement
     * @return array Records from SAMIE platform database (or null if it fails)
     */
    private function get_records_sql($sql) {
        $stmt = $this->conn->prepare($sql);
        try {
            $stmt->execute(array());
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $ex) {
            echo "Error fetching rows";
            return null;
        }
    }

    /**
     * Executes an INSERT SQL statement in SAMIE platform database
     *
     * @param string $sql SQL statement
     * @return bool Operation result
     */
    private function insert_sql($sql) {
        $stmt = $this->conn->prepare($sql);
        if ($stmt->execute()) {
            return $stmt->insert_id;
        } else {
            return null;
        }
    }

    /**
     * Executes an INSERT SQL statement in SAMIE platform database
     *
     * @param string $sql SQL statement
     * @return bool Operation result
     */
    private function update_sql($sql) {
        $stmt = $this->conn->prepare($sql);
        return $stmt->execute();
    }

    /**
     * Gets a SAMIE platform configuration parameter value
     *
     * @param string $paramname Parameter name
     * @param string $default Default value to return if parameter doesn't exists
     * @return string Parameter value
     */
    private function get_samie_config($paramname, $default = '') {
        try {
            $sql = "SELECT glc_value FROM st_globalconf WHERE glc_code = '".$paramname."'";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $rows = $stmt->FetchAll();
            if ($rows) {
                return $rows[0]['glc_value'];
            } else {
                return $default;
            }
        } catch (Exception $ex) {
            return $default;
        }
    }
}

/**
 * Auxiliary class to calculate usage statistics
 */
class studentactionspecialtystat {
    public $id, $userid, $specialtyid, $turn, $duration, $acceses;

    /**
     * Constructor
     *
     * @param int $id ID
     * @param int $userid User ID
     * @param int $specialtyid Specialty ID
     * @param int $turn Turn (0 - morning, 1 - evening, 2 - night)
     * @param int $duration Time in seconds
     * @param int $accesses Number of accesses
     */
    public function __construct($id, $userid, $specialtyid, $turn = 0, $duration = 0, $acceses = 0) {
        $this->id = $id;
        $this->userid = $userid;
        $this->specialtyid = $specialtyid;
        $this->turn = $turn;
        $this->duration = $duration;
        $this->acceses = $acceses;
    }

    /**
     * Converts the object into string
     *
     * @return string Object converted to string
     */
    public function tostring() {
        return 'IDUS:'.$this->userid.',IDESP:'.$this->specialtyid.',HOR:'.$this->turn.
            ',DUR:'.$this->duration.',ACC:'.$this->acceses;
    }
}