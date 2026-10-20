<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();
is_siteadmin() || die('Access restricted.');

$template_id   = optional_param('template_id', 0, PARAM_INT);
$edit_q_id     = optional_param('edit_q', 0, PARAM_INT);
$edit_template = optional_param('edit_template', 0, PARAM_INT);
$action        = optional_param('action', '', PARAM_ALPHANUMEXT);

$PAGE->set_url(new moodle_url('/local/surveyflow/builder.php', ['template_id' => $template_id]));
$PAGE->set_context($context);
$PAGE->set_title('Survey Flow Builder');
$PAGE->set_heading('Dynamic Question Builder');

// --- DIRECT MANAGEMENT CLASS ---
class surveyflow_manager {
    public static function create_template($name, $description, $timeopen, $timeclose) {
        global $DB;
        $record = new \stdClass();
        $record->name         = $name;
        $record->description  = $description;
        $record->timeopen     = $timeopen;
        $record->timeclose    = $timeclose;
        $record->timecreated  = time();
        $record->timemodified = time();
        return $DB->insert_record('local_surveyflow_templates', $record);
    }

    public static function update_template($id, $name, $description, $timeopen, $timeclose) {
        global $DB;
        $record = new \stdClass();
        $record->id           = $id;
        $record->name         = $name;
        $record->description  = $description;
        $record->timeopen     = $timeopen;
        $record->timeclose    = $timeclose;
        $record->timemodified = time();
        return $DB->update_record('local_surveyflow_templates', $record);
    }

    public static function add_question($template_id, $questiontext, $questiontype, $is_required, $sortorder) {
        global $DB;
        $is_measurable = ($questiontype === 'scale_likert') ? 1 : 0;

        $record = new \stdClass();
        $record->template_id   = $template_id;
        $record->questiontext  = $questiontext;
        $record->questiontype  = $questiontype;
        $record->is_measurable = $is_measurable;
        $record->is_required   = $is_required;
        $record->sortorder     = $sortorder;

        return $DB->insert_record('local_surveyflow_questions', $record);
    }

    public static function update_question($id, $questiontext, $questiontype, $is_required, $sortorder) {
        global $DB;
        $is_measurable = ($questiontype === 'scale_likert') ? 1 : 0;

        $record = new \stdClass();
        $record->id            = $id;
        $record->questiontext  = $questiontext;
        $record->questiontype  = $questiontype;
        $record->is_measurable = $is_measurable;
        $record->is_required   = $is_required;
        $record->sortorder     = $sortorder;

        return $DB->update_record('local_surveyflow_questions', $record);
    }

    public static function delete_question($id) {
        global $DB;
        $manager = $DB->get_manager();
        if ($manager->table_exists('local_surveyflow_responses')) {
            $DB->delete_records('local_surveyflow_responses', ['question_id' => $id]);
        }
        $DB->delete_records('local_surveyflow_questions', ['id' => $id]);
    }

    public static function get_template_questions($template_id) {
        global $DB;
        return $DB->get_records('local_surveyflow_questions', ['template_id' => $template_id], 'sortorder ASC');
    }
}

// 1. Process Template Creation or Update
if ($action === 'save_template') {
    $name      = required_param('name', PARAM_TEXT);
    $desc      = optional_param('description', '', PARAM_RAW);
    $t_open_s  = optional_param('timeopen', '', PARAM_TEXT);
    $t_close_s = optional_param('timeclose', '', PARAM_TEXT);
    $tpl_id    = optional_param('template_id', 0, PARAM_INT);

    $timeopen  = !empty($t_open_s) ? strtotime($t_open_s) : 0;
    $timeclose = !empty($t_close_s) ? strtotime($t_close_s) : 0;

    if ($tpl_id > 0) {
        surveyflow_manager::update_template($tpl_id, $name, $desc, $timeopen, $timeclose);
        redirect(new moodle_url('/local/surveyflow/builder.php', ['template_id' => $tpl_id]), 'Template updated successfully!');
    } else {
        $new_id = surveyflow_manager::create_template($name, $desc, $timeopen, $timeclose);
        redirect(new moodle_url('/local/surveyflow/builder.php', ['template_id' => $new_id]), 'Template created successfully!');
    }
}

// 2. Process Question Insertion or Edition
if ($action === 'save_question') {
    $qtext  = required_param('questiontext', PARAM_TEXT);
    $qtype  = required_param('questiontype', PARAM_ALPHANUMEXT);
    $is_req = optional_param('is_required', 1, PARAM_INT);
    $sort   = optional_param('sortorder', 1, PARAM_INT);
    $qid    = optional_param('question_id', 0, PARAM_INT);
    
    $form_template_id = optional_param('template_id', $template_id, PARAM_INT);

    if ($form_template_id > 0) {
        try {
            if ($qid > 0) {
                surveyflow_manager::update_question($qid, $qtext, $qtype, $is_req, $sort);
                $msg = 'Question modified successfully!';
            } else {
                surveyflow_manager::add_question($form_template_id, $qtext, $qtype, $is_req, $sort);
                $msg = 'Question added successfully!';
            }
            redirect(new moodle_url('/local/surveyflow/builder.php', ['template_id' => $form_template_id]), $msg);
        } catch (\Exception $e) {
            echo '<div class="alert alert-danger m-3"><strong>Error saving:</strong> ' . $e->getMessage() . '</div>';
        }
    }
}

// 3. Process Individual Question Deletion
if ($action === 'delete_question') {
    $del_q_id = optional_param('qid', 0, PARAM_INT);
    if ($del_q_id > 0) {
        surveyflow_manager::delete_question($del_q_id);
        redirect(new moodle_url('/local/surveyflow/builder.php', ['template_id' => $template_id]), 'Question deleted.');
    }
}

echo $OUTPUT->header();

if ($template_id == 0 || $edit_template == 1) {
    // --- STEP 1 VIEW: CREATE OR EDIT MASTER TEMPLATE ---
    $tpl_data = null;
    if ($template_id > 0) {
        $tpl_data = $DB->get_record('local_surveyflow_templates', ['id' => $template_id]);
    }

    $title_card = $tpl_data ? 'Edit Master Template: '.s($tpl_data->name) : 'Step 1: Create Master Template';
    $val_name   = $tpl_data ? $tpl_data->name : 'Learning Experience Evaluation - First Term';
    $val_desc   = $tpl_data ? $tpl_data->description : 'We want to know how your learning experience was in the virtual classroom.';
    $val_open   = ($tpl_data && $tpl_data->timeopen > 0) ? date('Y-m-d\TH:i', $tpl_data->timeopen) : '';
    $val_close  = ($tpl_data && $tpl_data->timeclose > 0) ? date('Y-m-d\TH:i', $tpl_data->timeclose) : '';

    echo html_writer::start_tag('div', ['class' => 'card p-4']);
    echo html_writer::tag('h3', $title_card);
    
    if ($tpl_data) {
        echo '<div class="mb-3"><a href="builder.php?template_id='.$template_id.'" class="btn btn-sm btn-secondary">&larr; Back to Question Builder</a></div>';
    }

    echo '<form method="post" action="builder.php?template_id='.$template_id.'">
            <input type="hidden" name="action" value="save_template">
            <input type="hidden" name="template_id" value="'.$template_id.'">
            <div class="form-group mb-3">
                <label><strong>Survey Name:</strong></label>
                <input type="text" name="name" class="form-control" value="'.s($val_name).'" required>
            </div>
            <div class="form-group mb-3">
                <label><strong>Description / Instructions:</strong></label>
                <textarea name="description" class="form-control" rows="3">'.s($val_desc).'</textarea>
            </div>
            <div class="row">
                <div class="col-md-6 form-group mb-3">
                    <label><strong>Opening Date & Time:</strong></label>
                    <input type="datetime-local" name="timeopen" class="form-control" value="'.$val_open.'">
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label><strong>Closing Date & Time (Deadline):</strong></label>
                    <input type="datetime-local" name="timeclose" class="form-control" value="'.$val_close.'">
                </div>
            </div>
            <button type="submit" class="btn btn-success">Save Template and Continue</button>
          </form>';
    echo html_writer::end_tag('div');
} else {
    // --- STEP 2 VIEW: QUESTION BUILDER ---
    global $DB;
    $template = $DB->get_record('local_surveyflow_templates', ['id' => $template_id], '*', MUST_EXIST);
    $questions = surveyflow_manager::get_template_questions($template_id);

    echo html_writer::tag('div', 'Active template: <strong>'.s($template->name).'</strong> 
        <a href="index.php" class="btn btn-sm btn-secondary float-right ml-2">Back to Panel</a>
        <a href="builder.php?template_id='.$template->id.'&edit_template=1" class="btn btn-sm btn-warning float-right">Edit Title & Dates</a>', 
        ['class' => 'alert alert-success clearfix']);

    // --- SECTION 1: REGISTERED QUESTIONS (TOP) ---
    echo html_writer::tag('h4', 'Registered Questions in the Instrument (Preview):');
    if ($questions) {
        echo '<div class="list-group mb-4">';
        foreach ($questions as $q) {
            $badge_type = ($q->is_measurable == 1) 
                ? '<span class="badge badge-primary" style="background-color: #007bff; color: white; padding: 4px 8px; border-radius: 4px; font-size: 80%;">Quantitative (Likert)</span>' 
                : '<span class="badge badge-secondary" style="background-color: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 80%;">Qualitative (Free Text)</span>';
            
            $badge_req = ($q->is_required == 1)
                ? '<span class="badge badge-danger" style="background-color: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 80%;">Required</span>'
                : '<span class="badge badge-light border" style="padding: 4px 8px; border-radius: 4px; font-size: 80%;">Optional</span>';

            $resp_count = 0;
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('local_surveyflow_responses')) {
                $resp_count = $DB->count_records('local_surveyflow_responses', ['question_id' => $q->id]);
            }
            $resp_badge = '<span class="badge badge-info" style="background-color: #17a2b8; color: white; padding: 4px 8px; border-radius: 4px; font-size: 80%;">'.$resp_count.' responses</span>';

            $edit_link = new moodle_url('/local/surveyflow/builder.php', ['template_id' => $template_id, 'edit_q' => $q->id]);
            $del_link  = new moodle_url('/local/surveyflow/builder.php', ['template_id' => $template_id, 'action' => 'delete_question', 'qid' => $q->id]);

            echo '<div class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <strong>[Order '.$q->sortorder.']</strong> '.s($q->questiontext).' <br class="d-md-none">
                        <div class="mt-1">'.$badge_type.' &nbsp; '.$badge_req.' &nbsp; '.$resp_badge.'</div>
                    </div>
                    <div class="btn-group mt-2 mt-md-0">
                        <a href="'.$edit_link.'" class="btn btn-sm btn-outline-primary">Modify</a>
                        <a href="'.$del_link.'" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Delete this question? Associated responses will also be deleted.\');">Delete</a>
                    </div>
                  </div>';
        }
        echo '</div>';
    } else {
        echo '<p class="text-muted">No questions added to this template yet. Use the form below to create the first one.</p>';
    }

    // --- SECTION 2: INSERTION OR EDITION FORM (BOTTOM) ---
    $q_to_edit = null;
    if ($edit_q_id > 0) {
        $q_to_edit = $DB->get_record('local_surveyflow_questions', ['id' => $edit_q_id]);
    }

    $form_title = $q_to_edit ? 'Modify Question ID: '.$q_to_edit->id : 'Add New Question';
    $btn_label  = $q_to_edit ? 'Update Question' : 'Save Question';
    $val_text   = $q_to_edit ? $q_to_edit->questiontext : '';
    $val_type   = $q_to_edit ? $q_to_edit->questiontype : 'scale_likert';
    $val_req    = $q_to_edit ? $q_to_edit->is_required : 1;
    $val_sort   = $q_to_edit ? $q_to_edit->sortorder : (count($questions) + 1);
    $hidden_id  = $q_to_edit ? '<input type="hidden" name="question_id" value="'.$q_to_edit->id.'">' : '';

    echo html_writer::start_tag('div', ['class' => 'card p-4 bg-light shadow-sm']);
    echo html_writer::tag('h5', $form_title);
    
    if ($q_to_edit) {
        echo '<div class="alert alert-warning py-2"><small>You are editing an existing question.</small> <a href="builder.php?template_id='.$template_id.'" class="float-right btn btn-sm btn-secondary">Cancel editing</a></div>';
    }

    echo '<form method="post" action="builder.php?template_id='.$template_id.'">
            <input type="hidden" name="action" value="save_question">
            '.$hidden_id.'
            <div class="form-group mb-3">
                <label><strong>Question Statement:</strong></label>
                <textarea name="questiontext" class="form-control" rows="2" required>'.s($val_text).'</textarea>
            </div>
            <div class="row">
                <div class="col-md-6 form-group mb-3">
                    <label><strong>Question Type:</strong></label>
                    <select name="questiontype" class="form-control">
                        <option value="scale_likert" '.($val_type === 'scale_likert' ? 'selected' : '').'>Likert Scale (Measurable)</option>
                        <option value="textarea" '.($val_type === 'textarea' ? 'selected' : '').'>Free Text (Qualitative)</option>
                    </select>
                </div>
                <div class="col-md-6 form-group mb-3">
                    <label><strong>Is Required?:</strong></label>
                    <select name="is_required" class="form-control">
                        <option value="1" '.($val_req == 1 ? 'selected' : '').'>Yes - Required</option>
                        <option value="0" '.($val_req == 0 ? 'selected' : '').'>No - Optional</option>
                    </select>
                </div>
            </div>
            <div class="form-group mb-3">
                <label><strong>Display Order:</strong></label>
                <input type="number" name="sortorder" class="form-control" value="'.$val_sort.'" style="width: 100px;" required>
            </div>
            <button type="submit" class="btn btn-primary">'.$btn_label.'</button>
          </form>';
    echo html_writer::end_tag('div');
}

echo '<div class="text-center text-muted mt-5 py-3 border-top">
        <small>&copy; 2026 <strong>@davidcolrod</strong> | Ing. David Rodriguez &mdash; Universidad Audiovisual de Venezuela (UAV)</small>
      </div>';
echo $OUTPUT->footer();