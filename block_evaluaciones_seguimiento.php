<?php
defined('MOODLE_INTERNAL') || die();

class block_evaluaciones_seguimiento extends block_base {
    public function init() {
        $this->title = get_string('evaluaciones_seguimiento', 'block_evaluaciones_seguimiento');
    }

    public function get_content() {
        global $DB, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;

        $this->page->requires->css(new moodle_url('/blocks/evaluaciones_seguimiento/styles.css'));
        $this->page->requires->js(new moodle_url('/blocks/evaluaciones_seguimiento/script.js'));

        $sql = "SELECT c.id, c.fullname 
            FROM {course} c
            JOIN {enrol} e ON c.id = e.courseid
            JOIN {user_enrolments} ue ON e.id = ue.enrolid
            WHERE c.visible = 1 
            AND c.fullname NOT LIKE '%Certificación%'
            GROUP BY c.id, c.fullname
            ORDER BY c.fullname";
        
        $courses = $DB->get_records_sql($sql);

        $this->content->text = '<div id="evaluaciones-container">';
        
        // Botones de cursos
        $this->content->text .= '<div class="curso-buttons">';
        foreach ($courses as $course) {
            $this->content->text .= sprintf(
                '<button class="curso-btn" data-courseid="%d">%s</button>',
                $course->id,
                $course->fullname
            );
        }
        $this->content->text .= '</div>';

        // Resumen
        $this->content->text .= '
        <div id="course-summary" class="course-summary hidden">
            <div class="stats-grid">
                <div class="stat-box">
                    <span class="stat-label">Total Estudiantes</span>
                    <span class="stat-value" id="total-students">-</span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Completados</span>
                    <span class="stat-value" id="completed-count">-</span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Pendientes</span>
                    <span class="stat-value" id="pending-count">-</span>
                </div>
                <div class="stat-box">
                <span class="stat-label">% Completado</span>
                    <span class="stat-value" id="completion-percentage">-</span>
                </div>
            </div>
        </div>';

        // Filtros
        $this->content->text .= '
        <div class="filter-controls mb-3">
            <select id="filter-quiz" class="form-select me-2">
                <option value="">Todas las evaluaciones</option>
            </select>
            <select id="filter-status" class="form-select">
                <option value="">Todos los estados</option>
                <option value="completado">Completado</option>
                <option value="pendiente">Pendiente</option>
            </select>
        </div>';

        // Botones de exportación
        $this->content->text .= '
            <div class="export-controls mb-3">
                <button id="export-excel" class="btn btn-secondary">
                    <i class="fa fa-file-excel-o"></i> Exportar a Excel
                </button>
                <button id="export-pdf" class="btn btn-secondary">
                    <i class="fa fa-file-pdf-o"></i> Exportar a PDF
                </button>
            </div>';

        // Control de registros por página
        $this->content->text .= '
            <div class="table-controls mb-3">
                <select id="records-per-page" class="form-select" style="width: auto;">
                    <option value="10">10 registros</option>
                    <option value="25">25 registros</option>
                    <option value="50">50 registros</option>
                    <option value="100">100 registros</option>
                    <option value="0">Todos los registros</option>
                </select>
            </div>';
                    
        // Tabla de resultados
        $this->content->text .= '<div class="resultados-table">
            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Apellido</th>
                        <th>Grupo</th>
                        <th>Estado</th>
                        <th>Calificación</th>
                        <th>Última Modificación</th>
                    </tr>
                </thead>
                <tbody id="resultados-body">
                </tbody>
            </table>
        </div>';
        
        $this->content->text .= '</div>';


        return $this->content;
    }
}