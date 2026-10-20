<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Crear la categoría principal en el menú de administración
    $ADMIN->add('root', new admin_category('surveyflowcategory', 'Survey Flow'));

    // Enlace a la página principal del gestor (index.php)
    $settings = new admin_externalpage(
        'local_surveyflow_manage',
        'Manage Surveys',
        new moodle_url('/local/surveyflow/index.php'),
        'moodle/site:config',
        false // Visible en el menú
    );

    // Agregar la página bajo la categoría recién creada
    $ADMIN->add('surveyflowcategory', $settings);
}