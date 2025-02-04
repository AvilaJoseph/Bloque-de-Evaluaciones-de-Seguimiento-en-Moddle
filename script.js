// Variables globales para manejar la paginación
let allUsers = [];
let recordsPerPage = 10;

document.addEventListener('DOMContentLoaded', function() {
   console.log('Script initialized');
   initializeBlock();
   
   // Agregar listener para el cambio de registros por página
   document.getElementById('records-per-page').addEventListener('change', function(e) {
       recordsPerPage = parseInt(e.target.value);
       updateUsersTableUI(allUsers);
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
        const activeCourseBtn = document.querySelector('.curso-btn.active');
        if (!activeCourseBtn) {
            showError('Por favor, seleccione un curso primero');
            return;
        }

        // Obtener el botón y guardar su texto original
        const exportBtn = document.getElementById(`export-${format}`);
        if (!exportBtn) {
            showError('Botón de exportación no encontrado');
            return;
        }
        const btnText = exportBtn.innerHTML;

        try {
            // Mostrar estado de carga
            exportBtn.innerHTML = '<span class="loader-small"></span> Exportando...';
            exportBtn.disabled = true;

            // Preparar los datos
            const data = allUsers.map(user => ({
                'Nombre': user.firstname || '',
                'Apellido': user.lastname || '',
                'Grupo': user.groupname || '',
                'Estado': user.estado_completacion || 'pendiente',
                'Calificación': user.calificacion || '-',
                'Última Modificación': user.fecha_ultima_modificacion ? 
                    new Date(parseInt(user.fecha_ultima_modificacion) * 1000).toLocaleString() : '-'
            }));

            if (format === 'excel') {
                if (typeof XLSX === 'undefined') {
                    throw new Error('La librería XLSX no está cargada correctamente');
                }
                const ws = XLSX.utils.json_to_sheet(data);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, "Evaluaciones");
                XLSX.writeFile(wb, "evaluaciones.xlsx");
            } 
            else if (format === 'pdf') {
                if (typeof window.jspdf === 'undefined') {
                    throw new Error('La librería jsPDF no está cargada correctamente');
                }
                
                // Crear nuevo documento PDF
                const { jsPDF } = window.jspdf;
                if (!jsPDF) {
                    throw new Error('Constructor jsPDF no disponible');
                }
                
                const doc = new jsPDF({
                    orientation: "portrait",
                    unit: "mm",
                    format: "a4"
                });

                // Título
                doc.setFont("helvetica");
                doc.setFontSize(12);
                doc.text("Reporte de Evaluaciones", 20, 20);

                // Tabla
                if (typeof doc.autoTable === 'undefined') {
                    throw new Error('Plugin autoTable no está disponible');
                }

                doc.autoTable({
                    head: [['Nombre', 'Apellido', 'Grupo', 'Estado', 'Calificación', 'Última Modificación']],
                    body: data.map(item => [
                        item.Nombre,
                        item.Apellido,
                        item.Grupo,
                        item.Estado,
                        item.Calificación,
                        item['Última Modificación']
                    ]),
                    startY: 30,
                    margin: { top: 20 },
                    styles: { fontSize: 8, cellPadding: 2 },
                    headStyles: { fillColor: [41, 128, 185], textColor: 255 },
                    alternateRowStyles: { fillColor: [245, 245, 245] }
                });

                doc.save("evaluaciones.pdf");
            }
        } finally {
            // Restaurar el botón
            exportBtn.innerHTML = btnText;
            exportBtn.disabled = false;
        }

    } catch (error) {
        console.error('Error en exportación:', error);
        showError(`Error al exportar: ${error.message}`);
        
        // Restaurar el botón en caso de error
        const exportBtn = document.getElementById(`export-${format}`);
        if (exportBtn) {
            exportBtn.innerHTML = btnText;
            exportBtn.disabled = false;
        }
    }
}

function initializeBlock() {
   const buttons = document.querySelectorAll('.curso-btn');
   const courseSummary = document.getElementById('course-summary');
   const loader = document.createElement('div');
   loader.className = 'loader';
   
   buttons.forEach(button => {
       button.addEventListener('click', async function() {
           try {
               const courseId = this.dataset.courseid;
               console.log('Course button clicked:', courseId);
               
               // Actualizar botón activo
               buttons.forEach(btn => btn.classList.remove('active'));
               this.classList.add('active');
               
               // Mostrar estado de carga
               showLoadingState(courseSummary, loader);
               
               // Cargar datos en paralelo
               await Promise.all([
                   loadCourseSummary(courseId),
                   loadCourseUsers(courseId)
               ]);
           } catch (error) {
               console.error('Error in button click handler:', error);
               showError('Error al cargar los datos del curso');
           }
       });
   });
}

async function loadCourseSummary(courseId) {
   try {
       const response = await fetch(`/moddle/blocks/evaluaciones_seguimiento/ajax.php?action=summary&courseid=${courseId}`, {
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

async function loadCourseUsers(courseId) {
   try {
       const response = await fetch(`/moddle/blocks/evaluaciones_seguimiento/ajax.php?action=users&courseid=${courseId}`, {
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

       updateUsersTableUI(result.data);
   } catch (error) {
       console.error('Error loading users:', error);
       showTableError('Error al cargar los datos de los usuarios');
   }
}

function showLoadingState(courseSummary, loader) {
   courseSummary.classList.remove('hidden');
   
   // Limpiar datos anteriores
   document.getElementById('total-students').textContent = '-';
   document.getElementById('completed-count').textContent = '-';
   document.getElementById('pending-count').textContent = '-';
   document.getElementById('completion-percentage').textContent = '-';
   
   // Mostrar loader en la tabla
   const tbody = document.getElementById('resultados-body');
   tbody.innerHTML = '';
   const loadingRow = document.createElement('tr');
   loadingRow.innerHTML = `
       <td colspan="3" class="text-center">
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
               <td colspan="3" class="text-center">No se encontraron usuarios</td>
           </tr>
       `;
       return;
   }

   // Guardar todos los usuarios
   allUsers = users;
   
   // Mostrar solo la cantidad seleccionada
   const usersToShow = recordsPerPage === 0 ? users : users.slice(0, recordsPerPage);
   usersToShow.forEach(user => {
       const tr = document.createElement('tr');
       
       const firstName = user.firstname || '';
       const lastName = user.lastname || '';
       const status = user.estado_completacion || 'pendiente';
       const statusClass = (status && typeof status === 'string') ? status.toLowerCase() : 'pendiente';
       
       const completionDate = user.fecha_ultima_modificacion 
           ? new Date(parseInt(user.fecha_ultima_modificacion) * 1000).toLocaleString()
           : '-';

       tr.innerHTML = `
           <td>${escapeHtml(firstName)} ${escapeHtml(lastName)}</td>
           <td class="status-${escapeHtml(statusClass)}">
               ${escapeHtml(status)}
           </td>
           <td>${completionDate}</td>
       `;
       tbody.appendChild(tr);
   });

   // Agregar información sobre registros mostrados
   const infoRow = document.createElement('tr');
   infoRow.innerHTML = `
       <td colspan="3" class="text-center text-muted">
           Mostrando ${recordsPerPage === 0 ? users.length : Math.min(recordsPerPage, users.length)} de ${users.length} registros
       </td>
   `;
   tbody.appendChild(infoRow);
}

function showTableError(message) {
   const tbody = document.getElementById('resultados-body');
   tbody.innerHTML = `
       <tr>
           <td colspan="3" class="error-message text-center">
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