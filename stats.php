<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
is_siteadmin() || die('Access restricted.');

$template_id = required_param('template_id', PARAM_INT);

global $DB;
$template = $DB->get_record('local_surveyflow_templates', ['id' => $template_id], '*', MUST_EXIST);
$dbman = $DB->get_manager();

$PAGE->set_url(new moodle_url('/local/surveyflow/stats.php', ['template_id' => $template_id]));
$PAGE->set_context($context);
$PAGE->set_title('Survey Statistics & KPIs');
$PAGE->set_heading('Survey Flow Analytical Dashboard');

echo $OUTPUT->header();

// --- 1. CALCULATION OF KPIS AND INSTITUTIONAL REACH ---
$assignments = [];
if ($dbman->table_exists('local_surveyflow_assignments')) {
    $assignments = $DB->get_records('local_surveyflow_assignments', ['template_id' => $template_id, 'status' => 1]);
}

$targeted_course_ids = [];
foreach ($assignments as $asm) {
    if ($asm->target_type === 'course') {
        $targeted_course_ids[$asm->target_id] = true;
    } else if ($asm->target_type === 'category') {
        $cat_courses = $DB->get_records('course', ['category' => $asm->target_id], '', 'id');
        foreach ($cat_courses as $cc) {
            if ($cc->id > 1) {
                $targeted_course_ids[$cc->id] = true;
            }
        }
    }
}

$total_enrolled_users = 0;
if (!empty($targeted_course_ids)) {
    list($in_sql, $in_params) = $DB->get_in_or_equal(array_keys($targeted_course_ids), SQL_PARAMS_NAMED);
    $sql_enrolled = "SELECT COUNT(DISTINCT ue.userid) 
                     FROM {user_enrolments} ue
                     JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE e.courseid $in_sql";
    $total_enrolled_users = $DB->count_records_sql($sql_enrolled, $in_params);
}

$total_submissions = 0;
if ($dbman->table_exists('local_surveyflow_submissions')) {
    $total_submissions = $DB->count_records('local_surveyflow_submissions', ['template_id' => $template_id]);
}

$participation_rate = ($total_enrolled_users > 0) ? round(($total_submissions / $total_enrolled_users) * 100, 1) : 0;

echo html_writer::tag('div', 'Analytical report for template: <strong>'.s($template->name).'</strong> 
    <a href="index.php" class="btn btn-sm btn-secondary float-right">Back to Master Panel</a>', 
    ['class' => 'alert alert-info clearfix shadow-sm']);

// --- 2. BLOCK 1: EFFICACY & REACH KPIS ---
echo '<div class="row mb-4">
        <div class="col-md-4">
            <div class="card border-primary shadow-sm text-center p-3">
                <h6 class="text-muted text-uppercase">Reach (Enrolled)</h6>
                <h3 class="font-weight-bold text-primary">'.$total_enrolled_users.'</h3>
                <small class="text-muted">Students in courses with active survey</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-success shadow-sm text-center p-3">
                <h6 class="text-muted text-uppercase">Submissions</h6>
                <h3 class="font-weight-bold text-success">'.$total_submissions.'</h3>
                <small class="text-muted">Completed and registered surveys</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-info shadow-sm text-center p-3">
                <h6 class="text-muted text-uppercase">Effectiveness KPI</h6>
                <h3 class="font-weight-bold text-info">'.$participation_rate.'%</h3>
                <small class="text-muted">Institutional response rate</small>
            </div>
        </div>
      </div>';

// Button to report.php
echo '<div class="mb-4 text-right">
        <a href="report.php?template_id='.$template_id.'" class="btn btn-success btn-lg shadow-sm">
            Consolidate and Export
        </a>
      </div>';

// --- 3. BLOCK 2: ANALYSIS PER QUESTION ---
$questions = $DB->get_records('local_surveyflow_questions', ['template_id' => $template_id], 'sortorder ASC');

echo html_writer::start_tag('div', ['class' => 'card shadow-sm mb-4']);
echo '<div class="card-header bg-dark text-white font-weight-bold">Metric Breakdown by Question (Institutional Scale & Frequencies)</div>';
echo html_writer::start_tag('div', ['class' => 'card-body']);

if (!$questions) {
    echo '<p class="text-muted">No questions configured in this template.</p>';
} else {
    $q_counter = 1;
    foreach ($questions as $q) {
        echo '<div class="border rounded p-3 mb-3 bg-light">';
        echo '<h5><strong>'.$q_counter.'.</strong> '.s($q->questiontext).' <span class="badge badge-secondary">'.s($q->questiontype).'</span></h5>';

        $qtype = strtolower(trim($q->questiontype));
        $is_likert = (strpos($qtype, 'likert') !== false || strpos($qtype, 'cuantitativa') !== false || strpos($qtype, 'scale') !== false || strpos($qtype, 'rating') !== false || strpos($qtype, 'quantitative') !== false);

        if ($is_likert) {
            $counts = ['CA' => 0, 'PA' => 0, 'PD' => 0, 'CD' => 0];
            $total_q_answers = 0;

            if ($dbman->table_exists('local_surveyflow_responses') && $dbman->table_exists('local_surveyflow_submissions')) {
                $sql_answers = "SELECT r.answertext AS responseval, COUNT(r.id) as total
                                FROM {local_surveyflow_responses} r
                                JOIN {local_surveyflow_submissions} s ON s.id = r.submission_id
                                WHERE r.question_id = :qid AND s.template_id = :tid
                                GROUP BY r.answertext";
                $resps = $DB->get_records_sql($sql_answers, ['qid' => $q->id, 'tid' => $template_id]);
                
                foreach ($resps as $rsp) {
                    $val = strtoupper(trim($rsp->responseval));
                    if (isset($counts[$val])) {
                        $counts[$val] = (int)$rsp->total;
                        $total_q_answers += (int)$rsp->total;
                    }
                }
            }

            echo '<div class="table-responsive mt-3"><table class="table table-sm table-bordered bg-white mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Option / Acronym</th>
                            <th>Institutional Meaning</th>
                            <th class="text-center">Votes</th>
                            <th class="text-center">Percentage (%)</th>
                            <th style="width: 40%;">Visual Distribution</th>
                        </tr>
                    </thead>
                    <tbody>';

            $likert_labels = [
                'CA' => 'Completely agree',
                'PA' => 'Partially agree',
                'PD' => 'Partially disagree',
                'CD' => 'Completely disagree'
            ];

            foreach ($likert_labels as $code => $label) {
                $votos = $counts[$code];
                $pct = ($total_q_answers > 0) ? round(($votos / $total_q_answers) * 100, 1) : 0;
                
                $bar_color = 'bg-success';
                if ($code === 'PA') $bar_color = 'bg-info';
                if ($code === 'PD') $bar_color = 'bg-warning';
                if ($code === 'CD') $bar_color = 'bg-danger';

                echo '<tr>
                        <td><strong>'.$code.'</strong></td>
                        <td>'.$label.'</td>
                        <td class="text-center font-weight-bold">'.$votos.'</td>
                        <td class="text-center">'.$pct.'%</td>
                        <td class="align-middle">
                            <div class="progress" style="height: 18px;">
                                <div class="progress-bar '.$bar_color.'" role="progressbar" style="width: '.$pct.'%;" aria-valuenow="'.$pct.'" aria-valuemin="0" aria-valuemax="100">'.$pct.'%</div>
                            </div>
                        </td>
                      </tr>';
            }
            echo '</tbody></table></div>';
            echo '<small class="text-muted mt-2 d-block">Total answers evaluated for this question: <strong>'.$total_q_answers.'</strong></small>';

        } else {
            echo '<p class="text-muted mb-2"><small>Qualitative / free text responses registered:</small></p>';
            if ($dbman->table_exists('local_surveyflow_responses')) {
                $sql_text = "SELECT r.answertext AS responseval, s.timecreated, u.firstname, u.lastname, c.fullname as coursename
                             FROM {local_surveyflow_responses} r
                             JOIN {local_surveyflow_submissions} s ON s.id = r.submission_id
                             JOIN {user} u ON u.id = s.userid
                             JOIN {course} c ON c.id = s.courseid
                             WHERE r.question_id = :qid AND s.template_id = :tid
                             ORDER BY s.id DESC LIMIT 10";
                $text_resps = $DB->get_records_sql($sql_text, ['qid' => $q->id, 'tid' => $template_id]);

                if ($text_resps) {
                    echo '<ul class="list-group list-group-flush bg-white rounded border">';
                    foreach ($text_resps as $tr) {
                        echo '<li class="list-group-item py-2">
                                <span class="font-weight-bold text-primary">"'.s($tr->responseval).'"</span><br>
                                <small class="text-muted">By: '.s($tr->firstname.' '.$tr->lastname).' | Course: '.s($tr->coursename).' | Date: '.userdate($tr->timecreated, '%d/%m/%Y').'</small>
                              </li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p class="text-italic font-italic text-muted">No text responses registered for this question yet.</p>';
                }
            }
        }

        echo '</div>';
        $q_counter++;
    }
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo '<div class="text-center text-muted mt-5 py-3 border-top">
        <small>&copy; 2026 <strong>@davidcolrod</strong> | Ing. David Rodriguez &mdash; Universidad Audiovisual de Venezuela (UAV)</small>
      </div>';
echo $OUTPUT->footer();