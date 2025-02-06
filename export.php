<?php
$dirroot = dirname(dirname(dirname(__FILE__)));
require_once($dirroot . '/config.php');
require_once($CFG->libdir . '/moodlelib.php');
require_once($CFG->libdir . '/pdflib.php');
require_login();

try {
    $format = required_param('format', PARAM_ALPHA);
    
    // Obtener solo los cursos específicos
    $course_ids = array(2, 3, 4, 5, 6, 8, 11);
    $courses = $DB->get_records_list('course', 'id', $course_ids, 'sortorder, id');

    $all_course_results = array();

    foreach ($courses as $course) {
        require_course_login($course);

        // Obtener evaluaciones del curso
        $sql_evaluaciones = "SELECT DISTINCT q.id, q.name
                            FROM {quiz} q
                            WHERE q.course = :courseid
                            AND (
                                (LOWER(q.name) LIKE '%inducción%')
                                OR 
                                (LOWER(q.name) LIKE '%reinducción%')
                            )
                            ORDER BY q.name ASC";
        
        $evaluaciones = $DB->get_records_sql($sql_evaluaciones, array('courseid' => $course->id));
        
        if (!empty($evaluaciones)) {
            foreach ($evaluaciones as $evaluacion) {
                // Consulta para PLANTA
                $sql_users = "SELECT 
                    u.firstname,
                    u.lastname,
                    u.department AS grupo,
                    q.name AS nombre_quiz,
                    c.fullname AS curso,
                    CASE 
                        WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN 'completado' 
                        ELSE 'pendiente' 
                    END AS estado_completacion,
                    CASE 
                        WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN 
                            CONCAT(ROUND((qa.sumgrades / q.grade) * 10, 2), '/10.00') 
                        ELSE '-' 
                    END AS calificacion,
                    COALESCE(qa.timefinish, ue.timemodified) AS fecha_ultima_modificacion
                FROM {user} u
                JOIN {user_enrolments} ue ON u.id = ue.userid
                JOIN {enrol} e ON ue.enrolid = e.id
                JOIN {course} c ON e.courseid = c.id
                JOIN {quiz} q ON q.course = c.id AND q.id = :quizid
                LEFT JOIN (
                    SELECT userid, quiz, state, sumgrades, timefinish
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
                    AND CASE 
                        WHEN q.name LIKE '%Reinducción%' THEN u.department = 'CONTRATISTA'
                        WHEN q.name LIKE '%Inducción%' THEN u.department = 'PLANTA'
                    END
                ORDER BY u.lastname, u.firstname";

                $params = array(
                    'courseid' => $course->id,
                    'quizid' => $evaluacion->id
                );

                $results = $DB->get_records_sql($sql_users, $params);
                if (!empty($results)) {
                    foreach ($results as $result) {
                        $user_type = $result->grupo;
                        if (!isset($all_course_results[$course->fullname][$user_type][$result->nombre_quiz])) {
                            $all_course_results[$course->fullname][$user_type][$result->nombre_quiz] = array();
                        }
                        $all_course_results[$course->fullname][$user_type][$result->nombre_quiz][] = $result;
                    }
                }
            }
        }
    }

    if ($format === 'pdf') {
        $pdf = new pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Moodle');
        $pdf->SetTitle('Evaluaciones - Todos los cursos');
    
        foreach ($all_course_results as $course_name => $course_data) {
            $pdf->AddPage('L');
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->SetFillColor(51, 122, 183); // Azul
            $pdf->SetTextColor(255, 255, 255); // Texto blanco
            $pdf->Cell(0, 10, $course_name, 1, 1, 'C', true);
            
            foreach ($course_data as $user_type => $evaluaciones) {
                $pdf->SetFont('helvetica', 'B', 14);
                $pdf->SetFillColor(92, 184, 92); // Verde
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Ln(5);
                $pdf->Cell(0, 8, "Grupo: " . $user_type, 1, 1, 'C', true);
                
                foreach ($evaluaciones as $quiz_name => $quiz_results) {
                    $pdf->SetFont('helvetica', 'B', 12);
                    $pdf->SetFillColor(240, 240, 240); // Gris claro
                    $pdf->SetTextColor(51, 51, 51); // Texto oscuro
                    $pdf->Ln(3);
                    $pdf->Cell(0, 8, $quiz_name, 1, 1, 'L', true);
                    
                    // Headers
                    $w = array(35, 35, 25, 25, 35, 35);
                    $headers = array('Nombre', 'Apellido', 'Grupo', 'Estado', 'Calificación', 'Fecha');
                    
                    $pdf->SetFillColor(217, 237, 247); // Azul claro
                    $pdf->SetFont('helvetica', 'B', 9);
                    
                    foreach($headers as $i => $header) {
                        $pdf->Cell($w[$i], 7, $header, 1, 0, 'C', true);
                    }
                    $pdf->Ln();
                    
                    // Datos
                    $pdf->SetFont('helvetica', '', 9);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->SetTextColor(51, 51, 51);
                    
                    $fill = false;
                    foreach ($quiz_results as $row) {
                        $fecha = date('d/m/Y H:i', $row->fecha_ultima_modificacion);
                        
                        $fill = !$fill;
                        $fillColor = $fill ? array(249, 249, 249) : array(255, 255, 255);
                        $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
                        
                        $pdf->Cell($w[0], 6, $row->firstname, 1, 0, 'L', true);
                        $pdf->Cell($w[1], 6, $row->lastname, 1, 0, 'L', true);
                        $pdf->Cell($w[2], 6, $row->grupo, 1, 0, 'C', true);
                        
                        // Color para el estado
                        $estadoColor = $row->estado_completacion === 'completado' ? 
                            array(92, 184, 92) : array(217, 83, 79);
                        $pdf->SetTextColor($estadoColor[0], $estadoColor[1], $estadoColor[2]);
                        $pdf->Cell($w[3], 6, $row->estado_completacion, 1, 0, 'C', true);
                        $pdf->SetTextColor(51, 51, 51);
                        
                        $pdf->Cell($w[4], 6, $row->calificacion, 1, 0, 'C', true);
                        $pdf->Cell($w[5], 6, $fecha, 1, 0, 'C', true);
                        $pdf->Ln();
                    }
                    
                    // Resumen
                    $total = count($quiz_results);
                    $completados = count(array_filter($quiz_results, function($r) { 
                        return $r->estado_completacion === 'completado'; 
                    }));
                    $pendientes = $total - $completados;
                    
                    $pdf->Ln(2);
                    $pdf->SetFont('helvetica', 'B', 9);
                    $pdf->SetFillColor(217, 237, 247);
                    $pdf->Cell(0, 6, "Resumen: Total: $total, Completados: $completados, Pendientes: $pendientes", 1, 1, 'L', true);
                    $pdf->Ln(3);
                }
            }
        }
        
        $pdf->Output('Informe_General_Evaluaciones.pdf', 'D');
        exit;

    } elseif ($format === 'excel') {
        require_once($CFG->libdir . '/excellib.class.php');
        
        $workbook = new MoodleExcelWorkbook('Informe_General_Evaluaciones.xlsx');
        $workbook->send('Informe_General_Evaluaciones.xlsx');
        
        foreach ($all_course_results as $course_name => $course_data) {
            $safe_sheet_name = clean_filename(substr($course_name, 0, 25));
            $safe_sheet_name = preg_replace('/[^a-zA-Z0-9_]/', '', $safe_sheet_name);
            $worksheet = $workbook->add_worksheet($safe_sheet_name);
            
            // Configurar anchos de columna
            $worksheet->set_column(0, 0, 25);  // Nombre
            $worksheet->set_column(1, 1, 25);  // Apellido
            $worksheet->set_column(2, 2, 15);  // Grupo
            $worksheet->set_column(3, 3, 15);  // Estado
            $worksheet->set_column(4, 4, 20);  // Calificación
            $worksheet->set_column(5, 5, 20);  // Fecha
            
            // Formatos
            $formato_titulo = $workbook->add_format();
            $formato_titulo->set_bold();
            $formato_titulo->set_size(12);
            $formato_titulo->set_align('center');
            $formato_titulo->set_text_wrap();
            
            $formato_header = $workbook->add_format();
            $formato_header->set_bold();
            $formato_header->set_bg_color('silver');
            $formato_header->set_align('center');
            $formato_header->set_text_wrap();
            
            $formato_celda = $workbook->add_format();
            $formato_celda->set_align('left');
            $formato_celda->set_text_wrap();
            
            // Nombre del curso
            $worksheet->write(0, 0, $course_name, $formato_titulo);
            $worksheet->merge_cells(0, 0, 0, 5);
            $worksheet->set_row(0, 30); // Altura para el título
            
            $current_row = 2;
            
            foreach (['PLANTA', 'CONTRATISTA'] as $user_type) {
                if (isset($course_data[$user_type])) {
                    $worksheet->write($current_row, 0, "Grupo: " . $user_type, $formato_titulo);
                    $worksheet->merge_cells($current_row, 0, $current_row, 5);
                    $worksheet->set_row($current_row, 25);
                    $current_row += 2;
                    
                    foreach ($course_data[$user_type] as $quiz_name => $quiz_results) {
                        $worksheet->write($current_row, 0, $quiz_name, $formato_titulo);
                        $worksheet->merge_cells($current_row, 0, $current_row, 5);
                        $worksheet->set_row($current_row, 25);
                        $current_row += 2;
                        
                        $headers = array('Nombre', 'Apellido', 'Grupo', 'Estado', 'Calificación', 'Fecha');
                        foreach ($headers as $col => $header) {
                            $worksheet->write($current_row, $col, $header, $formato_header);
                        }
                        $worksheet->set_row($current_row, 20);
                        $current_row++;
                        
                        foreach ($quiz_results as $result) {
                            $fecha = date('d/m/Y H:i', $result->fecha_ultima_modificacion);
                            
                            $worksheet->write($current_row, 0, $result->firstname, $formato_celda);
                            $worksheet->write($current_row, 1, $result->lastname, $formato_celda);
                            $worksheet->write($current_row, 2, $result->grupo, $formato_celda);
                            $worksheet->write($current_row, 3, $result->estado_completacion, $formato_celda);
                            $worksheet->write($current_row, 4, $result->calificacion, $formato_celda);
                            $worksheet->write($current_row, 5, $fecha, $formato_celda);
                            $current_row++;
                        }
                        
                        $total = count($quiz_results);
                        $completados = count(array_filter($quiz_results, function($r) { 
                            return $r->estado_completacion === 'completado'; 
                        }));
                        $pendientes = $total - $completados;
                        
                        $current_row++;
                        $formato_resumen = $workbook->add_format();
                        $formato_resumen->set_bold();
                        $formato_resumen->set_align('left');
                        $worksheet->write($current_row, 0, "Resumen: Total: $total, Completados: $completados, Pendientes: $pendientes", $formato_resumen);
                        $current_row += 2;
                    }
                    $current_row += 1;
                }
            }
        }
        
        $workbook->close();
        exit;
    }

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo "Error: " . $e->getMessage();
    die();
}