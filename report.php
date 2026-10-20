<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
is_siteadmin() || die('Access restricted.');

$template_id = required_param('template_id', PARAM_INT);
$download_csv = optional_param('download_csv', 0, PARAM_INT);

global $DB;
$template = $DB->get_record('local_surveyflow_templates', ['id' => $template_id], '*', MUST_EXIST);
$dbman = $DB->get_manager();

// --- 1. PROCESS DIRECT CSV DOWNLOAD IF REQUESTED ---
if ($download_csv == 1) {
    if (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=surveyflow_detailed_report_' . $template_id . '_' . date('Ymd_Hi') . '.csv');
    
    $output = fopen('php://output', 'w');
    // Force UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // File headers
    fputcsv($output, ['Sub ID', 'Participant', 'Email', 'Category', 'Course ID', 'Course Name', 'Question ID', 'Question Text', 'Type', 'Response Given', 'Date']);

    if ($dbman->table_exists('local_surveyflow_submissions') && $dbman->table_exists('local_surveyflow_responses')) {
        $sql_csv = "SELECT s.id as subid, u.firstname, u.lastname, u.email, c.id as cid, c.fullname as cname, cc.name as catname, q.id as qid, q.questiontext, q.questiontype, r.answertext AS responseval, s.timecreated
                    FROM {local_surveyflow_submissions} s
                    JOIN {user} u ON u.id = s.userid
                    JOIN {course} c ON c.id = s.courseid
                    JOIN {course_categories} cc ON cc.id = c.category
                    JOIN {local_surveyflow_responses} r ON r.submission_id = s.id
                    JOIN {local_surveyflow_questions} q ON q.id = r.question_id
                    WHERE s.template_id = :tid
                    ORDER BY s.id DESC, q.sortorder ASC";
        
        $records_csv = $DB->get_recordset_sql($sql_csv, ['tid' => $template_id]);
        foreach ($records_csv as $rec) {
            fputcsv($output, [
                $rec->subid,
                $rec->firstname . ' ' . $rec->lastname,
                $rec->email,
                $rec->catname,
                $rec->cid,
                $rec->cname,
                $rec->qid,
                html_entity_decode(strip_tags($rec->questiontext)),
                $rec->questiontype,
                $rec->responseval,
                userdate($rec->timecreated, '%d/%m/%Y %H:%M')
            ]);
        }
        $records_csv->close();
    }
    fclose($output);
    exit;
}

// --- 2. VISUAL HTML SCREEN VIEW ---
$PAGE->set_url(new moodle_url('/local/surveyflow/report.php', ['template_id' => $template_id]));
$PAGE->set_context($context);
$PAGE->set_title('Detailed Response Consolidated Report');
$PAGE->set_heading('Detailed Survey Consolidated Report');

echo $OUTPUT->header();

echo html_writer::tag('div', 'Detailed analytical consolidated report for template: <strong>'.s($template->name).'</strong> 
    <a href="stats.php?template_id='.$template_id.'" class="btn btn-sm btn-secondary float-right">Return to Statistics</a>', 
    ['class' => 'alert alert-info clearfix shadow-sm mb-4']);

// Direct download button to Excel (CSV)
echo '<div class="mb-3 text-right">
        <a href="report.php?template_id='.$template_id.'&download_csv=1" class="btn btn-success btn-lg shadow-sm">
            Download Detailed Consolidated Report in Excel (CSV)
        </a>
      </div>';

echo html_writer::start_tag('div', ['class' => 'card shadow-sm']);
echo '<div class="card-header bg-dark text-white font-weight-bold">Consolidated Table of Participations and Votes</div>';
echo '<div class="card-body p-0">';

$has_data = false;
$records = [];

if ($dbman->table_exists('local_surveyflow_submissions') && $dbman->table_exists('local_surveyflow_responses')) {
    $count_subs = $DB->count_records('local_surveyflow_submissions', ['template_id' => $template_id]);
    if ($count_subs > 0) {
        $has_data = true;
        $sql_report = "SELECT s.id as subid, u.firstname, u.lastname, u.email, c.id as cid, c.fullname as cname, cc.name as catname, q.id as qid, q.questiontext, q.questiontype, r.answertext AS responseval, s.timecreated
                       FROM {local_surveyflow_submissions} s
                       JOIN {user} u ON u.id = s.userid
                       JOIN {course} c ON c.id = s.courseid
                       JOIN {course_categories} cc ON cc.id = c.category
                       JOIN {local_surveyflow_responses} r ON r.submission_id = s.id
                       JOIN {local_surveyflow_questions} q ON q.id = r.question_id
                       WHERE s.template_id = :tid
                       ORDER BY s.id DESC, q.sortorder ASC";
        
        try {
            $records = $DB->get_records_sql($sql_report, ['tid' => $template_id]);
        } catch (\Exception $e) {
            $records = [];
            $has_data = false;
        }
    }
}

if ($has_data && !empty($records)) {
    echo '<div class="table-responsive"><table class="table table-bordered table-striped table-hover mb-0 font-size-sm">
            <thead class="thead-light">
                <tr>
                    <th>Sub ID</th>
                    <th>Participant</th>
                    <th>Category</th>
                    <th>Course</th>
                    <th>Question</th>
                    <th class="text-center">Response Given</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>';

    foreach ($records as $rec) {
        $resp_val = s($rec->responseval);
        if (in_array($resp_val, ['CA', 'PA', 'PD', 'CD'])) {
            $badge_color = 'badge-success';
            if ($resp_val === 'PA') $badge_color = 'badge-info';
            if ($resp_val === 'PD') $badge_color = 'badge-warning text-dark';
            if ($resp_val === 'CD') $badge_color = 'badge-danger';
            $resp_display = '<span class="badge '.$badge_color.' px-2 py-1 font-weight-bold" style="font-size: 95%;">'.$resp_val.'</span>';
        } else {
            $resp_display = $resp_val;
        }

        echo '<tr>
                <td>'.$rec->subid.'</td>
                <td><strong>'.s($rec->firstname.' '.$rec->lastname).'</strong><br><small class="text-muted">'.s($rec->email).'</small></td>
                <td>'.s($rec->catname).'</td>
                <td>'.s($rec->cname).'</td>
                <td>'.s(strip_tags($rec->questiontext)).'</td>
                <td class="text-center align-middle">'.$resp_display.'</td>
                <td><small>'.userdate($rec->timecreated, '%d/%m/%Y %H:%M').'</small></td>
              </tr>';
    }

    echo '</tbody></table></div>';
} else {
    echo '<div class="p-5 text-center text-muted">
            <h4 class="text-secondary">No analytical records yet</h4>
            <p>There are no submissions sent by students for this template yet. Run a test by answering the survey from a course to see data reflected here.</p>
          </div>';
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo '<div class="text-center text-muted mt-5 py-3 border-top">
        <small>&copy; 2026 <strong>@davidcolrod</strong> | Ing. David Rodriguez &mdash; Universidad Audiovisual de Venezuela (UAV)</small>
      </div>';
echo $OUTPUT->footer();