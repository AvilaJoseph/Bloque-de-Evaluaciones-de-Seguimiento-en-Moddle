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

        // Agregar los estilos y JavaScript
        $this->page->requires->css(new moodle_url('/blocks/evaluaciones_seguimiento/styles.css'));
        $this->page->requires->js(new moodle_url('/blocks/evaluaciones_seguimiento/script.js'));

        // Obtener cursos disponibles
        $sql = "SELECT c.id, c.fullname 
                FROM {course} c
                JOIN {enrol} e ON c.id = e.courseid
                JOIN {user_enrolments} ue ON e.id = ue.enrolid
                WHERE c.visible = 1
                GROUP BY c.id, c.fullname
                ORDER BY c.fullname";
        
        $courses = $DB->get_records_sql($sql);

        // Contenedor principal
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

        // Resumen del curso seleccionado
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

        // Controles de exportación
        $this->content->text .= '
            <div class="export-controls mb-3">
                <button id="export-excel" class="btn btn-secondary">
                    <i class="fa fa-file-excel-o"></i> Exportar a Excel
                </button>
                <button id="export-pdf" class="btn btn-secondary">
                    <i class="fa fa-file-pdf-o"></i> Exportar a PDF
                </button>
            </div>';

        // Controles de tabla
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
        $this->content->text .= '
        <div class="resultados-table">
            <table>
                <thead>
                    <tr>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th>Última actualización</th>
                    </tr>
                </thead>
                <tbody id="resultados-body">
                    <!-- Los resultados se cargarán dinámicamente -->
                </tbody>
            </table>
        </div>';
        
        $this->content->text .= '</div>';

        // Agregar los scripts al final
        $this->content->text .= '
        <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
        ';

        return $this->content;
    }
}