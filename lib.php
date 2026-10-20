<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Global Moodle interceptor to force mandatory Survey Flow participation
 * Executed on every page load in the system.
 */
function local_surveyflow_before_http_headers() {
    global $CFG, $USER, $PAGE, $DB;

    // 1. Skip admins, login pages, or AJAX requests
    if (!isloggedin() || surveyflow_is_ajax()) {
        return;
    }

    // Skip if we are already inside the surveyflow plugin to prevent redirection loops
    if (strpos($PAGE->url->get_path(), '/local/surveyflow/') !== false) {
        return;
    }

    // 2. Detect if the user is inside a Moodle course
    $courseid = optional_param('id', 0, PARAM_INT);
    if ($courseid <= 1) {
        $context = $PAGE->context;
        if ($context && $context->context_level == CONTEXT_COURSE) {
            $courseid = $context->instanceid;
        }
    }

    if ($courseid <= 1) {
        return; // Not in a specific course
    }

    // 3. Check if the course has an active assigned survey
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('local_surveyflow_assignments') || !$dbman->table_exists('local_surveyflow_templates')) {
        return;
    }

    $course = $DB->get_record('course', ['id' => $courseid], 'id, category', IGNORE_MISSING);
    if (!$course) {
        return;
    }

    $sql = "SELECT t.*, a.id as assignment_id 
            FROM {local_surveyflow_templates} t
            JOIN {local_surveyflow_assignments} a ON a.template_id = t.id
            WHERE a.status = 1 AND (
                (a.target_type = 'course' AND a.target_id = :courseid) OR 
                (a.target_type = 'category' AND a.target_id = :catid)
            )";
    
    $params = ['courseid' => $courseid, 'catid' => $course->category];
    $active_template = $DB->get_record_sql($sql, $params, IGNORE_MULTIPLE);

    if (!$active_template) {
        return; 
    }

    // 4. Validate validity dates
    $now = time();
    if ((!empty($active_template->timeopen) && $active_template->timeopen > $now) || 
        (!empty($active_template->timeclose) && $active_template->timeclose < $now)) {
        return; 
    }

    // 5. Check if the student ALREADY submitted this survey in this course
    if ($dbman->table_exists('local_surveyflow_submissions')) {
        $already_submitted = $DB->record_exists('local_surveyflow_submissions', [
            'template_id' => $active_template->id,
            'userid'      => $USER->id,
            'courseid'    => $courseid
        ]);

        if ($already_submitted) {
            return; 
        }
    }

    // 6. Mandatory redirection to the response form
    $target_url = new moodle_url('/local/surveyflow/respond.php', [
        'template_id' => $active_template->id,
        'courseid'    => $courseid
    ]);

    redirect($target_url, '⚠️ You have a pending mandatory survey in this course. You must complete it to continue.', null, \core\output\notification::NOTIFY_WARNING);
}

function surveyflow_is_ajax() {
    return (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
}