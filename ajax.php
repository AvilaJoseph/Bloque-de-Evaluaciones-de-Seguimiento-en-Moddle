<?php
$dirroot = dirname(dirname(dirname(__FILE__)));
require_once($dirroot . '/config.php');
require_login();

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
require_course_login($course);

header('Content-Type: application/json; charset=utf-8');

try {
    $action = required_param('action', PARAM_ALPHA);
    $evaluacion_tipo = optional_param('eval_tipo', '', PARAM_TEXT);
    $response = array('success' => true, 'data' => array());

    switch($action) {
        case 'evaluaciones':
            try {
                if ($courseid == 3) {
                    // Obtener el departamento del usuario actual
                    $current_user = $DB->get_record('user', array('id' => $USER->id), 'department');
                    
                    // Consulta base para obtener las evaluaciones
                    $sql = "SELECT DISTINCT q.name
                            FROM {quiz} q
                            JOIN {course} c ON q.course = c.id
                            WHERE c.id = :courseid";
        
                    // Agregar filtro según el departamento del usuario
                    if ($current_user && $current_user->department == 'PLANTA') {
                        $sql .= " AND LOWER(q.name) LIKE 'evaluación de inducción%'";
                    } elseif ($current_user && $current_user->department == 'CONTRATISTA') {
                        $sql .= " AND LOWER(q.name) LIKE 'evaluación de reinducción%'";
                    }
                    
                    $sql .= " ORDER BY q.name ASC";
                    
                    debugging("User Department: " . ($current_user ? $current_user->department : 'No department'));
                    debugging("SQL Query: " . $sql);
                } else {
                    // Consulta original para otros cursos
                    $sql = "SELECT DISTINCT q.name
                            FROM {quiz} q
                            JOIN {course} c ON q.course = c.id
                            WHERE c.id = :courseid
                            AND (
                                (LOWER(q.name) LIKE 'evaluación de inducción%')
                                OR 
                                (LOWER(q.name) LIKE 'evaluación de reinducción%')
                            )
                            ORDER BY q.name ASC";
                }
                
                $params = array('courseid' => $courseid);
                $results = $DB->get_records_sql($sql, $params);
                
                if ($results === false) {
                    throw new Exception('Error al obtener evaluaciones');
                }
                
                $response['data'] = array_values($results);
                debugging("Number of evaluaciones found: " . count($response['data']));
                
            } catch (dml_exception $e) {
                debugging("DML Error: " . $e->getMessage());
                throw new Exception('Error al leer evaluaciones: ' . $e->getMessage());
            }
            break;

            case 'users':
                try {
                    $sql = "SELECT 
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
                    JOIN {enrol}    e ON ue.enrolid = e.id
                    JOIN {course} c ON e.courseid = c.id
                    JOIN {quiz} q ON q.course = c.id
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
                        AND u.deleted = 0";
            
                    if (!empty($evaluacion_tipo)) {
                        // Cuando se selecciona una evaluación específica
                        $sql .= " AND q.name = :eval_tipo";
                        
                        // Agregar filtro de departamento basado en el tipo de evaluación
                        if (stripos($evaluacion_tipo, 'reinducción') !== false) {
                            $sql .= " AND u.department = 'CONTRATISTA'";
                        } elseif (stripos($evaluacion_tipo, 'inducción') !== false) {
                            $sql .= " AND u.department = 'PLANTA'";
                        }
                        
                        $params = array(
                            'courseid' => $courseid,
                            'eval_tipo' => $evaluacion_tipo
                        );
                        
                        debugging("Tipo de evaluación: " . $evaluacion_tipo);
                        debugging("Department filter applied: " . (stripos($evaluacion_tipo, 'reinducción') !== false ? 'CONTRATISTA' : 'PLANTA'));
                    } else {
                        // Cuando no se selecciona una evaluación específica
                        $sql .= " AND (
                            (u.department = 'PLANTA' AND LOWER(q.name) LIKE 'evaluación de inducción%')
                            OR 
                            (u.department = 'CONTRATISTA' AND LOWER(q.name) LIKE 'evaluación de reinducción%')
                        )";
                        
                        $params = array('courseid' => $courseid);
                    }
            
                    $sql .= " ORDER BY nombre_quiz, u.lastname, u.firstname";
                    
                    debugging("SQL Query: " . $sql);
                    debugging("Parameters: " . json_encode($params));
                    
                    $results = $DB->get_records_sql($sql, $params);
                    
                    if ($results === false) {
                        throw new Exception('Error al obtener registros');
                    }
                    
                    debugging("Results count: " . count($results));
                    
                    $response['data'] = array_values($results);
                    
                } catch (dml_exception $e) {
                    debugging("DML Error: " . $e->getMessage());
                    throw new Exception('Error al leer de la base de datos: ' . $e->getMessage());
                }
                break;

        case 'summary':
            try {
                $sql = "SELECT 
                    COUNT(DISTINCT u.id) AS total_students,
                    COUNT(DISTINCT CASE 
                        WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN u.id 
                    END) AS completed,
                    COUNT(DISTINCT u.id) - COUNT(DISTINCT CASE 
                        WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN u.id 
                    END) AS pending,
                    ROUND(
                        (COUNT(DISTINCT CASE 
                            WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN u.id 
                        END) * 100.0 / NULLIF(COUNT(DISTINCT u.id), 0)),
                        2
                    ) AS completion_percentage
                FROM {user} u
                INNER JOIN {user_enrolments} ue ON u.id = ue.userid
                INNER JOIN {enrol} e ON ue.enrolid = e.id
                INNER JOIN {course} c ON e.courseid = c.id
                INNER JOIN {quiz} q ON q.course = c.id
                LEFT JOIN (
                    SELECT 
                        userid,
                        quiz,
                        state,
                        sumgrades
                    FROM {quiz_attempts} qa1
                    WHERE attempt = (
                        SELECT MAX(attempt)
                        FROM {quiz_attempts} qa2
                        WHERE qa2.userid = qa1.userid 
                        AND qa2.quiz = qa1.quiz
                    )
                ) qa ON qa.userid = u.id AND qa.quiz = q.id
                WHERE 
                    e.courseid = :courseid
                    AND u.deleted = 0
                    AND ue.status = 0";

                $params = array('courseid' => $courseid);

                if ($courseid == 3 && !empty($evaluacion_tipo)) {
                    $sql .= " AND q.name = :eval_tipo";
                    $params['eval_tipo'] = $evaluacion_tipo;
                } else {
                    $sql .= " AND (
                        (u.department = 'PLANTA' AND LOWER(q.name) LIKE 'evaluación de inducción%')
                        OR 
                        (u.department = 'CONTRATISTA' AND LOWER(q.name) LIKE 'evaluación de reinducción%')
                    )";
                }
                
                $result = $DB->get_record_sql($sql, $params);
                
                if ($result === false) {
                    throw new Exception('No se encontraron datos para el resumen');
                }

                $response['data'] = $result;
            } catch (dml_exception $e) {
                throw new Exception('Error al leer de la base de datos: ' . $e->getMessage());
            }
            break;

        default:
            throw new Exception('Acción no válida');
    }

    echo json_encode($response);

} catch (Exception $e) {
    debugging('Error: ' . $e->getMessage());
    $response = array(
        'success' => false,
        'error' => $e->getMessage()
    );
    echo json_encode($response);
}