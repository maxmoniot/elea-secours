// ==================== UTILITAIRES ====================
function generateId() {
return 'id_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
}

function showToast(message, type = 'info') {
const container = document.getElementById('toastContainer');
const toast = document.createElement('div');
toast.className = 'toast ' + type;
toast.textContent = message;
container.appendChild(toast);
setTimeout(() => toast.remove(), 3000);
}

function closeModal(modalId) {
    document.getElementById(modalId)?.classList.remove('active');
}

function openModal(modalId) {
    document.getElementById(modalId)?.classList.add('active');
}

function escapeHtml(text) {
const div = document.createElement('div');
div.textContent = text || '';
return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
