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
                    g.name AS groupname,
                    CASE 
                        WHEN MAX(qa.state) = 'finished' AND MAX(qa.sumgrades) IS NOT NULL THEN 'completado'
                        ELSE 'pendiente'
                    END AS estado_completacion,
                    CASE
                        WHEN MAX(qa.state) = 'finished' AND MAX(qa.sumgrades) IS NOT NULL 
                        THEN CONCAT(
                            ROUND((MAX(qa.sumgrades) / MAX(q.grade)) * 10, 2),
                            '/10.00 (',
                            ROUND((MAX(qa.sumgrades) / MAX(q.grade)) * 100, 2),
                            '%)'
                        )
                        ELSE '-'
                    END AS calificacion,
                    COALESCE(MAX(qa.timefinish), MAX(ue.timemodified)) AS fecha_ultima_modificacion
                FROM 
                    {user} u
                INNER JOIN {user_enrolments} ue ON u.id = ue.userid
                INNER JOIN {enrol} e ON ue.enrolid = e.id AND e.courseid = :courseid
                LEFT JOIN {groups_members} gm ON u.id = gm.userid
                LEFT JOIN {groups} g ON gm.groupid = g.id AND g.courseid = e.courseid
                INNER JOIN {quiz} q ON q.course = e.courseid
                LEFT JOIN {quiz_attempts} qa ON qa.userid = u.id 
                    AND qa.quiz = q.id 
                    AND qa.state = 'finished'
                    AND qa.sumgrades IS NOT NULL
                WHERE 
                    u.deleted = 0
                    AND ue.status = 0
                    AND (ue.timeend = 0 OR ue.timeend > :currenttime)
                    AND (g.name = 'CONTRATISTA' OR g.name = 'PLANTA')
                GROUP BY 
                    u.id,
                    u.firstname,
                    u.lastname,
                    g.name
                ORDER BY 
                    u.lastname ASC, 
                    u.firstname ASC";
                
                $params = array(
                    'courseid' => $courseid,
                    'currenttime' => time()
                );
                
                // Debug info
                debugging("SQL Query: " . $sql);
                debugging("Params: " . json_encode($params));
                
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
                LEFT JOIN {groups_members} gm ON u.id = gm.userid
                LEFT JOIN {groups} g ON gm.groupid = g.id AND g.courseid = e.courseid
                INNER JOIN {quiz} q ON q.course = e.courseid
                LEFT JOIN {quiz_attempts} qa ON qa.userid = u.id 
                    AND qa.quiz = q.id
                WHERE e.courseid = :courseid
                    AND u.deleted = 0
                    AND ue.status = 0
                    AND (ue.timeend = 0 OR ue.timeend > :currenttime)
                    AND (g.name = 'CONTRATISTA' OR g.name = 'PLANTA')";
                
                $params = [
                    'courseid' => $courseid,
                    'currenttime' => time()
                ];
                
                $result = $DB->get_record_sql($sql, $params);
                
                if ($result === false) {
                    throw new Exception('No se encontraron datos para el resumen');
                }

                $response['data'] = $result;
            } catch (dml_exception $e) {
                debugging("DML Error: " . $e->getMessage());
                throw new Exception('Error al leer de la base de datos: ' . $e->getMessage());
            } catch (Exception $e) {
                debugging("General Error: " . $e->getMessage());
                throw new Exception('Error general: ' . $e->getMessage());
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