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
    $response = array('success' => true, 'data' => array());

    switch($action) {
        case 'users':
            try {
                $sql = "SELECT 
                    u.id AS userid, 
                    u.firstname, 
                    u.lastname, 
                    u.department, 
                    q.id AS quizid, 
                    q.name AS nombre_quiz, 
                    c.fullname AS nombre_curso,
                    CASE 
                        WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN 'completado' 
                        ELSE 'pendiente' 
                    END AS estado_completacion,
                    CASE 
                        WHEN qa.state = 'finished' AND qa.sumgrades IS NOT NULL THEN 
                            CONCAT(ROUND((qa.sumgrades / q.grade) * 10, 2), '/10.00 (', ROUND((qa.sumgrades / q.grade) * 100, 2), '%)') 
                        ELSE '-' 
                    END AS calificacion
                FROM {user} u
                JOIN {user_enrolments} ue ON u.id = ue.userid
                JOIN {enrol} e ON ue.enrolid = e.id
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
                    AND (
                        (u.department = 'PLANTA' AND LOWER(q.name) LIKE 'evaluación de inducción%')
                        OR 
                        (u.department = 'CONTRATISTA' AND LOWER(q.name) LIKE 'evaluación de reinducción%')
                    )
                    AND u.deleted = 0
                ORDER BY 
                    nombre_curso, 
                    nombre_quiz,
                    u.lastname,
                    u.firstname";

                $params = array('courseid' => $courseid);
                
                // Debug info
                debugging("SQL Query: " . $sql);
                debugging("Parameters: " . json_encode($params));
                
                $results = $DB->get_records_sql($sql, $params);
                
                if ($results === false) {
                    throw new Exception('Error al obtener registros');
                }
                
                debugging("Results: " . print_r($results, true));
                
                $response['data'] = array_values($results);
                
            } catch (dml_exception $e) {
                debugging("DML Error: " . $e->getMessage());
                throw new Exception('Error al leer de la base de datos: ' . $e->getMessage());
            } catch (Exception $e) {
                debugging("General Error: " . $e->getMessage());
                throw new Exception('Error general: ' . $e->getMessage());
            }
            break;

        case 'summary':
            // ... mantener el código existente para el summary ...
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