<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
is_siteadmin() || die('Access restricted.');

$PAGE->set_url(new moodle_url('/local/surveyflow/index.php'));
$PAGE->set_context($context);
$PAGE->set_title('Survey Flow Management');
$PAGE->set_heading('Survey Builder & Analytics');

global $DB;

// --- CASCADE DELETION ACTIONS ---
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$del_tpl = optional_param('deltemplate', 0, PARAM_INT);

if ($action === 'delete_template' && $del_tpl > 0) {
    // Safe cascade deletion using local_surveyflow tables
    $DB->delete_records('local_surveyflow_questions', ['template_id' => $del_tpl]);
    
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('local_surveyflow_assignments')) {
        $DB->delete_records('local_surveyflow_assignments', ['template_id' => $del_tpl]);
    }
    
    $DB->delete_records('local_surveyflow_templates', ['id' => $del_tpl]);
    
    redirect(new moodle_url('/local/surveyflow/index.php'), 'Survey template and its associated records successfully deleted.');
}

echo $OUTPUT->header();

echo html_writer::div('Welcome to the Survey Flow management dashboard.', 'alert alert-info shadow-sm');

echo html_writer::start_tag('div', ['class' => 'card mt-3 shadow-sm']);
echo html_writer::tag('div', 'Master Control Panel', ['class' => 'card-header font-weight-bold bg-dark text-white']);
echo html_writer::start_tag('div', ['class' => 'card-body']);

echo html_writer::tag('p', 'From here you can manage the dynamic question builder, active dates, publication by categories/courses, and audit analytics.');

// Button to create a new survey template
echo html_writer::tag('a', 'Create New Survey Template', [
    'href' => new moodle_url('/local/surveyflow/builder.php'),
    'class' => 'btn btn-primary mb-4 shadow-sm font-weight-bold'
]);

// List existing templates from the database
$templates = $DB->get_records('local_surveyflow_templates', null, 'timecreated DESC');

echo html_writer::tag('h4', 'Registered Templates:', ['class' => 'mb-3']);
if ($templates) {
    echo '<div class="table-responsive"><table class="table table-bordered table-hover align-middle">
            <thead class="thead-dark">
                <tr>
                    <th style="width: 5%;">ID</th>
                    <th style="width: 25%;">Name / Description</th>
                    <th style="width: 20%;">Validity (Dates)</th>
                    <th style="width: 20%;">Publication Status</th>
                    <th style="width: 30%; text-align: center;">Operational Actions</th>
                </tr>
            </thead>
            <tbody>';
    
    $dbman = $DB->get_manager();

    foreach ($templates as $tpl) {
        $builder_url  = new moodle_url('/local/surveyflow/builder.php', ['template_id' => $tpl->id]);
        $edit_tpl_url = new moodle_url('/local/surveyflow/builder.php', ['template_id' => $tpl->id, 'edit_template' => 1]);
        $assign_url   = new moodle_url('/local/surveyflow/assign.php', ['template_id' => $tpl->id]);
        $stats_url    = new moodle_url('/local/surveyflow/stats.php', ['template_id' => $tpl->id]);
        $del_url      = new moodle_url('/local/surveyflow/index.php', ['action' => 'delete_template', 'deltemplate' => $tpl->id]);
        
        // Count questions registered for this template
        $q_count = $DB->count_records('local_surveyflow_questions', ['template_id' => $tpl->id]);

        // Count published assignments
        $assign_count = 0;
        if ($dbman->table_exists('local_surveyflow_assignments')) {
            $assign_count = $DB->count_records('local_surveyflow_assignments', ['template_id' => $tpl->id, 'status' => 1]);
        }

        // Format opening and closing dates
        $timeopen_str  = (!empty($tpl->timeopen) && $tpl->timeopen > 0) ? userdate($tpl->timeopen, '%d/%m/%Y %H:%M') : '<span class="text-muted">Not defined</span>';
        $timeclose_str = (!empty($tpl->timeclose) && $tpl->timeclose > 0) ? userdate($tpl->timeclose, '%d/%m/%Y %H:%M') : '<span class="text-muted">No limit</span>';

        // Publication status badge
        if ($assign_count > 0) {
            $pub_badge = '<span class="badge badge-success p-2">Published ('.$assign_count.' targets)</span>';
        } else {
            $pub_badge = '<span class="badge badge-warning text-dark p-2">Draft (Not published)</span>';
        }

        echo '<tr>';
        echo '<td class="align-middle"><strong>'.$tpl->id.'</strong></td>';
        echo '<td class="align-middle"><a href="'.$builder_url.'"><strong>'.s($tpl->name).'</strong></a><br>
              <span class="badge badge-info text-white mt-1">'.$q_count.' questions</span><br>
              <small class="text-muted">'.s($tpl->description).'</small></td>';
        
        echo '<td class="align-middle"><small><strong>Start:</strong> '.$timeopen_str.'<br><strong>Close:</strong> '.$timeclose_str.'</small></td>';
        echo '<td class="align-middle text-center">'.$pub_badge.'</td>';

        // Operational action buttons
        echo '<td class="text-center align-middle">
                <div class="btn-group-vertical btn-group-sm w-100" role="group">
                    <div class="btn-group btn-group-sm mb-1" role="group">
                        <a href="'.$builder_url.'" class="btn btn-outline-primary" title="Manage questions">Questions</a>
                        <a href="'.$assign_url.'" class="btn btn-outline-success" title="Publish targets">Publish</a>
                    </div>
                    <div class="btn-group btn-group-sm mb-1" role="group">
                        <a href="'.$stats_url.'" class="btn btn-outline-info" title="View metrics and reports">Statistics</a>
                        <a href="'.$edit_tpl_url.'" class="btn btn-outline-secondary" title="Edit template details">Edit</a>
                    </div>
                    <a href="'.$del_url.'" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Are you sure you want to delete this survey and all its associated data?\');" title="Delete template">Delete Template</a>
                </div>
              </td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
} else {
    echo '<p class="text-muted">No survey templates created yet.</p>';
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo '<div class="text-center text-muted mt-5 py-3 border-top">
        <small>&copy; 2026 <strong>@davidcolrod</strong> | Ing. David Rodr&iacute;guez &mdash; Universidad Audiovisual de Venezuela (UAV)</small>
      </div>';
echo $OUTPUT->footer();