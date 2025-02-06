<?php
$dirroot = dirname(dirname(dirname(__FILE__)));
require_once($dirroot . '/config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/pdflib.php');
require_login();

try {
    $courseid = required_param('courseid', PARAM_INT);
    $format = required_param('format', PARAM_ALPHA);
    
    // Verificar permisos
    $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
    require_course_login($course);

    // 1. Primero obtenemos todas las evaluaciones del curso
    $sql_evaluaciones = "SELECT DISTINCT q.id, q.name
                        FROM {quiz} q
                        WHERE q.course = :courseid
                        AND (
                            (LOWER(q.name) LIKE '%inducción%')
                            OR 
                            (LOWER(q.name) LIKE '%reinducción%')
                        )
                        ORDER BY q.name ASC";
    
    $evaluaciones = $DB->get_records_sql($sql_evaluaciones, array('courseid' => $courseid));
    
    // 2. Inicializar array para todos los resultados
    $all_results = array();
    
    foreach ($evaluaciones as $evaluacion) {
        $sql_users = "SELECT 
            u.firstname,
            u.lastname,
            u.department AS grupo,
            q.name AS nombre_quiz,
            CASE 
                WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN 'completado' 
                ELSE 'pendiente' 
            END AS estado_completacion,
            CASE 
                WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN 
                    CONCAT(ROUND((qa.sumgrades / q.grade) * 10, 2), '/10.00 (', ROUND((qa.sumgrades / q.grade) * 100, 2), '%)') 
                ELSE '-' 
            END AS calificacion,
            COALESCE(qa.timefinish, ue.timemodified) AS fecha_ultima_modificacion
        FROM {user} u
        JOIN {user_enrolments} ue ON u.id = ue.userid
        JOIN {enrol} e ON ue.enrolid = e.id
        JOIN {course} c ON e.courseid = c.id
        JOIN {quiz} q ON q.course = c.id AND q.id = :quizid
        LEFT JOIN (
            SELECT 
                userid,
                quiz,
                state,
                sumgrades,
                timefinish
            FROM {quiz_attempts} qa1
            WHERE attempt = (
                SELECT MAX(attempt)
                FROM {quiz_attempts} qa2
                WHERE qa2.userid = qa1.userid 
                AND qa2.quiz = qa1.quiz
            )
        ) qa ON qa.userid = u.id AND qa.quiz = q.id
        WHERE 
            ue.status = 0  
            AND c.id = :courseid
            AND u.deleted = 0
            AND (
                (LOWER(q.name) LIKE '%inducción%' AND u.department = 'PLANTA')
                OR 
                (LOWER(q.name) LIKE '%reinducción%' AND u.department = 'CONTRATISTA')
            )
        ORDER BY u.lastname, u.firstname";

        $params = array(
            'courseid' => $courseid,
            'quizid' => $evaluacion->id
        );

        $results = $DB->get_records_sql($sql_users, $params);
        if (!empty($results)) {
            foreach ($results as $result) {
                $all_results[] = $result;
            }
        }
    }

    if ($format === 'pdf') {
        $pdf = new pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Moodle');
        $pdf->SetAuthor('Sistema de Evaluaciones');
        $pdf->SetTitle($course->shortname . ' - Evaluaciones');
        
        // Agrupar resultados por evaluación
        $grouped_results = array();
        foreach ($all_results as $result) {
            if (!isset($grouped_results[$result->nombre_quiz])) {
                $grouped_results[$result->nombre_quiz] = array();
            }
            $grouped_results[$result->nombre_quiz][] = $result;
        }
        
        // Por cada evaluación, crear una nueva página
        foreach ($grouped_results as $quiz_name => $quiz_results) {
            $pdf->AddPage('L');
            
            // Título de la evaluación
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, $quiz_name, 0, 1, 'C');
            $pdf->Ln(2);
            
            // Headers
            $w = array(35, 35, 25, 25, 35, 35);
            $headers = array('Nombre', 'Apellido', 'Grupo', 'Estado', 'Calificación', 'Fecha');
            
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetFont('helvetica', 'B', 8);
            
            foreach($headers as $i => $header) {
                $pdf->Cell($w[$i], 7, $header, 1, 0, 'C', true);
            }
            $pdf->Ln();
            
            // Datos
            $pdf->SetFont('helvetica', '', 8);
            foreach ($quiz_results as $row) {
                $fecha = date('d/m/Y H:i', $row->fecha_ultima_modificacion);
                
                $pdf->Cell($w[0], 6, $row->firstname, 1);
                $pdf->Cell($w[1], 6, $row->lastname, 1);
                $pdf->Cell($w[2], 6, $row->grupo, 1);
                $pdf->Cell($w[3], 6, $row->estado_completacion, 1);
                $pdf->Cell($w[4], 6, $row->calificacion, 1);
                $pdf->Cell($w[5], 6, $fecha, 1);
                $pdf->Ln();
            }
            
            // Resumen
            $total = count($quiz_results);
            $completados = count(array_filter($quiz_results, function($r) { 
                return $r->estado_completacion === 'completado'; 
            }));
            $pendientes = $total - $completados;
            
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 8);
            $pdf->Cell(0, 6, "Resumen: Total: $total, Completados: $completados, Pendientes: $pendientes", 0, 1, 'L');
        }
        
        $pdf->Output(clean_filename($course->shortname . '_evaluaciones.pdf'), 'D');
        exit;
    }

} catch (Exception $e) {
    debugging('Error: ' . $e->getMessage());
    $response = array(
        'success' => false,
        'error' => $e->getMessage()
    );
    echo json_encode($response);
}