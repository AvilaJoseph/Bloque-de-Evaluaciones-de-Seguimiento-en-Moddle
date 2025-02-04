<?php
require_once('../../../config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_login();

// Verificar parámetros requeridos
$courseid = required_param('courseid', PARAM_INT);
$format = required_param('format', PARAM_ALPHA);

// Verificar permisos
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
require_course_login($course);

// Obtener datos (usando la misma consulta que en ajax.php)
$sql = "SELECT 
            DISTINCT u.id AS userid,
            u.firstname,
            u.lastname,
            g.name AS groupname,
            CASE 
                WHEN (qa.state = 'finished' AND qa.sumgrades IS NOT NULL) THEN 'completado'
                ELSE 'pendiente'
            END AS estado_completacion,
            CASE
                WHEN (qa.state = 'finished' AND qa.sumgrades IS NOT NULL) 
                THEN CONCAT(
                    ROUND((qa.sumgrades / q.grade) * 10, 2),
                    '/10.00'
                )
                ELSE '-'
            END AS calificacion
        FROM {user} u
        INNER JOIN {user_enrolments} ue ON u.id = ue.userid
        INNER JOIN {enrol} e ON ue.enrolid = e.id AND e.courseid = :courseid
        LEFT JOIN {groups_members} gm ON u.id = gm.userid
        LEFT JOIN {groups} g ON gm.groupid = g.id AND g.courseid = e.courseid
        INNER JOIN {quiz} q ON q.course = e.courseid
        LEFT JOIN {quiz_attempts} qa ON qa.userid = u.id AND qa.quiz = q.id
        WHERE u.deleted = 0
        AND (g.name = 'CONTRATISTA' OR g.name = 'PLANTA')
        ORDER BY u.lastname ASC, u.firstname ASC";

$params = array('courseid' => $courseid);
$results = $DB->get_records_sql($sql, $params);

if ($format === 'excel') {
    require_once($CFG->libdir . '/excellib.class.php');
    
    $filename = clean_filename($course->shortname . '_evaluaciones.xlsx');
    
    $workbook = new MoodleExcelWorkbook($filename);
    $worksheet = $workbook->add_worksheet('Evaluaciones');
    
    // Encabezados
    $headers = array('Nombre', 'Apellido', 'Grupo', 'Estado', 'Calificación');
    $col = 0;
    foreach ($headers as $header) {
        $worksheet->write(0, $col++, $header);
    }
    
    // Datos
    $row = 1;
    foreach ($results as $result) {
        $col = 0;
        $worksheet->write($row, $col++, $result->firstname);
        $worksheet->write($row, $col++, $result->lastname);
        $worksheet->write($row, $col++, $result->groupname);
        $worksheet->write($row, $col++, $result->estado_completacion);
        $worksheet->write($row, $col++, $result->calificacion);
        $row++;
    }
    
    $workbook->close();
    exit;

} elseif ($format === 'pdf') {
    require_once($CFG->libdir . '/pdflib.php');
    
    $pdf = new pdf();
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Moodle');
    $pdf->SetTitle($course->shortname . ' - Evaluaciones');
    
    $pdf->AddPage();
    
    // Título
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Reporte de Evaluaciones - ' . $course->shortname, 0, 1, 'C');
    
    // Tabla
    $pdf->SetFont('helvetica', '', 10);
    
    $headers = array('Nombre', 'Apellido', 'Grupo', 'Estado', 'Calificación');
    $data = array();
    foreach ($results as $result) {
        $data[] = array(
            $result->firstname,
            $result->lastname,
            $result->groupname,
            $result->estado_completacion,
            $result->calificacion
        );
    }
    
    $pdf->BasicTable($headers, $data);
    
    $pdf->Output(clean_filename($course->shortname . '_evaluaciones.pdf'), 'D');
    exit;
}

// Si no se especifica un formato válido
throw new moodle_exception('formatnotsupported', 'block_evaluaciones_seguimiento');