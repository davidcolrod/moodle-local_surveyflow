<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
is_siteadmin() || die('Access restricted.');

$template_id = required_param('template_id', PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHANUMEXT);

$PAGE->set_url(new moodle_url('/local/surveyflow/assign.php', ['template_id' => $template_id]));
$PAGE->set_context($context);
$PAGE->set_title('Publish and Assign Survey');
$PAGE->set_heading('Survey Publication & Hierarchy Manager');

global $DB;
$dbman = $DB->get_manager();

// --- SECURITY SAFETY: CREATE TABLES IF THEY DO NOT PHYSICALLY EXIST ---
if (!$dbman->table_exists('local_surveyflow_assignments')) {
    $table = new xmldb_table('local_surveyflow_assignments');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('template_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('target_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
    $table->add_field('target_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $dbman->create_table($table);
}

if (!$dbman->table_exists('local_surveyflow_submissions')) {
    $table = new xmldb_table('local_surveyflow_submissions');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('template_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $dbman->create_table($table);
}

$template = $DB->get_record('local_surveyflow_templates', ['id' => $template_id], '*', MUST_EXIST);

// --- 1. PROCESS SMART SAVING ---
if ($action === 'save_assignments') {
    $selected_categories = optional_param_array('categories', [], PARAM_INT);
    $selected_courses    = optional_param_array('courses', [], PARAM_INT);

    $existing = $DB->get_records('local_surveyflow_assignments', ['template_id' => $template_id]);
    $current_assigned = [];
    foreach ($existing as $ex) {
        $current_assigned[$ex->target_type][$ex->target_id] = $ex->id;
    }

    $incoming_cats = array_fill_keys($selected_categories, true);
    foreach ($selected_categories as $cat_id) {
        if (!isset($current_assigned['category'][$cat_id])) {
            $record = new \stdClass();
            $record->template_id = $template_id;
            $record->target_type = 'category';
            $record->target_id   = $cat_id;
            $record->status      = 1;
            $record->timecreated = time();
            $DB->insert_record('local_surveyflow_assignments', $record);
        } else {
            $assign_id = $current_assigned['category'][$cat_id];
            $DB->set_field('local_surveyflow_assignments', 'status', 1, ['id' => $assign_id]);
        }
    }

    foreach ($current_assigned['category'] ?? [] as $cat_id => $assign_id) {
        if (!isset($incoming_cats[$cat_id])) {
            $DB->delete_records('local_surveyflow_assignments', ['id' => $assign_id]);
        }
    }

    $incoming_courses = array_fill_keys($selected_courses, true);
    foreach ($selected_courses as $course_id) {
        if (!isset($current_assigned['course'][$course_id])) {
            $record = new \stdClass();
            $record->template_id = $template_id;
            $record->target_type = 'course';
            $record->target_id   = $course_id;
            $record->status      = 1;
            $record->timecreated = time();
            $DB->insert_record('local_surveyflow_assignments', $record);
        } else {
            $assign_id = $current_assigned['course'][$course_id];
            $DB->set_field('local_surveyflow_assignments', 'status', 1, ['id' => $assign_id]);
        }
    }

    foreach ($current_assigned['course'] ?? [] as $course_id => $assign_id) {
        if (!isset($incoming_courses[$course_id])) {
            $has_submissions = $DB->record_exists('local_surveyflow_submissions', [
                'template_id' => $template_id, 
                'courseid' => $course_id
            ]);

            if ($has_submissions) {
                $DB->set_field('local_surveyflow_assignments', 'status', 0, ['id' => $assign_id]);
            } else {
                $DB->delete_records('local_surveyflow_assignments', ['id' => $assign_id]);
            }
        }
    }

    redirect(new moodle_url('/local/surveyflow/assign.php', ['template_id' => $template_id]), 'Coverage configuration updated successfully!');
}

echo $OUTPUT->header();

// --- 2. LOAD CURRENT STATE ---
$assigned_cats = [];
$assigned_courses = [];

$current_assignments = $DB->get_records('local_surveyflow_assignments', ['template_id' => $template_id, 'status' => 1]);
foreach ($current_assignments as $asm) {
    if ($asm->target_type === 'category') {
        $assigned_cats[$asm->target_id] = true;
    } else if ($asm->target_type === 'course') {
        $assigned_courses[$asm->target_id] = true;
    }
}

$categories = $DB->get_records('course_categories', null, 'name ASC');
$courses    = $DB->get_records('course', null, 'fullname ASC');

$courses_by_cat = [];
foreach ($courses as $crs) {
    if ($crs->id == 1) continue;
    $courses_by_cat[$crs->category][] = $crs;
}

echo html_writer::tag('div', 'Active template for assignment: <strong>'.s($template->name).'</strong> 
    <a href="index.php" class="btn btn-sm btn-secondary float-right">Back to Master Panel</a>', 
    ['class' => 'alert alert-info clearfix shadow-sm']);

// --- 3. INTERACTIVE TOP SUMMARY ---
echo html_writer::start_tag('div', ['class' => 'card mb-4 border-success shadow-sm']);
echo '<div class="card-header bg-success text-white font-weight-bold">Active Production Coverage</div>';
echo '<div class="card-body py-3">';

$has_summary = false;

if (!empty($assigned_cats)) {
    $has_summary = true;
    echo '<div class="mb-3">';
    echo '<strong class="text-dark">Active Transversal Categories:</strong>';
    echo '<ul class="list-unstyled mt-1 mb-0">';
    foreach ($assigned_cats as $cat_id => $val) {
        if (isset($categories[$cat_id])) {
            echo '<li class="mb-1"><span class="badge badge-primary p-2" style="font-size: 95%;">'.s($categories[$cat_id]->name).'</span></li>';
        }
    }
    echo '</ul>';
    echo '</div>';
}

if (!empty($assigned_courses)) {
    $has_summary = true;
    $total_courses = count($assigned_courses);
    
    echo '<div class="mb-2">';
    echo '<div class="d-flex justify-content-between align-items-center">';
    echo '<strong>Active Specific Courses: <span class="badge badge-secondary">'.$total_courses.' courses</span></strong>';
    echo '<button class="btn btn-sm btn-outline-success" type="button" onclick="toggleActiveCourses()">View / Hide Active Courses 🔽</button>';
    echo '</div>';

    echo '<div class="mt-2" id="collapseActiveCourses" style="display: none;">';
    echo '<div class="card card-body bg-light p-2" style="max-height: 200px; overflow-y: auto;">';
    echo '<ul class="mb-0 pl-3">';
    
    foreach ($assigned_courses as $crs_id => $val) {
        if (isset($courses[$crs_id])) {
            $resp_count = 0;
            if ($dbman->table_exists('local_surveyflow_submissions')) {
                $resp_count = $DB->count_records('local_surveyflow_submissions', ['template_id' => $template_id, 'courseid' => $crs_id]);
            }
            $badge = ($resp_count > 0) ? '<span class="badge badge-warning text-dark ml-2">'.$resp_count.' responses</span>' : '<span class="text-muted ml-2 font-italic">(No responses yet)</span>';
            echo '<li class="py-1 border-bottom"><strong>'.s($courses[$crs_id]->fullname).'</strong> '.$badge.'</li>';
        }
    }
    
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

if (!$has_summary) {
    echo '<p class="text-muted mb-0">This survey has no active coverages. Configure selectors below to publish it.</p>';
}

echo '</div>';
echo html_writer::end_tag('div');

// --- 4. MAIN FORM ---
echo '<form method="post" action="assign.php?template_id='.$template_id.'">
        <input type="hidden" name="action" value="save_assignments">';

echo html_writer::start_tag('div', ['class' => 'card mb-4 p-3 shadow-sm']);
echo '<h4 class="mb-2">2. Granularity by Specific Courses (Category Selection)</h4>';
echo '<p class="text-muted"><small>Select a category from the dropdown menu to display its associated courses in the container below.</small></p>';

echo '<div class="form-group mb-3">
        <label for="category_selector"><strong>Filter and display courses from category:</strong></label>
        <select id="category_selector" class="form-control form-control-lg border-primary" onchange="filterCoursesByCategory(this.value)">
            <option value="">-- Select a category to view its courses --</option>';
foreach ($categories as $cat) {
    echo '<option value="'.$cat->id.'">'.s($cat->name).'</option>';
}
echo '</select>
      </div>';

foreach ($categories as $cat) {
    $cat_courses = isset($courses_by_cat[$cat->id]) ? $courses_by_cat[$cat->id] : [];
    
    echo '<div id="cat-courses-container-'.$cat->id.'" class="category-course-group border rounded p-3 mb-3 bg-white shadow-sm" style="display: none;">';
    echo '<h5 class="text-primary border-bottom pb-2 mb-3">Courses belonging to: <strong>'.s($cat->name).'</strong></h5>';
    
    if (!empty($cat_courses)) {
        echo '<div class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleGroup(\'group-'.$cat->id.'\', true)">Select all in this category</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleGroup(\'group-'.$cat->id.'\', false)">Deselect all</button>
              </div>';
        
        echo '<div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead class="thead-light">
                    <tr>
                        <th style="width: 10%;">Assign</th>
                        <th style="width: 15%;">ID</th>
                        <th style="width: 75%;">Course Name</th>
                    </tr>
                </thead>
                <tbody>';
        foreach ($cat_courses as $crs) {
            $checked_crs = isset($assigned_courses[$crs->id]) ? 'checked' : '';
            echo '<tr>
                    <td><input type="checkbox" class="group-'.$cat->id.' course-item-checkbox" name="courses[]" value="'.$crs->id.'" '.$checked_crs.'></td>
                    <td>'.$crs->id.'</td>
                    <td><strong>'.s($crs->fullname).'</strong> <small class="text-muted">('.s($crs->shortname).')</small></td>
                  </tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<p class="text-muted mb-0">No courses registered in this category.</p>';
    }
    
    echo '</div>';
}

echo html_writer::end_tag('div');

echo '<button type="submit" class="btn btn-success btn-lg shadow-sm">Save and Update Configuration</button>
      <a href="index.php" class="btn btn-secondary btn-lg ml-2">Back to Panel</a>
      </form>';

echo '<script>
function toggleActiveCourses() {
    const container = document.getElementById("collapseActiveCourses");
    if (container.style.display === "none") {
        container.style.display = "block";
    } else {
        container.style.display = "none";
    }
}

function filterCoursesByCategory(catId) {
    const allGroups = document.querySelectorAll(".category-course-group");
    allGroups.forEach(group => group.style.display = "none");
    
    if (catId) {
        const targetGroup = document.getElementById("cat-courses-container-" + catId);
        if (targetGroup) {
            targetGroup.style.display = "block";
        }
    }
}

function toggleGroup(groupClassName, status) {
    const checkboxes = document.querySelectorAll("." + groupClassName);
    checkboxes.forEach(cb => cb.checked = status);
}
</script>';

echo '<div class="text-center text-muted mt-5 py-3 border-top">
        <small>&copy; 2026 <strong>@davidcolrod</strong> | Ing. David Rodriguez &mdash; Universidad Audiovisual de Venezuela (UAV)</small>
      </div>';
echo $OUTPUT->footer();