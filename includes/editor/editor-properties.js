// ==================== PANNEAU PROPRIÉTÉS ====================
// Note: Le volet propriétés a été supprimé, cette fonction ne fait plus rien
function renderProperties() {
    const container = document.getElementById('propertiesContent');
    if (!container) return; // Volet supprimé
    
    if (selectedActivity) {
        const section = courseData.sections.find(s => s.id === selectedSection);
        const activity = section?.activities.find(a => a.id === selectedActivity);
        if (!activity) return;
        
        container.innerHTML = `
            <div class="property-group">
                <label class="property-label">Nom de l'activité</label>
                <input type="text" class="property-input" value="${escapeHtml(activity.name)}" 
                       onchange="updateActivityProperty('name', this.value)">
            </div>
            <div class="property-group">
                <label class="property-label">Type</label>
                <input type="text" class="property-input" value="${activity.h5pType}" disabled>
            </div>`;
            
    } else if (selectedSection) {
        const section = courseData.sections.find(s => s.id === selectedSection);
        if (!section) return;
        
        container.innerHTML = `
            <div class="property-group">
                <label class="property-label">Nom de la section</label>
                <input type="text" class="property-input" value="${escapeHtml(section.name)}" 
                       onchange="updateSectionProperty('name', this.value)">
            </div>
            <div class="property-group">
                <label class="property-label">Description</label>
                <textarea class="property-input property-textarea" 
                          onchange="updateSectionProperty('summary', this.value)">${escapeHtml(section.summary || '')}</textarea>
            </div>`;
            
    } else {
        container.innerHTML = `
            <div class="empty-properties">
                <div class="empty-properties-icon">⚙️</div>
                <p>Sélectionnez un élément pour voir ses propriétés</p>
            </div>`;
    }
}

function updateSectionProperty(prop, value) {
    const section = courseData.sections.find(s => s.id === selectedSection);
    if (section) {
        section[prop] = value;
        renderTree();
        showStructureView();
        onCourseModified();
    }
}

function updateActivityProperty(prop, value) {
    const section = courseData.sections.find(s => s.id === selectedSection);
    const activity = section?.activities.find(a => a.id === selectedActivity);
    if (activity) {
        activity[prop] = value;
        renderTree();
        renderActivityEditor();
        onCourseModified();
    }
}

