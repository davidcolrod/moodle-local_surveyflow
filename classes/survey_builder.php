<?php
namespace local_uavencuesta;

defined('MOODLE_INTERNAL') || die();

class survey_builder {

    /**
     * Crea una nueva plantilla maestra de encuesta
     */
    public static function create_template($name, $description) {
        global $DB;
        $record = new \stdClass();
        $record->name = $name;
        $record->description = $description;
        $record->timecreated = time();
        $record->timemodified = time();
        
        return $DB->insert_record('uavencuesta_templates', $record);
    }

    /**
     * Agrega una pregunta aplicando la tipificación inteligente
     */
    public static function add_question($template_id, $questiontext, $questiontype, $sortorder) {
        global $DB;

        // Si es escala Likert -> es medible (1)
        // Si es caja de texto (textarea) -> es cualitativa / no medible (0)
        $is_measurable = ($questiontype === 'scale_likert') ? 1 : 0;

        $record = new \stdClass();
        $record->template_id   = $template_id;
        $record->questiontext  = $questiontext;
        $record->questiontype  = $questiontype;
        $record->is_measurable = $is_measurable;
        $record->sortorder     = $sortorder;

        return $DB->insert_record('uavencuesta_questions', $record);
    }
}