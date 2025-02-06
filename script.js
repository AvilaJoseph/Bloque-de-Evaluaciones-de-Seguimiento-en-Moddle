// Variables globales
let allUsers = [];
let recordsPerPage = 10;
let currentCourseId = null;

document.addEventListener('DOMContentLoaded', function() {
    console.log('Script initialized');
    initializeBlock();
    
    // Agregar listener para el cambio de registros por página
    document.getElementById('records-per-page').addEventListener('change', function(e) {
        recordsPerPage = parseInt(e.target.value);
        updateUsersTableUI(allUsers);
    });

    // Agregar listener para el filtro de evaluaciones
    document.getElementById('filter-quiz').addEventListener('change', async function(e) {
        const evaluacionTipo = e.target.value;
        if (currentCourseId) {
            showLoadingState();
            await Promise.all([
                loadCourseSummary(currentCourseId, evaluacionTipo),
                loadCourseUsers(currentCourseId, evaluacionTipo)
            ]);
        }
    });

    // Agregar listener para el filtro de estado
    document.getElementById('filter-status').addEventListener('change', function(e) {
        filterByStatus(e.target.value);
    });

    // Add export button listeners
    document.getElementById('export-excel').addEventListener('click', async function() {
        await exportFile('excel');
    });

    document.getElementById('export-pdf').addEventListener('click', async function() {
        await exportFile('pdf');
    });
});

async function exportFile(format) {
    try {
        const exportBtn = document.getElementById(`export-${format}`);
        if (!exportBtn) {
            showError('Botón de exportación no encontrado');
            return;
        }

        const btnText = exportBtn.innerHTML;
        exportBtn.innerHTML = '<span class="loader-small"></span> Exportando...';
        exportBtn.disabled = true;

        try {
            window.location.href = `/moddle/blocks/evaluaciones_seguimiento/export.php?format=${format}`;
        } finally {
            setTimeout(() => {
                exportBtn.innerHTML = btnText;
                exportBtn.disabled = false;
            }, 2000);
        }
    } catch (error) {
        console.error('Error en exportación:', error);
        showError(`Error al exportar: ${error.message}`);
        
        const exportBtn = document.getElementById(`export-${format}`);
        if (exportBtn) {
            exportBtn.innerHTML = btnText;
            exportBtn.disabled = false;
        }
    }
}

function initializeBlock() {
    const buttons = document.querySelectorAll('.curso-btn');
    
    buttons.forEach(button => {
        button.addEventListener('click', async function() {
            try {
                currentCourseId = this.dataset.courseid;
                console.log('Course button clicked:', currentCourseId);
                
                buttons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                
                showLoadingState();
                
                // Cargar evaluaciones disponibles
                await loadEvaluaciones(currentCourseId);
                
                // Cargar datos iniciales
                await Promise.all([
                    loadCourseSummary(currentCourseId),
                    loadCourseUsers(currentCourseId)
                ]);
            } catch (error) {
                console.error('Error in button click handler:', error);
                showError('Error al cargar los datos del curso');
            }
        });
    });
}

async function loadEvaluaciones(courseId) {
    try {
        const response = await fetch(`/moddle/blocks/evaluaciones_seguimiento/ajax.php?action=evaluaciones&courseid=${courseId}`, {  // Cambiado de 'get_evaluaciones' a 'evaluaciones'
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Error desconocido');
        }

        // Actualizar el select de evaluaciones
        const filterQuiz = document.getElementById('filter-quiz');
        filterQuiz.innerHTML = '<option value="">Todas las evaluaciones</option>';
        result.data.forEach(eval => {
            const option = document.createElement('option');
            option.value = eval.name;
            option.textContent = eval.name;
            filterQuiz.appendChild(option);
        });

    } catch (error) {
        console.error('Error loading evaluaciones:', error);
        showError('Error al cargar las evaluaciones');
    }
}

async function loadCourseSummary(courseId, evaluacionTipo = '') {
    try {
        let url = `/moddle/blocks/evaluaciones_seguimiento/ajax.php?action=summary&courseid=${courseId}`;
        if (evaluacionTipo) {
            url += `&eval_tipo=${encodeURIComponent(evaluacionTipo)}`;
        }

        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || 'Error desconocido');
        }

        updateSummaryUI(data.data);
    } catch (error) {
        console.error('Error loading summary:', error);
        updateSummaryUI({
            total_students: 'Error',
            completed: 'Error',
            pending: 'Error',
            completion_percentage: 'Error'
        });
    }
}

async function loadCourseUsers(courseId, evaluacionTipo = '') {
    try {
        let url = `/moddle/blocks/evaluaciones_seguimiento/ajax.php?action=users&courseid=${courseId}`;
        if (evaluacionTipo) {
            url += `&eval_tipo=${encodeURIComponent(evaluacionTipo)}`;
        }

        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Cache-Control': 'no-cache'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Error desconocido');
        }

        allUsers = result.data;
        updateUsersTableUI(result.data);
    } catch (error) {
        console.error('Error loading users:', error);
        showTableError('Error al cargar los datos de los usuarios');
    }
}

function filterByStatus(status) {
    if (!allUsers || !allUsers.length) return;

    let filteredUsers = allUsers;
    if (status) {
        filteredUsers = allUsers.filter(user => user.estado_completacion === status);
    }

    updateUsersTableUI(filteredUsers);
}

function showLoadingState() {
    document.getElementById('total-students').textContent = '-';
    document.getElementById('completed-count').textContent = '-';
    document.getElementById('pending-count').textContent = '-';
    document.getElementById('completion-percentage').textContent = '-';
    
    const tbody = document.getElementById('resultados-body');
    tbody.innerHTML = '';
    const loadingRow = document.createElement('tr');
    loadingRow.innerHTML = `
        <td colspan="6" class="text-center">
            <div class="loader"></div>
            <p>Cargando datos...</p>
        </td>
    `;
    tbody.appendChild(loadingRow);
}

function updateSummaryUI(data) {
    if (!data) return;

    document.getElementById('total-students').textContent = data.total_students || '0';
    document.getElementById('completed-count').textContent = data.completed || '0';
    document.getElementById('pending-count').textContent = data.pending || '0';
    document.getElementById('completion-percentage').textContent = 
        data.completion_percentage ? `${data.completion_percentage}%` : '0%';
}

function updateUsersTableUI(users) {
    const tbody = document.getElementById('resultados-body');
    tbody.innerHTML = '';
    
    if (!users || !Array.isArray(users) || users.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" class="text-center">No se encontraron usuarios</td>
            </tr>
        `;
        return;
    }
    
    const usersToShow = recordsPerPage === 0 ? users : users.slice(0, recordsPerPage);
    usersToShow.forEach(user => {
        const tr = document.createElement('tr');
        
        const status = user.estado_completacion || 'pendiente';
        const statusClass = status.toLowerCase();

        // Formatear la fecha si existe
        const fecha_ultima_modificacion = user.fecha_ultima_modificacion ? 
            new Date(parseInt(user.fecha_ultima_modificacion) * 1000).toLocaleString() : '-';

        tr.innerHTML = `
            <td>${escapeHtml(user.firstname || '')}</td>
            <td>${escapeHtml(user.lastname || '')}</td>
            <td>${escapeHtml(user.grupo || '')}</td>
            <td class="status-${statusClass}">${escapeHtml(status)}</td>
            <td>${escapeHtml(user.calificacion || '-')}</td>
            <td>${fecha_ultima_modificacion}</td>
        `;
        tbody.appendChild(tr);
    });

    const infoRow = document.createElement('tr');
    infoRow.innerHTML = `
        <td colspan="6" class="text-center text-muted">
            Mostrando ${recordsPerPage === 0 ? users.length : Math.min(recordsPerPage, users.length)} de ${users.length} registros
        </td>
    `;
    tbody.appendChild(infoRow);
}

function showTableError(message) {
    const tbody = document.getElementById('resultados-body');
    tbody.innerHTML = `
        <tr>
            <td colspan="6" class="error-message text-center">
                ${escapeHtml(message)}
            </td>
        </tr>
    `;
}

function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'error-message';
    errorDiv.textContent = message;
    
    const container = document.getElementById('evaluaciones-container');
    if (container.firstChild.classList && container.firstChild.classList.contains('error-message')) {
        container.removeChild(container.firstChild);
    }
    container.insertBefore(errorDiv, container.firstChild);
    
    setTimeout(() => {
        if (errorDiv.parentNode === container) {
            errorDiv.remove();
        }
    }, 5000);
}

function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return '';
    
    return unsafe
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}