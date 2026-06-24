const roleModal = document.getElementById('roleModal');
if (roleModal) {
  roleModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const form = roleModal.querySelector('form');
    const title = roleModal.querySelector('.modal-title');
    const submitLabel = roleModal.querySelector('[data-submit-label]');
    if (!form || !title || !submitLabel) return;

    const mode = trigger.dataset.mode || 'create';
    const idField = form.elements.namedItem('id');
    const companyIdField = form.elements.namedItem('company_id');
    const nameField = form.elements.namedItem('name');
    const descriptionField = form.elements.namedItem('description');
    const permissionFields = form.querySelectorAll('input[name="permissions[]"]');

    form.reset();
    permissionFields.forEach((field) => {
      field.checked = false;
    });

    if (companyIdField) {
      companyIdField.value = trigger.dataset.company_id || trigger.dataset.companyId || companyIdField.value || '';
    }

    if (mode === 'edit') {
      title.textContent = 'Rol Düzenle';
      submitLabel.textContent = 'Güncelle';

      if (idField) idField.value = trigger.dataset.id || '';
      if (nameField) nameField.value = trigger.dataset.name || '';
      if (descriptionField) descriptionField.value = trigger.dataset.description || '';

      const permissions = String(trigger.dataset.permissions || '')
        .split(',')
        .map((value) => value.trim())
        .filter(Boolean);

      permissionFields.forEach((field) => {
        field.checked = permissions.includes(field.value);
      });
    } else {
      title.textContent = 'Rol Ekle';
      submitLabel.textContent = 'Kaydet';

      if (idField) idField.value = '';
    }
  });
}
