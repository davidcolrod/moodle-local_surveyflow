<?php
require_once(__DIR__ . '/../../config.php');

$context = context_system::instance();
require_login();

$template_id = required_param('template_id', PARAM_INT);
$courseid    = required_param('courseid', PARAM_INT);
$action      = optional_param('action', '', PARAM_ALPHANUMEXT);

$PAGE->set_url(new moodle_url('/local/surveyflow/respond.php', ['template_id' => $template_id, 'courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Mandatory Institutional Evaluation');
$PAGE->set_heading('Survey Flow Evaluation');

global $DB, $USER;

// 1. Validate that the template and course exist
$template = $DB->get_record('local_surveyflow_templates', ['id' => $template_id], '*', MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// 2. Validate if the student already answered this survey in this course (double submission security)
$dbman = $DB->get_manager();
if ($dbman->table_exists('local_surveyflow_submissions')) {
    $already_submitted = $DB->record_exists('local_surveyflow_submissions', [
        'template_id' => $template_id,
        'userid'      => $USER->id,
        'courseid'    => $courseid
    ]);
    if ($already_submitted) {
        redirect(new moodle_url('/course/view.php', ['id' => $courseid]), 'You have already completed this survey. Thank you.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// 3. --- PROCESS FORM SUBMISSION ---
if ($action === 'submit_response') {
    // Validate Moodle CSRF sesskey
    require_sesskey();

    $answers = optional_param_array('answers', [], PARAM_RAW);
    $questions = $DB->get_records('local_surveyflow_questions', ['template_id' => $template_id]);

    // Validate required questions in backend
    $errors = [];
    foreach ($questions as $q) {
        if ($q->is_required == 1) {
            if (!isset($answers[$q->id]) || (is_string($answers[$q->id]) && trim($answers[$q->id]) === '')) {
                $errors[] = 'The question "'.s($q->questiontext).'" is required.';
            }
        }
    }

    if (!empty($errors)) {
        \core\notification::error(implode('<br>', $errors));
    } else {
        // Save submission header
        $sub = new \stdClass();
        $sub->template_id = $template_id;
        $sub->userid      = $USER->id;
        $sub->courseid    = $courseid;
        $sub->timecreated = time();
        $submission_id = $DB->insert_record('local_surveyflow_submissions', $sub);

        // Save detailed responses using answertext according to install.xml
        foreach ($answers as $qid => $val) {
            $resp = new \stdClass();
            $resp->submission_id = $submission_id;
            $resp->question_id   = $qid;
            
            // If value comes as array (e.g. multiple checkboxes), implode them
            if (is_array($val)) {
                $resp->answertext = implode(' | ', clean_param_array($val, PARAM_TEXT));
            } else {
                $resp->answertext = clean_param($val, PARAM_TEXT);
            }
            
            $resp->timecreated = time();
            $DB->insert_record('local_surveyflow_responses', $resp);
        }

        // Redirect student back to their course with success message
        redirect(new moodle_url('/course/view.php', ['id' => $courseid]), 'Survey answered successfully! Thank you for your valuable feedback. You can now access your course.', null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

echo $OUTPUT->header();

// 4. Get configured questions for the template
$questions = $DB->get_records('local_surveyflow_questions', ['template_id' => $template_id], 'sortorder ASC');

echo html_writer::start_tag('div', ['class' => 'card shadow-sm mb-4']);
echo '<div class="card-header bg-primary text-white font-weight-bold">'.s($template->name).'</div>';
echo '<div class="card-body">';
echo '<p class="lead">'.s($template->description).'</p>';
echo '<hr>';
echo '<div class="alert alert-warning"><strong>Important Note:</strong> You are required to answer this evaluation for the course: <strong>'.s($course->fullname).'</strong>. You must complete the required fields to continue navigating the classroom.</div>';

if (!$questions) {
    echo '<p class="text-danger">This survey does not have questions configured by the administrator yet. Please contact support.</p>';
    echo '<a href="'.new moodle_url('/course/view.php', ['id' => $courseid]).'" class="btn btn-secondary">Return to course</a>';
} else {
    // Response form
    echo '<form method="post" action="respond.php?template_id='.$template_id.'&courseid='.$courseid.'">
            <input type="hidden" name="action" value="submit_response">
            <input type="hidden" name="sesskey" value="'.sesskey().'">';

    $counter = 1;
    foreach ($questions as $q) {
        $required_badge = ($q->is_required == 1) ? '<span class="text-danger font-weight-bold" title="Required">*</span>' : '<span class="text-muted font-italic small">(Optional)</span>';
        
        echo html_writer::start_tag('div', ['class' => 'form-group border-bottom pb-3 mb-4']);
        echo '<label class="font-weight-bold">'.$counter.'. '.s($q->questiontext).' '.$required_badge.'</label>';

        // Render according to question type with flexible detection
        $qtype = strtolower(trim($q->questiontype));

        if (strpos($qtype, 'likert') !== false || strpos($qtype, 'cuantitativa') !== false || strpos($qtype, 'scale') !== false || strpos($qtype, 'rating') !== false || strpos($qtype, 'quantitative') !== false) {
            
            // Institutional Likert Scale options
            $likert_options = [
                'CA' => 'Completely agree',
                'PA' => 'Partially agree',
                'PD' => 'Partially disagree',
                'CD' => 'Completely disagree'
            ];
            
            echo '<div class="mt-2 p-3 bg-light rounded border">';
            echo '<div class="d-flex flex-wrap justify-content-between align-items-center">';
            
            foreach ($likert_options as $code => $label) {
                echo '<div class="custom-control custom-radio custom-control-inline mx-2 my-1">
                        <input type="radio" id="q_'.$q->id.'_'.$code.'" name="answers['.$q->id.']" value="'.$code.'" class="custom-control-input" required>
                        <label class="custom-control-label font-weight-bold" for="q_'.$q->id.'_'.$code.'">
                            <span class="badge badge-secondary px-2 py-1 mr-1" style="font-size: 100%;">'.$code.'</span> 
                            <span class="text-muted small">('.$label.')</span>
                        </label>
                      </div>';
            }
            
            echo '</div>';
            echo '</div>';

        } else {
            switch ($qtype) {
                case 'text':
                case 'shorttext':
                    echo '<input type="text" class="form-control mt-2" name="answers['.$q->id.']" placeholder="Type your answer here...">';
                    break;

                case 'textarea':
                    echo '<textarea class="form-control mt-2" name="answers['.$q->id.']" rows="3" placeholder="Type your detailed comments..."></textarea>';
                    break;

                case 'select':
                case 'radio':
                    if (!empty($q->options)) {
                        $options = explode("\n", $q->options);
                        echo '<div class="mt-2 pl-2">';
                        foreach ($options as $opt) {
                            $opt = trim($opt);
                            if ($opt === '') continue;
                            echo '<div class="custom-control custom-radio mb-1">
                                    <input type="radio" id="q_'.$q->id.'_'.md5($opt).'" name="answers['.$q->id.']" value="'.s($opt).'" class="custom-control-input">
                                    <label class="custom-control-label" for="q_'.$q->id.'_'.md5($opt).'">'.s($opt).'</label>
                                  </div>';
                        }
                        echo '</div>';
                    }
                    break;

                case 'checkbox':
                    if (!empty($q->options)) {
                        $options = explode("\n", $q->options);
                        echo '<div class="mt-2 pl-2">';
                        foreach ($options as $opt) {
                            $opt = trim($opt);
                            if ($opt === '') continue;
                            echo '<div class="custom-control custom-checkbox mb-1">
                                    <input type="checkbox" id="q_'.$q->id.'_'.md5($opt).'" name="answers['.$q->id.'][]" value="'.s($opt).'" class="custom-control-input">
                                    <label class="custom-control-label" for="q_'.$q->id.'_'.md5($opt).'">'.s($opt).'</label>
                                  </div>';
                        }
                        echo '</div>';
                    }
                    break;

                default:
                    echo '<input type="text" class="form-control mt-2" name="answers['.$q->id.']" placeholder="Type your answer here...">';
                    break;
            }
        }

        echo html_writer::end_tag('div');
        $counter++;
    }

    echo '<div class="text-center mt-4">
            <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm">Submit Answers and Continue to Course</button>
          </div>';
    echo '</form>';
}

echo html_writer::end_tag('div');
echo html_writer::end_tag('div');
echo '<div class="text-center text-muted mt-5 py-3 border-top">
        <small>&copy; 2026 <strong>@davidcolrod</strong> | Ing. David Rodriguez &mdash; Universidad Audiovisual de Venezuela (UAV)</small>
      </div>';
echo $OUTPUT->footer();