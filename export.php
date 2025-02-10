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
                                (LOWER(q.name) LIKE '%evaluación de inducción%')
                                OR 
                                (LOWER(q.name) LIKE '%evaluación de reinducción%')
                            )
                            ORDER BY q.name ASC";
        
        $evaluaciones = $DB->get_records_sql($sql_evaluaciones, array('courseid' => $course->id));
        
        if (!empty($evaluaciones)) {
            foreach ($evaluaciones as $evaluacion) {
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

    // Evaluaciones por curso predefinidas
    $evaluaciones_curso = array(
        'GENERALIDADES' => array(
            'Evaluación de Inducción del Módulo de Generalidades'
        ),
        'TALENTO HUMANO' => array(
            'Evaluación de Inducción del Submódulo Evaluación del desempeño ( EDL )',
            'Evaluación de Inducción del Submódulo Novedades administrativas',
            'Evaluación de Inducción del Submódulo Bienestar Social',
            'Evaluación de Inducción del Submódulo de Capacitación',
            'Evaluación de Inducción del Submódulo SSGT',
            'Evaluación de Inducción del Comités',
            'Evaluación de Inducción del Módulo de Talento Humano'
        ),
        'PROCESOS' => array(
            'Evaluación de Inducción del Módulo de Procesos'
        ),
        'HERRAMIENTAS TECNOLÓGICAS' => array(
            'Evaluación de Inducción del Submódulo Ophelia',
            'Evaluación de Inducción del Módulo de Herramientas Tecnológicas'
        ),
        'SIGC' => array(
            'Evaluación de Inducción del Módulo de SIGC'
        ),
        'SEGURIDAD INFORMÁTICA' => array(
            'Evaluación de Inducción del Módulo de Seguridad Informática'
        ),
        'ENTÉRATE' => array(
            'Evaluación de Inducción del Submódulo Subdirección desarrollo sostenible',
            'Evaluación de Inducción del Submódulo Subdirección gestión comercial',
            'Evaluación de Inducción del Submódulo Oficina Asesora planeación',
            'Evaluación de Inducción del Submódulo Secretaria general'
        )
    );

    if ($format === 'pdf') {
        $pdf = new pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('Moodle');
        $pdf->SetTitle('Evaluaciones - Todos los cursos');

        // Procesar para cada tipo de usuario
        foreach (['PLANTA', 'CONTRATISTA'] as $tipo_usuario) {
            $pdf->AddPage('L');
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->SetFillColor(51, 122, 183);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->Cell(0, 10, "REPORTE DE EVALUACIONES - " . $tipo_usuario, 1, 1, 'C', true);
            
            // Obtener todos los usuarios únicos
            $usuarios = array();
            foreach ($all_course_results as $course_data) {
                if (isset($course_data[$tipo_usuario])) {
                    foreach ($course_data[$tipo_usuario] as $quiz_results) {
                        foreach ($quiz_results as $result) {
                            $usuario_key = $result->firstname . '_' . $result->lastname;
                            if (!isset($usuarios[$usuario_key])) {
                                $usuarios[$usuario_key] = array(
                                    'firstname' => $result->firstname,
                                    'lastname' => $result->lastname,
                                    'grupo' => $result->grupo
                                );
                            }
                        }
                    }
                }
            }

            foreach ($evaluaciones_curso as $curso => $evaluaciones) {
                $pdf->SetFont('helvetica', 'B', 14);
                $pdf->SetFillColor(92, 184, 92);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Ln(5);
                $pdf->Cell(0, 8, $curso, 1, 1, 'C', true);
                
                // Headers
                $w = array(60, 25);
                $evaluacion_width = 180 / count($evaluaciones);
                
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetFillColor(217, 237, 247);
                
                // Headers principales
                $pdf->Cell($w[0], 7, 'Nombre Completo', 1, 0, 'C', true);
                $pdf->Cell($w[1], 7, 'Grupo', 1, 0, 'C', true);
                foreach ($evaluaciones as $evaluacion) {
                    $pdf->Cell($evaluacion_width, 7, $evaluacion, 1, 0, 'C', true);
                }
                $pdf->Ln();
                
                // Datos
                $pdf->SetFont('helvetica', '', 9);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetTextColor(51, 51, 51);
                
                foreach ($usuarios as $usuario_key => $usuario) {
                    $pdf->Cell($w[0], 6, $usuario['firstname'] . ' ' . $usuario['lastname'], 1, 0, 'L');
                    $pdf->Cell($w[1], 6, $usuario['grupo'], 1, 0, 'C');
                    
                    foreach ($evaluaciones as $evaluacion) {
                        $estado = 'pendiente';
                        $estadoColor = array(217, 83, 79);
                        
                        foreach ($all_course_results as $course_data) {
                            if (isset($course_data[$tipo_usuario])) {
                                foreach ($course_data[$tipo_usuario] as $quiz_name => $quiz_results) {
                                    if ($quiz_name === $evaluacion) {
                                        foreach ($quiz_results as $result) {
                                            if ($result->firstname . '_' . $result->lastname === $usuario_key 
                                                && $result->estado_completacion === 'completado') {
                                                $estado = 'completado';
                                                $estadoColor = array(92, 184, 92);
                                                break 3;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        $pdf->SetTextColor($estadoColor[0], $estadoColor[1], $estadoColor[2]);
                        $pdf->Cell($evaluacion_width, 6, $estado, 1, 0, 'C');
                    }
                    $pdf->Ln();
                }
                $pdf->Ln(3);
            }
            $pdf->Ln(5);
        }
        
        $pdf->Output('Informe_General_Evaluaciones.pdf', 'D');
        exit;

    } elseif ($format === 'excel') {
        require_once($CFG->libdir . '/excellib.class.php');
        
        $workbook = new MoodleExcelWorkbook('Informe_General_Evaluaciones.xlsx');
        $workbook->send('Informe_General_Evaluaciones.xlsx');
        
        // Definir formatos
        $formato_titulo = $workbook->add_format();
        $formato_titulo->set_bold();
        $formato_titulo->set_size(12);
        $formato_titulo->set_align('center');
        $formato_titulo->set_text_wrap();
        $formato_titulo->set_border(1);
        
        $formato_header = $workbook->add_format();
        $formato_header->set_bold();
        $formato_header->set_bg_color('silver');
        $formato_header->set_align('center');
        $formato_header->set_text_wrap();
        $formato_header->set_border(1);
        
        $formato_celda = $workbook->add_format();
        $formato_celda->set_align('left');
        $formato_celda->set_text_wrap();
        $formato_celda->set_border(1);

        $formato_pendiente = $workbook->add_format();
        $formato_pendiente->set_bg_color('#FFC7CE');
        $formato_pendiente->set_align('center');
        $formato_pendiente->set_border(1);

        $formato_completado = $workbook->add_format();
        $formato_completado->set_bg_color('#C6EFCE');
        $formato_completado->set_align('center');
        $formato_completado->set_border(1);

        // Procesar cada tipo de usuario
        foreach (['PLANTA', 'CONTRATISTA'] as $tipo_usuario) {
            $worksheet = $workbook->add_worksheet($tipo_usuario);
            
            // Configurar anchos de columna
            $worksheet->set_column(0, 0, 35);
            $worksheet->set_column(1, 1, 20);
            $worksheet->set_row(0, 30);
            
            // Headers principales
            $col = 0;
            $worksheet->write(0, $col++, 'FUNCIONARIOS', $formato_titulo);
            $worksheet->write(0, $col++, 'VINCULACION', $formato_titulo);
            
            // Escribir headers de cursos y evaluaciones
            $start_col = $col;
            foreach ($evaluaciones_curso as $curso => $evaluaciones) {
                if (!empty($evaluaciones)) {
                    $end_col = $start_col + count($evaluaciones) - 1;
                    $worksheet->merge_cells(0, $start_col, 0, $end_col);
                    $worksheet->write(0, $start_col, $curso, $formato_titulo);
                    
                    foreach ($evaluaciones as $i => $evaluacion) {
                        $worksheet->write(1, $start_col + $i, $evaluacion, $formato_header);
                        $worksheet->set_column($start_col + $i, $start_col + $i, 15);
                    }
                    $start_col = $end_col + 1;
                }
            }

            // Obtener usuarios únicos
            $usuarios = array();
            foreach ($all_course_results as $course_data) {
                if (isset($course_data[$tipo_usuario])) {
                    foreach ($course_data[$tipo_usuario] as $quiz_results) {
                        foreach ($quiz_results as $result) {
                            $usuario_key = $result->firstname . '_' . $result->lastname;
                            if (!isset($usuarios[$usuario_key])) {
                                $usuarios[$usuario_key] = array(
                                    'firstname' => $result->firstname,
                                    'lastname' => $result->lastname,
                                    'grupo' => $result->grupo
                                );
                            }
                        }
                    }
                }
            }

            // Escribir datos
            $current_row = 2;
            $usuarios_procesados = array();

            foreach ($usuarios as $usuario_key => $usuario) {
                $worksheet->write($current_row, 0, $usuario['firstname'] . ' ' . $usuario['lastname'], $formato_celda);
                $worksheet->write($current_row, 1, $usuario['grupo'], $formato_celda);
                
                $col = 2;
                foreach ($evaluaciones_curso as $curso => $evaluaciones) {
                    foreach ($evaluaciones as $evaluacion) {
                        $worksheet->write($current_row, $col, 'PENDIENTE', $formato_pendiente);
                        $col++;
                    }
                }
                
                $usuarios_procesados[$usuario_key] = $current_row;
                $current_row++;
            }

            // Actualizar estados completados
            foreach ($all_course_results as $course_data) {
                if (isset($course_data[$tipo_usuario])) {
                    foreach ($course_data[$tipo_usuario] as $quiz_name => $quiz_results) {
                        foreach ($quiz_results as $result) {
                            $usuario_key = $result->firstname . '_' . $result->lastname;
                            if (isset($usuarios_procesados[$usuario_key])) {
                                $col = 2;
                                foreach ($evaluaciones_curso as $curso => $evaluaciones) {
                                    foreach ($evaluaciones as $evaluacion) {
                                        if ($evaluacion === $result->nombre_quiz && $result->estado_completacion === 'completado') {
                                            $worksheet->write($usuarios_procesados[$usuario_key], $col, 'completado', $formato_completado);
                                        }
                                        $col++;
                                    }
                                }
                            }
                        }
                    }
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
?>