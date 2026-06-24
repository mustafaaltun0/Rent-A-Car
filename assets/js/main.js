if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/rentecarWeb/sw.js').catch(() => {});
  });
}

document.querySelectorAll('[data-confirm]').forEach((el) => {
  el.addEventListener('click', (e) => {
    if (!confirm(el.dataset.confirm)) {
      e.preventDefault();
    }
  });
});

function toTitleCase(value) {
  return value
    .toLocaleLowerCase('tr-TR')
    .split(/\s+/)
    .filter(Boolean)
    .map((word) => word.charAt(0).toLocaleUpperCase('tr-TR') + word.slice(1))
    .join(' ');
}

function formatPhone(value) {
  let digits = value.replace(/\D/g, '');
  if (digits.startsWith('90') && digits.length > 10) {
    digits = `0${digits.slice(2)}`;
  }
  if (!digits.startsWith('0') && digits.length > 0) {
    digits = `0${digits}`;
  }
  digits = digits.slice(0, 11);

  const part1 = digits.slice(0, 4);
  const part2 = digits.slice(4, 7);
  const part3 = digits.slice(7, 9);
  const part4 = digits.slice(9, 11);

  return [part1, part2, part3, part4].filter(Boolean).join(' ');
}

function formatKm(value) {
  const digits = String(value || '').replace(/\D/g, '');
  if (!digits) return '';
  return `${Number(digits).toLocaleString('tr-TR')} km`;
}

function cleanKm(value) {
  return String(value || '').replace(/\D/g, '');
}

function currentDateTimeLocal() {
  const now = new Date();
  const offsetMs = now.getTimezoneOffset() * 60000;
  return new Date(now.getTime() - offsetMs).toISOString().slice(0, 16);
}

function fillModalForm(modalId, config) {
  const modal = document.getElementById(modalId);
  if (!modal) return;

  const form = modal.querySelector('form');
  const title = modal.querySelector('.modal-title');
  const submitLabel = modal.querySelector('[data-submit-label]');
  const readTriggerValue = (trigger, name) => {
    if (!trigger) return '';
    const dashedName = name.replace(/_/g, '-');
    return trigger.dataset[name] ?? trigger.getAttribute(`data-${name}`) ?? trigger.getAttribute(`data-${dashedName}`) ?? '';
  };

  modal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const mode = trigger.dataset.mode || 'create';
    const defaults = typeof config.defaults === 'function' ? config.defaults() : (config.defaults || {});
    form.reset();

    Object.entries(defaults).forEach(([name, value]) => {
      const field = form.elements.namedItem(name);
      if (field) {
        if (field.type === 'checkbox') {
          field.checked = !!value && value !== '0';
        } else {
          field.value = value;
        }
      }
    });

    if (mode === 'edit') {
      title.textContent = config.editTitle;
      submitLabel.textContent = 'Güncelle';

      config.fields.forEach((name) => {
        const field = form.elements.namedItem(name);
        if (field) {
          const triggerValue = readTriggerValue(trigger, name);
          if (field.type === 'checkbox') {
            field.checked = triggerValue === '1' || triggerValue === 'true';
          } else {
            field.value = triggerValue;
          }
        }
      });
    } else {
      title.textContent = config.createTitle;
      submitLabel.textContent = 'Kaydet';

      config.fields.forEach((name) => {
        const field = form.elements.namedItem(name);
        if (field && !Object.prototype.hasOwnProperty.call(defaults, name)) {
          if (field.type === 'checkbox') {
            field.checked = false;
          } else {
            field.value = '';
          }
        }
      });

      config.fields.forEach((name) => {
        const field = form.elements.namedItem(name);
        const triggerValue = readTriggerValue(trigger, name);
        if (field && triggerValue !== '') {
          if (field.type === 'checkbox') {
            field.checked = triggerValue === '1' || triggerValue === 'true';
          } else {
            field.value = triggerValue;
          }
        }
      });
    }
  });
}

fillModalForm('carModal', {
  createTitle: 'Yeni Araç Ekle',
  editTitle: 'Araç Düzenle',
  fields: ['id', 'plate', 'brand', 'model', 'telematics_enabled', 'telematics_provider', 'telematics_device_id', 'year', 'inspection_date', 'insurance_date', 'maintenance_date', 'maintenance_note', 'photo_focus_x', 'photo_focus_y'],
  defaults: () => ({
    photo_focus_x: '50',
    photo_focus_y: '50',
  }),
});

const carModal = document.getElementById('carModal');
if (carModal) {
  const carForm = carModal.querySelector('form');
  const photoFileInput = carForm?.querySelector('[name="photo_file"]');
  const photoFocusXInput = carForm?.querySelector('[name="photo_focus_x"]');
  const photoFocusYInput = carForm?.querySelector('[name="photo_focus_y"]');
  const photoPreview = carForm?.querySelector('[data-car-photo-preview]');
  const photoEmpty = carForm?.querySelector('[data-car-photo-empty]');
  const photoFocusXLabel = carForm?.querySelector('[data-photo-focus-x-label]');
  const photoFocusYLabel = carForm?.querySelector('[data-photo-focus-y-label]');
  const photoDragSurface = carForm?.querySelector('[data-car-photo-drag-surface]');
  const photoResetButton = carForm?.querySelector('[data-car-photo-reset]');
  let photoPreviewObjectUrl = null;
  let activePointerId = null;

  const clampPhotoFocus = (value) => {
    const numeric = Number.parseInt(String(value || '50'), 10);
    if (!Number.isFinite(numeric)) return 50;
    return Math.max(0, Math.min(100, numeric));
  };

  const setPhotoFocus = (focusX, focusY) => {
    if (!photoFocusXInput || !photoFocusYInput) return;
    photoFocusXInput.value = String(clampPhotoFocus(focusX));
    photoFocusYInput.value = String(clampPhotoFocus(focusY));
    applyPhotoPreviewPosition();
  };

  const applyPhotoPreviewPosition = () => {
    if (!photoPreview || !photoFocusXInput || !photoFocusYInput) return;
    const focusX = clampPhotoFocus(photoFocusXInput.value);
    const focusY = clampPhotoFocus(photoFocusYInput.value);
    photoPreview.style.objectPosition = `${focusX}% ${focusY}%`;
    if (photoFocusXLabel) photoFocusXLabel.textContent = `${focusX}%`;
    if (photoFocusYLabel) photoFocusYLabel.textContent = `${focusY}%`;
  };

  const setPhotoPreviewSource = (src) => {
    if (!photoPreview || !photoEmpty) return;

    const hasSource = typeof src === 'string' && src.trim() !== '';
    if (hasSource) {
      photoPreview.src = src;
      photoPreview.hidden = false;
      photoEmpty.hidden = true;
    } else {
      photoPreview.hidden = true;
      photoPreview.removeAttribute('src');
      photoEmpty.hidden = false;
    }

    applyPhotoPreviewPosition();
  };

  const syncFocusFromPointer = (clientX, clientY) => {
    if (!photoDragSurface || !photoPreview || photoPreview.hidden) return;
    const rect = photoDragSurface.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return;

    const focusX = ((clientX - rect.left) / rect.width) * 100;
    const focusY = ((clientY - rect.top) / rect.height) * 100;
    setPhotoFocus(focusX, focusY);
  };

  const resetPhotoPreviewObjectUrl = () => {
    if (photoPreviewObjectUrl) {
      URL.revokeObjectURL(photoPreviewObjectUrl);
      photoPreviewObjectUrl = null;
    }
  };

  if (photoFileInput) {
    photoFileInput.addEventListener('change', () => {
      resetPhotoPreviewObjectUrl();
      const file = photoFileInput.files && photoFileInput.files[0] ? photoFileInput.files[0] : null;
      if (!file) {
        return;
      }

      photoPreviewObjectUrl = URL.createObjectURL(file);
      setPhotoPreviewSource(photoPreviewObjectUrl);
    });
  }

  [photoFocusXInput, photoFocusYInput].forEach((input) => {
    if (!input) return;
    input.addEventListener('input', applyPhotoPreviewPosition);
    input.addEventListener('change', applyPhotoPreviewPosition);
  });

  if (photoResetButton) {
    photoResetButton.addEventListener('click', () => {
      setPhotoFocus(50, 50);
    });
  }

  if (photoDragSurface) {
    photoDragSurface.addEventListener('pointerdown', (event) => {
      if (!photoPreview || photoPreview.hidden) return;
      activePointerId = event.pointerId;
      photoDragSurface.classList.add('is-dragging');
      photoDragSurface.setPointerCapture?.(event.pointerId);
      syncFocusFromPointer(event.clientX, event.clientY);
      event.preventDefault();
    });

    photoDragSurface.addEventListener('pointermove', (event) => {
      if (activePointerId !== event.pointerId) return;
      syncFocusFromPointer(event.clientX, event.clientY);
      event.preventDefault();
    });

    const stopDragging = (event) => {
      if (activePointerId !== event.pointerId) return;
      activePointerId = null;
      photoDragSurface.classList.remove('is-dragging');
      photoDragSurface.releasePointerCapture?.(event.pointerId);
    };

    photoDragSurface.addEventListener('pointerup', stopDragging);
    photoDragSurface.addEventListener('pointercancel', stopDragging);
    photoDragSurface.addEventListener('lostpointercapture', () => {
      activePointerId = null;
      photoDragSurface.classList.remove('is-dragging');
    });
  }

  carModal.addEventListener('show.bs.modal', (event) => {
    resetPhotoPreviewObjectUrl();
    const trigger = event.relatedTarget;
    const photoUrl = trigger?.getAttribute('data-photo_url') || trigger?.dataset.photoUrl || '';

    window.requestAnimationFrame(() => {
      if (photoFocusXInput) {
        photoFocusXInput.value = String(clampPhotoFocus(photoFocusXInput.value));
      }
      if (photoFocusYInput) {
        photoFocusYInput.value = String(clampPhotoFocus(photoFocusYInput.value));
      }

      setPhotoPreviewSource(photoUrl);
    });
  });

  carModal.addEventListener('hidden.bs.modal', () => {
    resetPhotoPreviewObjectUrl();
    activePointerId = null;
    photoDragSurface?.classList.remove('is-dragging');
    setPhotoPreviewSource('');
  });
}

const avatarEditorForm = document.querySelector('[data-avatar-editor]');
if (avatarEditorForm) {
  const avatarFileInput = avatarEditorForm.querySelector('[name="avatar_file"]');
  const avatarFocusXInput = avatarEditorForm.querySelector('[name="avatar_focus_x"]');
  const avatarFocusYInput = avatarEditorForm.querySelector('[name="avatar_focus_y"]');
  const avatarRangeX = avatarEditorForm.querySelector('[data-avatar-focus-x]');
  const avatarRangeY = avatarEditorForm.querySelector('[data-avatar-focus-y]');
  const avatarPreview = avatarEditorForm.querySelector('[data-avatar-preview]');
  const avatarEmpty = avatarEditorForm.querySelector('[data-avatar-empty]');
  const avatarCardPreview = avatarEditorForm.querySelector('[data-avatar-card-preview]');
  const avatarCardPlaceholder = avatarEditorForm.querySelector('[data-avatar-card-placeholder]');
  const avatarFocusXLabel = avatarEditorForm.querySelector('[data-avatar-focus-x-label]');
  const avatarFocusYLabel = avatarEditorForm.querySelector('[data-avatar-focus-y-label]');
  const avatarDragSurface = avatarEditorForm.querySelector('[data-avatar-drag-surface]');
  const avatarResetButton = avatarEditorForm.querySelector('[data-avatar-reset]');
  const avatarControls = avatarEditorForm.querySelector('[data-avatar-controls]');
  const avatarOpenPickerButton = avatarEditorForm.querySelector('[data-avatar-open-picker]');
  const removeAvatarCheck = avatarEditorForm.querySelector('[name="remove_avatar"]');
  let avatarPreviewObjectUrl = null;
  let activeAvatarPointerId = null;
  let avatarAdjustMode = false;
  const initialAvatarSrc = avatarPreview && !avatarPreview.hidden ? avatarPreview.getAttribute('src') || '' : '';
  const initialAvatarCardSrc = avatarCardPreview && !avatarCardPreview.classList.contains('d-none') ? avatarCardPreview.getAttribute('src') || '' : '';

  const clampAvatarFocus = (value) => {
    const numeric = Number.parseInt(String(value || '50'), 10);
    if (!Number.isFinite(numeric)) return 50;
    return Math.max(0, Math.min(100, numeric));
  };

  const applyAvatarPreviewPosition = () => {
    if (!avatarPreview || !avatarFocusXInput || !avatarFocusYInput) return;
    const focusX = clampAvatarFocus(avatarFocusXInput.value);
    const focusY = clampAvatarFocus(avatarFocusYInput.value);
    avatarPreview.style.objectPosition = `${focusX}% ${focusY}%`;
    if (avatarCardPreview && !avatarCardPreview.classList.contains('d-none')) {
      avatarCardPreview.style.objectPosition = `${focusX}% ${focusY}%`;
    }
    if (avatarRangeX) avatarRangeX.value = String(focusX);
    if (avatarRangeY) avatarRangeY.value = String(focusY);
    if (avatarFocusXInput) avatarFocusXInput.value = String(focusX);
    if (avatarFocusYInput) avatarFocusYInput.value = String(focusY);
    if (avatarFocusXLabel) avatarFocusXLabel.textContent = `${focusX}%`;
    if (avatarFocusYLabel) avatarFocusYLabel.textContent = `${focusY}%`;
  };

  const setAvatarFocus = (focusX, focusY) => {
    if (avatarFocusXInput) avatarFocusXInput.value = String(clampAvatarFocus(focusX));
    if (avatarFocusYInput) avatarFocusYInput.value = String(clampAvatarFocus(focusY));
    applyAvatarPreviewPosition();
  };

  const setAvatarAdjustMode = (enabled) => {
    avatarAdjustMode = enabled;
    if (avatarControls) {
      avatarControls.classList.toggle('d-none', !enabled);
    }
    if (avatarDragSurface) {
      avatarDragSurface.classList.toggle('is-adjustable', enabled);
    }
  };

  const setAvatarPreviewSource = (src) => {
    if (!avatarPreview || !avatarEmpty || !avatarDragSurface) return;
    const hasSource = typeof src === 'string' && src.trim() !== '';
    if (hasSource) {
      avatarPreview.src = src;
      avatarPreview.hidden = false;
      avatarEmpty.hidden = true;
      avatarDragSurface.classList.add('has-image');
    } else {
      avatarPreview.hidden = true;
      avatarPreview.removeAttribute('src');
      avatarEmpty.hidden = false;
      avatarDragSurface.classList.remove('has-image');
    }

    applyAvatarPreviewPosition();
  };

  const setAvatarCardSource = (src) => {
    if (!avatarCardPreview || !avatarCardPlaceholder) return;

    const hasSource = typeof src === 'string' && src.trim() !== '';
    if (hasSource) {
      avatarCardPreview.src = src;
      avatarCardPreview.classList.remove('d-none');
      avatarCardPlaceholder.classList.add('d-none');
      avatarCardPreview.style.objectPosition = avatarPreview?.style.objectPosition || '50% 50%';
    } else {
      avatarCardPreview.removeAttribute('src');
      avatarCardPreview.classList.add('d-none');
      avatarCardPlaceholder.classList.remove('d-none');
    }
  };

  const resetAvatarPreviewObjectUrl = () => {
    if (avatarPreviewObjectUrl) {
      URL.revokeObjectURL(avatarPreviewObjectUrl);
      avatarPreviewObjectUrl = null;
    }
  };

  const syncAvatarFocusFromPointer = (clientX, clientY) => {
    if (!avatarAdjustMode || !avatarDragSurface || !avatarPreview || avatarPreview.hidden) return;
    const rect = avatarDragSurface.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) return;

    const focusX = ((clientX - rect.left) / rect.width) * 100;
    const focusY = ((clientY - rect.top) / rect.height) * 100;
    setAvatarFocus(focusX, focusY);
  };

  if (avatarFileInput) {
    if (avatarOpenPickerButton) {
      avatarOpenPickerButton.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        avatarFileInput.value = '';

        if (typeof avatarFileInput.showPicker === 'function') {
          avatarFileInput.showPicker();
          return;
        }

        avatarFileInput.click();
      });
    }

    avatarFileInput.addEventListener('change', () => {
      resetAvatarPreviewObjectUrl();
      const file = avatarFileInput.files && avatarFileInput.files[0] ? avatarFileInput.files[0] : null;
      if (!file) {
        setAvatarAdjustMode(false);
        if (!removeAvatarCheck?.checked && initialAvatarSrc) {
          setAvatarPreviewSource(initialAvatarSrc);
          setAvatarCardSource(initialAvatarCardSrc);
        }
        return;
      }

      if (removeAvatarCheck) removeAvatarCheck.checked = false;
      avatarPreviewObjectUrl = URL.createObjectURL(file);
      setAvatarPreviewSource(avatarPreviewObjectUrl);
      setAvatarCardSource(avatarPreviewObjectUrl);
      setAvatarAdjustMode(true);
    });
  }

  [avatarRangeX, avatarRangeY].forEach((input) => {
    if (!input) return;
    input.addEventListener('input', () => {
      setAvatarFocus(avatarRangeX?.value || 50, avatarRangeY?.value || 50);
    });
    input.addEventListener('change', () => {
      setAvatarFocus(avatarRangeX?.value || 50, avatarRangeY?.value || 50);
    });
  });

  if (avatarResetButton) {
    avatarResetButton.addEventListener('click', () => {
      setAvatarFocus(50, 50);
    });
  }

  if (removeAvatarCheck) {
    removeAvatarCheck.addEventListener('change', () => {
      if (removeAvatarCheck.checked) {
        resetAvatarPreviewObjectUrl();
        if (avatarFileInput) avatarFileInput.value = '';
        setAvatarPreviewSource('');
        setAvatarCardSource('');
        setAvatarAdjustMode(false);
      } else if (avatarFileInput?.files && avatarFileInput.files[0]) {
        avatarPreviewObjectUrl = URL.createObjectURL(avatarFileInput.files[0]);
        setAvatarPreviewSource(avatarPreviewObjectUrl);
        setAvatarCardSource(avatarPreviewObjectUrl);
        setAvatarAdjustMode(true);
      } else if (initialAvatarSrc) {
        setAvatarPreviewSource(initialAvatarSrc);
        setAvatarCardSource(initialAvatarCardSrc);
        setAvatarAdjustMode(false);
      }
    });
  }

  if (avatarDragSurface) {
    avatarDragSurface.addEventListener('pointerdown', (event) => {
      if (!avatarAdjustMode || !avatarPreview || avatarPreview.hidden) return;
      activeAvatarPointerId = event.pointerId;
      avatarDragSurface.classList.add('is-dragging');
      avatarDragSurface.setPointerCapture?.(event.pointerId);
      syncAvatarFocusFromPointer(event.clientX, event.clientY);
      event.preventDefault();
    });

    avatarDragSurface.addEventListener('pointermove', (event) => {
      if (activeAvatarPointerId !== event.pointerId) return;
      syncAvatarFocusFromPointer(event.clientX, event.clientY);
      event.preventDefault();
    });

    const stopAvatarDragging = (event) => {
      if (activeAvatarPointerId !== event.pointerId) return;
      activeAvatarPointerId = null;
      avatarDragSurface.classList.remove('is-dragging');
      avatarDragSurface.releasePointerCapture?.(event.pointerId);
    };

    avatarDragSurface.addEventListener('pointerup', stopAvatarDragging);
    avatarDragSurface.addEventListener('pointercancel', stopAvatarDragging);
    avatarDragSurface.addEventListener('lostpointercapture', () => {
      activeAvatarPointerId = null;
      avatarDragSurface.classList.remove('is-dragging');
    });
  }

  applyAvatarPreviewPosition();
  setAvatarCardSource(initialAvatarCardSrc);
  setAvatarAdjustMode(false);
}

fillModalForm('rentalModal', {
  createTitle: 'Yeni Kiralama Ekle',
  editTitle: 'Kiralama Düzenle',
  fields: ['id', 'customer_company_id', 'customer_name', 'customer_phone', 'customer_identity_no', 'car_id', 'start_date', 'rental_days', 'end_date', 'departure_km', 'income', 'collected_amount', 'payment_due_date', 'expense'],
  defaults: () => ({
    start_date: currentDateTimeLocal(),
    customer_company_id: '',
    customer_phone: '0',
  }),
});

fillModalForm('customerCompanyModal', {
  createTitle: 'Müşteri Firma Ekle',
  editTitle: 'Müşteri Firma Düzenle',
  fields: ['id', 'company_name', 'contact_name', 'phone', 'email', 'tax_office', 'tax_number', 'address', 'notes'],
  defaults: () => ({}),
});

fillModalForm('platformUserModal', {
  createTitle: 'Firma Kullanıcısı Ekle',
  editTitle: 'Firma Kullanıcısını Düzenle',
  fields: ['id', 'company_id', 'company_label', 'full_name', 'username', 'role'],
  defaults: () => ({}),
});

const platformUserModal = document.getElementById('platformUserModal');
if (platformUserModal) {
  platformUserModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const form = platformUserModal.querySelector('form');
    if (!form) return;

    const companyIdField = form.elements.namedItem('company_id');
    const companyLabelField = form.elements.namedItem('company_label');
    const companyId = trigger.getAttribute('data-company-id') || trigger.dataset.companyId || '';
    const companyLabel = trigger.getAttribute('data-company-label') || trigger.dataset.companyLabel || '';

    if (companyIdField) companyIdField.value = companyId;
    if (companyLabelField) companyLabelField.value = companyLabel;
  });
}

fillModalForm('managedCompanyUserModal', {
  createTitle: 'Firma Kullanıcısı Ekle',
  editTitle: 'Firma Kullanıcısını Düzenle',
  fields: ['id', 'company_id', 'company_label', 'full_name', 'username', 'role'],
  defaults: () => ({}),
});

const managedCompanyUserModal = document.getElementById('managedCompanyUserModal');
if (managedCompanyUserModal) {
  managedCompanyUserModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const form = managedCompanyUserModal.querySelector('form');
    if (!form) return;

    const companyIdField = form.elements.namedItem('company_id');
    const companyLabelField = form.elements.namedItem('company_label');
    const companyId = trigger.getAttribute('data-company-id') || trigger.dataset.companyId || '';
    const companyLabel = trigger.getAttribute('data-company-label') || trigger.dataset.companyLabel || '';

    if (companyIdField) companyIdField.value = companyId;
    if (companyLabelField) companyLabelField.value = companyLabel;
  });
}

const rentalModal = document.getElementById('rentalModal');
if (rentalModal) {
  const nameInput = rentalModal.querySelector('[name="customer_name"]');
  const phoneInput = rentalModal.querySelector('[name="customer_phone"]');
  const identityInput = rentalModal.querySelector('[name="customer_identity_no"]');
  const departureKmInput = rentalModal.querySelector('[name="departure_km"]');
  const startDateInput = rentalModal.querySelector('[name="start_date"]');
  const rentalDaysInput = rentalModal.querySelector('[name="rental_days"]');
  const endDateInput = rentalModal.querySelector('[name="end_date"]');
  const incomeInput = rentalModal.querySelector('[name="income"]');
  const collectedAmountInput = rentalModal.querySelector('[name="collected_amount"]');
  const remainingAmountPreviewInput = rentalModal.querySelector('[name="remaining_amount_preview"]');
  const paymentDueDateInput = rentalModal.querySelector('[name="payment_due_date"]');

  const syncEndDateFromDays = () => {
    if (!startDateInput || !rentalDaysInput || !endDateInput) return;

    const startValue = startDateInput.value;
    const daysValue = parseInt(rentalDaysInput.value || '', 10);
    if (!startValue || !Number.isFinite(daysValue) || daysValue <= 0) {
      return;
    }

    const endDate = new Date(startValue);
    if (Number.isNaN(endDate.getTime())) {
      return;
    }

    endDate.setDate(endDate.getDate() + daysValue);
    const offsetMs = endDate.getTimezoneOffset() * 60000;
    endDateInput.value = new Date(endDate.getTime() - offsetMs).toISOString().slice(0, 16);
  };

  const formatCurrencyPreview = (value) => {
    const safeValue = Number.isFinite(value) ? value : 0;
    return `${safeValue.toLocaleString('tr-TR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })} TL`;
  };

  const syncRentalCollectionPreview = () => {
    if (!incomeInput || !remainingAmountPreviewInput) return;

    const totalIncome = Number.parseFloat(incomeInput.value || '0');
    const safeTotalIncome = Number.isFinite(totalIncome) ? Math.max(0, totalIncome) : 0;
    const rawCollected = collectedAmountInput ? collectedAmountInput.value.trim() : '';
    const collectedAmount = rawCollected === '' ? safeTotalIncome : Number.parseFloat(rawCollected || '0');
    const safeCollectedAmount = Number.isFinite(collectedAmount) ? Math.max(0, Math.min(safeTotalIncome, collectedAmount)) : safeTotalIncome;
    const remainingAmount = Math.max(0, safeTotalIncome - safeCollectedAmount);

    remainingAmountPreviewInput.value = formatCurrencyPreview(remainingAmount);

    if (paymentDueDateInput && remainingAmount <= 0) {
      paymentDueDateInput.value = '';
    }
  };

  if (nameInput) {
    nameInput.addEventListener('blur', () => {
      nameInput.value = toTitleCase(nameInput.value);
    });
  }

  if (phoneInput) {
    phoneInput.addEventListener('input', () => {
      phoneInput.value = formatPhone(phoneInput.value);
    });
  }

  if (identityInput) {
    identityInput.addEventListener('input', () => {
      identityInput.value = identityInput.value.replace(/\D/g, '').slice(0, 11);
    });
    identityInput.addEventListener('paste', () => {
      requestAnimationFrame(() => {
        identityInput.value = identityInput.value.replace(/\D/g, '').slice(0, 11);
      });
    });
  }

  if (departureKmInput) {
    departureKmInput.addEventListener('input', () => {
      departureKmInput.value = formatKm(departureKmInput.value);
    });
  }

  if (startDateInput) {
    startDateInput.addEventListener('change', syncEndDateFromDays);
  }

  if (rentalDaysInput) {
    rentalDaysInput.addEventListener('input', syncEndDateFromDays);
  }

  if (incomeInput) {
    incomeInput.addEventListener('input', syncRentalCollectionPreview);
  }

  if (collectedAmountInput) {
    collectedAmountInput.addEventListener('input', syncRentalCollectionPreview);
  }

  rentalModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const mode = trigger.dataset.mode || 'create';
    const carSelect = rentalModal.querySelector('[name="car_id"]');
    const customerCompanySelect = rentalModal.querySelector('[name="customer_company_id"]');
    if (!carSelect) return;

    Array.from(carSelect.options).forEach((option) => {
      option.disabled = option.dataset.busy === '1';
    });

    if (customerCompanySelect) {
      Array.from(customerCompanySelect.options).forEach((option) => {
        option.disabled = option.dataset.inactive === '1';
      });
    }

    if (mode === 'edit') {
      const selectedCarId = trigger.getAttribute('data-car_id') || trigger.getAttribute('data-car-id') || trigger.dataset.car_id || trigger.dataset.carId || ''; 
      const selectedOption = Array.from(carSelect.options).find((option) => option.value === selectedCarId);
      if (selectedOption) {
        selectedOption.disabled = false;
      }
      if (customerCompanySelect) {
        const selectedCustomerCompanyId = trigger.getAttribute('data-customer_company_id') || trigger.getAttribute('data-customer-company-id') || trigger.dataset.customer_company_id || trigger.dataset.customerCompanyId || '';
        const selectedCustomerCompanyOption = Array.from(customerCompanySelect.options).find((option) => option.value === selectedCustomerCompanyId);
        if (selectedCustomerCompanyOption) {
          selectedCustomerCompanyOption.disabled = false;
        }
      }
      if (rentalDaysInput) {
        rentalDaysInput.value = '';
      }
      if (departureKmInput) {
        departureKmInput.value = trigger.getAttribute('data-departure_km') || trigger.getAttribute('data-departure-km') || trigger.dataset.departure_km || trigger.dataset.departureKm || departureKmInput.value || '';
      }
    } else if (rentalDaysInput) {
      rentalDaysInput.value = '';
      syncEndDateFromDays();
    }

    if (nameInput) {
      nameInput.value = toTitleCase(nameInput.value);
    }

    if (phoneInput) {
      phoneInput.value = formatPhone(phoneInput.value);
    }

    if (identityInput) {
      identityInput.value = identityInput.value.replace(/\D/g, '').slice(0, 11);
    }

    if (departureKmInput) {
      departureKmInput.value = formatKm(departureKmInput.value);
    }

    syncRentalCollectionPreview();
  });

  const rentalForm = rentalModal.querySelector('form');
  if (rentalForm) {
    rentalForm.addEventListener('submit', () => {
      if (departureKmInput) {
        departureKmInput.value = cleanKm(departureKmInput.value);
      }
      if (collectedAmountInput && incomeInput) {
        const totalIncome = Number.parseFloat(incomeInput.value || '0');
        const safeTotalIncome = Number.isFinite(totalIncome) ? Math.max(0, totalIncome) : 0;
        const rawCollected = collectedAmountInput.value.trim();
        if (rawCollected !== '') {
          const collectedAmount = Number.parseFloat(rawCollected || '0');
          const safeCollectedAmount = Number.isFinite(collectedAmount) ? Math.max(0, Math.min(safeTotalIncome, collectedAmount)) : safeTotalIncome;
          collectedAmountInput.value = String(safeCollectedAmount);
        }
      }
    });
  }
}

document.querySelectorAll('[data-complete-trigger]').forEach((button) => {
  button.addEventListener('click', () => {
    const form = button.closest('[data-complete-form]');
    if (!form) return;

    const departureKm = parseInt(button.dataset.departureKm || '', 10);
    const promptText = Number.isFinite(departureKm)
      ? `Dönüş KM girin. Çıkış KM: ${departureKm}`
      : 'Dönüş KM girin.';
    const result = window.prompt(promptText, '');
    if (result === null) {
      return;
    }

    const cleanedValue = result.replace(/\D/g, '');
    if (!cleanedValue) {
      window.alert('Lütfen geçerli bir dönüş KM girin.');
      return;
    }

    if (Number.isFinite(departureKm) && parseInt(cleanedValue, 10) < departureKm) {
      window.alert('Dönüş KM, çıkış KM değerinden küçük olamaz.');
      return;
    }

    const hiddenInput = form.querySelector('[name="return_km"]');
    if (!hiddenInput) return;
    hiddenInput.value = cleanedValue;
    form.submit();
  });
});

fillModalForm('expenseModal', {
  createTitle: 'Yeni Gider Ekle',
  editTitle: 'Gider Düzenle',
  fields: ['id', 'title', 'amount', 'expense_date'],
  defaults: () => ({
    expense_date: new Date().toISOString().slice(0, 10),
  }),
});

fillModalForm('businessPartnerModal', {
  createTitle: 'Kişi Ekle',
  editTitle: 'Kişi Düzenle',
  fields: ['id', 'name', 'is_settlement_partner', 'sort_order'],
  defaults: () => ({
    is_settlement_partner: '1',
    sort_order: '0',
  }),
});

fillModalForm('businessEntryModal', {
  createTitle: 'Hareket Ekle',
  editTitle: 'Hareket Düzenle',
  fields: ['id', 'period_id', 'type', 'partner_id', 'car_label', 'amount', 'note', 'entry_date'],
  defaults: () => ({
    period_id: document.querySelector('#businessEntryModal [name="period_id"]')?.value || '',
    type: 'income',
    entry_date: currentDateTimeLocal(),
  }),
});

const businessEntryModal = document.getElementById('businessEntryModal');
if (businessEntryModal) {
  businessEntryModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    const typeField = businessEntryModal.querySelector('[name="type"]');
    if (!trigger || !typeField) return;

    if ((trigger.dataset.mode || 'create') === 'create' && trigger.dataset.type) {
      typeField.value = trigger.dataset.type;
    }
  });
}

const extendRentalModal = document.getElementById('extendRentalModal');
if (extendRentalModal) {
  const currentEndDateInput = extendRentalModal.querySelector('[name="current_end_date_preview"]');
  const extensionDaysInput = extendRentalModal.querySelector('[name="extension_days"]');
  const newEndDateInput = extendRentalModal.querySelector('[name="new_end_date"]');
  const paymentStatusInput = extendRentalModal.querySelector('[name="payment_status"]');
  const paymentDueDateInput = extendRentalModal.querySelector('[name="payment_due_date"]');
  const initialCollectedAmountInput = extendRentalModal.querySelector('[name="initial_collected_amount"]');
  const additionalIncomeInput = extendRentalModal.querySelector('[name="additional_income"]');
  const remainingAmountPreviewInput = extendRentalModal.querySelector('[name="remaining_amount_preview"]');
  const customCollectionPlanInput = extendRentalModal.querySelector('[name="custom_collection_plan"]');
  const collectionPlanWrapper = extendRentalModal.querySelector('[data-extension-collection-plan]');
  const dueDateWrapper = extendRentalModal.querySelector('[data-extension-due-date-wrapper]');

  const formatCurrencyPreview = (value) => {
    const safeValue = Number.isFinite(value) ? value : 0;
    return `${safeValue.toLocaleString('tr-TR', {
      minimumFractionDigits: safeValue % 1 === 0 ? 0 : 2,
      maximumFractionDigits: 2,
    })} TL`;
  };

  const syncExtensionPaymentState = () => {
    const hasCustomCollectionPlan = !!customCollectionPlanInput?.checked;
    const totalIncome = parseFloat(additionalIncomeInput?.value || '0');
    const rawCollected = parseFloat(initialCollectedAmountInput?.value || (hasCustomCollectionPlan ? '0' : String(totalIncome || 0)));

    const safeTotalIncome = Number.isFinite(totalIncome) && totalIncome > 0 ? totalIncome : 0;
    let safeCollected = Number.isFinite(rawCollected) && rawCollected > 0 ? rawCollected : 0;

    if (!hasCustomCollectionPlan) {
      safeCollected = safeTotalIncome;
    }

    if (safeCollected > safeTotalIncome && safeTotalIncome > 0) {
      safeCollected = safeTotalIncome;
      if (initialCollectedAmountInput) {
        initialCollectedAmountInput.value = String(safeCollected);
      }
    }

    const remainingAmount = Math.max(0, safeTotalIncome - safeCollected);
    if (remainingAmountPreviewInput) {
      remainingAmountPreviewInput.value = formatCurrencyPreview(remainingAmount);
    }

    if (collectionPlanWrapper) {
      collectionPlanWrapper.classList.toggle('d-none', !hasCustomCollectionPlan);
    }

    if (dueDateWrapper) {
      dueDateWrapper.classList.toggle('d-none', !(hasCustomCollectionPlan && remainingAmount > 0));
    }

    if (paymentStatusInput) {
      paymentStatusInput.value = remainingAmount <= 0 ? 'collected' : 'pending';
    }

    if (paymentDueDateInput) {
      if (!hasCustomCollectionPlan || remainingAmount <= 0) {
        paymentDueDateInput.value = '';
      } else if (!paymentDueDateInput.value) {
        paymentDueDateInput.value = currentDateTimeLocal();
      }
    }
  };

  const syncExtensionEndDate = () => {
    if (!currentEndDateInput || !extensionDaysInput || !newEndDateInput) return;

    const currentEndValue = currentEndDateInput.value;
    const daysValue = parseInt(extensionDaysInput.value || '', 10);
    if (!currentEndValue || !Number.isFinite(daysValue) || daysValue <= 0) {
      return;
    }

    const endDate = new Date(currentEndValue);
    if (Number.isNaN(endDate.getTime())) {
      return;
    }

    endDate.setDate(endDate.getDate() + daysValue);
    const offsetMs = endDate.getTimezoneOffset() * 60000;
    newEndDateInput.value = new Date(endDate.getTime() - offsetMs).toISOString().slice(0, 16);
  };

  if (extensionDaysInput) {
    extensionDaysInput.addEventListener('input', syncExtensionEndDate);
  }

  if (additionalIncomeInput) {
    additionalIncomeInput.addEventListener('input', syncExtensionPaymentState);
  }

  if (initialCollectedAmountInput) {
    initialCollectedAmountInput.addEventListener('input', syncExtensionPaymentState);
  }

  if (customCollectionPlanInput) {
    customCollectionPlanInput.addEventListener('change', () => {
      if (!customCollectionPlanInput.checked && initialCollectedAmountInput) {
        initialCollectedAmountInput.value = '';
      }
      syncExtensionPaymentState();
    });
  }

  if (paymentStatusInput && paymentDueDateInput) {
    paymentStatusInput.addEventListener('change', () => {
      if (paymentStatusInput.value === 'collected') {
        paymentDueDateInput.value = '';
      }
    });
  }

  extendRentalModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    extendRentalModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    extendRentalModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    extendRentalModal.querySelector('[name="current_end_date_preview"]').value = trigger.dataset.current_end_date || '';
    extendRentalModal.querySelector('[name="new_end_date"]').value = trigger.dataset.current_end_date || '';
    extendRentalModal.querySelector('[name="extension_days"]').value = '';
    extendRentalModal.querySelector('[name="additional_income"]').value = '';
    if (customCollectionPlanInput) customCollectionPlanInput.checked = false;
    if (initialCollectedAmountInput) initialCollectedAmountInput.value = '';
    if (remainingAmountPreviewInput) remainingAmountPreviewInput.value = '0 TL';
    extendRentalModal.querySelector('[name="additional_expense"]').value = '0';
    extendRentalModal.querySelector('[name="payment_status"]').value = 'collected';
    extendRentalModal.querySelector('[name="payment_due_date"]').value = '';
    extendRentalModal.querySelector('[name="note"]').value = '';
    syncExtensionPaymentState();
  });
}

const editRentalExtensionModal = document.getElementById('editRentalExtensionModal');
if (editRentalExtensionModal) {
  const paymentStatusInput = editRentalExtensionModal.querySelector('[name="payment_status"]');
  const paymentDueDateInput = editRentalExtensionModal.querySelector('[name="payment_due_date"]');
  const pricingModeInput = editRentalExtensionModal.querySelector('[name="pricing_mode"]');
  const newEndDateInput = editRentalExtensionModal.querySelector('[name="new_end_date"]');
  const additionalIncomeInput = editRentalExtensionModal.querySelector('[name="additional_income"]');
  const originalExtensionDaysPreviewInput = editRentalExtensionModal.querySelector('[name="original_extension_days_preview"]');
  const currentExtensionDaysPreviewInput = editRentalExtensionModal.querySelector('[name="current_extension_days_preview"]');
  const dailyRatePreviewInput = editRentalExtensionModal.querySelector('[name="daily_rate_preview"]');
  const suggestedIncomePreviewInput = editRentalExtensionModal.querySelector('[name="suggested_income_preview"]');
  const applySuggestedIncomeButton = editRentalExtensionModal.querySelector('[data-apply-suggested-income]');
  let extensionPricingState = {
    originalPreviousEndDate: '',
    originalNewEndDate: '',
    originalIncome: 0,
    suggestedIncome: 0,
    manualIncomeOverride: false,
  };

  const formatCurrencyText = (value) => {
    const safeValue = Number.isFinite(value) ? value : 0;
    return `${safeValue.toLocaleString('tr-TR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    })} TL`;
  };

  const calculateExtensionDays = (startValue, endValue) => {
    if (!startValue || !endValue) return 0;

    const startDate = new Date(startValue);
    const endDate = new Date(endValue);
    if (Number.isNaN(startDate.getTime()) || Number.isNaN(endDate.getTime())) return 0;

    const diffMs = endDate.getTime() - startDate.getTime();
    if (diffMs <= 0) return 0;

    return Math.ceil(diffMs / 86400000);
  };

  const syncExtensionIncomeSuggestion = () => {
    if (!newEndDateInput || !additionalIncomeInput) return;

    const originalDays = calculateExtensionDays(extensionPricingState.originalPreviousEndDate, extensionPricingState.originalNewEndDate);
    const currentDays = calculateExtensionDays(extensionPricingState.originalPreviousEndDate, newEndDateInput.value);
    const safeOriginalIncome = Number.isFinite(extensionPricingState.originalIncome) ? Math.max(0, extensionPricingState.originalIncome) : 0;
    const dailyRate = originalDays > 0 ? (safeOriginalIncome / originalDays) : 0;
    const suggestedIncome = Math.max(0, Math.round(dailyRate * currentDays * 100) / 100);

    extensionPricingState.suggestedIncome = suggestedIncome;

    if (originalExtensionDaysPreviewInput) {
      originalExtensionDaysPreviewInput.value = originalDays > 0 ? `${originalDays} gun` : '-';
    }
    if (currentExtensionDaysPreviewInput) {
      currentExtensionDaysPreviewInput.value = currentDays > 0 ? `${currentDays} gun` : '-';
    }
    if (dailyRatePreviewInput) {
      dailyRatePreviewInput.value = originalDays > 0 ? formatCurrencyText(dailyRate) : '-';
    }
    if (suggestedIncomePreviewInput) {
      suggestedIncomePreviewInput.value = currentDays > 0 ? formatCurrencyText(suggestedIncome) : '-';
    }

    if (!extensionPricingState.manualIncomeOverride && currentDays > 0) {
      additionalIncomeInput.value = String(suggestedIncome);
    }
  };

  if (paymentStatusInput && paymentDueDateInput) {
    paymentStatusInput.addEventListener('change', () => {
      if (paymentStatusInput.value === 'collected') {
        paymentDueDateInput.value = '';
      }
    });
  }

  if (newEndDateInput) {
    newEndDateInput.addEventListener('change', syncExtensionIncomeSuggestion);
    newEndDateInput.addEventListener('input', syncExtensionIncomeSuggestion);
  }

  if (additionalIncomeInput) {
    additionalIncomeInput.addEventListener('input', () => {
      extensionPricingState.manualIncomeOverride = true;
      if (pricingModeInput) pricingModeInput.value = 'manual';
    });
  }

  if (applySuggestedIncomeButton && additionalIncomeInput) {
    applySuggestedIncomeButton.addEventListener('click', () => {
      extensionPricingState.manualIncomeOverride = false;
      if (pricingModeInput) pricingModeInput.value = 'auto_prorata';
      additionalIncomeInput.value = String(extensionPricingState.suggestedIncome || 0);
      syncExtensionIncomeSuggestion();
    });
  }

  editRentalExtensionModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    editRentalExtensionModal.querySelector('[name="extension_id"]').value = trigger.dataset.extension_id || '';
    editRentalExtensionModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    editRentalExtensionModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    editRentalExtensionModal.querySelector('[name="previous_end_date_preview"]').value = trigger.dataset.previous_end_date || '';
    editRentalExtensionModal.querySelector('[name="new_end_date"]').value = trigger.dataset.new_end_date || '';
    editRentalExtensionModal.querySelector('[name="additional_income"]').value = trigger.dataset.additional_income || '';
    editRentalExtensionModal.querySelector('[name="additional_expense"]').value = trigger.dataset.additional_expense || '0';
    editRentalExtensionModal.querySelector('[name="payment_status"]').value = trigger.dataset.payment_status || 'pending';
    editRentalExtensionModal.querySelector('[name="payment_due_date"]').value = trigger.dataset.payment_due_date || '';
    editRentalExtensionModal.querySelector('[name="note"]').value = trigger.dataset.note || '';
    if (pricingModeInput) pricingModeInput.value = 'auto_prorata';
    extensionPricingState = {
      originalPreviousEndDate: trigger.dataset.original_previous_end_date || trigger.dataset.previous_end_date || '',
      originalNewEndDate: trigger.dataset.original_new_end_date || trigger.dataset.new_end_date || '',
      originalIncome: Number.parseFloat(trigger.dataset.original_income || trigger.dataset.additional_income || '0') || 0,
      suggestedIncome: 0,
      manualIncomeOverride: false,
    };
    syncExtensionIncomeSuggestion();
  });
}

const collectRentalExtensionModal = document.getElementById('collectRentalExtensionModal');
if (collectRentalExtensionModal) {
  collectRentalExtensionModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const previousEndDate = trigger.dataset.previous_end_date || '';
    const newEndDate = trigger.dataset.new_end_date || '';
    const remainingAmount = trigger.dataset.remaining_amount || '';

    collectRentalExtensionModal.querySelector('[name="extension_id"]').value = trigger.dataset.extension_id || '';
    collectRentalExtensionModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    collectRentalExtensionModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    collectRentalExtensionModal.querySelector('[name="extension_period_preview"]').value = previousEndDate && newEndDate ? `${previousEndDate} -> ${newEndDate}` : '';
    collectRentalExtensionModal.querySelector('[name="remaining_amount_preview"]').value = remainingAmount ? `${remainingAmount} TL` : '';
    collectRentalExtensionModal.querySelector('[name="amount"]').value = remainingAmount || '';
    collectRentalExtensionModal.querySelector('[name="payment_method"]').value = '';
    collectRentalExtensionModal.querySelector('[name="note"]').value = '';
  });
}

const cancelRentalExtensionModal = document.getElementById('cancelRentalExtensionModal');
if (cancelRentalExtensionModal) {
  cancelRentalExtensionModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const previousEndDate = trigger.dataset.previous_end_date || '';
    const newEndDate = trigger.dataset.new_end_date || '';

    cancelRentalExtensionModal.querySelector('[name="extension_id"]').value = trigger.dataset.extension_id || '';
    cancelRentalExtensionModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    cancelRentalExtensionModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    cancelRentalExtensionModal.querySelector('[name="extension_period_preview"]').value = previousEndDate && newEndDate ? `${previousEndDate} -> ${newEndDate}` : '';
    cancelRentalExtensionModal.querySelector('[name="cancel_reason_option"]').value = '';
    cancelRentalExtensionModal.querySelector('[name="cancel_reason_detail"]').value = '';
  });
}

const cancelRentalExtensionCollectionModal = document.getElementById('cancelRentalExtensionCollectionModal');
if (cancelRentalExtensionCollectionModal) {
  cancelRentalExtensionCollectionModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    cancelRentalExtensionCollectionModal.querySelector('[name="collection_id"]').value = trigger.dataset.collection_id || '';
    cancelRentalExtensionCollectionModal.querySelector('[name="extension_id"]').value = trigger.dataset.extension_id || '';
    cancelRentalExtensionCollectionModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    cancelRentalExtensionCollectionModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    cancelRentalExtensionCollectionModal.querySelector('[name="collected_at_preview"]').value = trigger.dataset.collected_at || '';
    cancelRentalExtensionCollectionModal.querySelector('[name="collection_amount_preview"]').value = trigger.dataset.collection_amount ? `${trigger.dataset.collection_amount} TL` : '';
    cancelRentalExtensionCollectionModal.querySelector('[name="cancel_reason_option"]').value = '';
    cancelRentalExtensionCollectionModal.querySelector('[name="cancel_reason_detail"]').value = '';
  });
}

const editRentalExtensionCollectionModal = document.getElementById('editRentalExtensionCollectionModal');
if (editRentalExtensionCollectionModal) {
  editRentalExtensionCollectionModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    editRentalExtensionCollectionModal.querySelector('[name="collection_id"]').value = trigger.dataset.collection_id || '';
    editRentalExtensionCollectionModal.querySelector('[name="extension_id"]').value = trigger.dataset.extension_id || '';
    editRentalExtensionCollectionModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    editRentalExtensionCollectionModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    editRentalExtensionCollectionModal.querySelector('[name="collected_at"]').value = trigger.dataset.collected_at || '';
    editRentalExtensionCollectionModal.querySelector('[name="amount"]').value = trigger.dataset.collection_amount || '';
    editRentalExtensionCollectionModal.querySelector('[name="payment_method"]').value = trigger.dataset.payment_method || '';
    editRentalExtensionCollectionModal.querySelector('[name="note"]').value = trigger.dataset.note || '';
    const maxAmountLabel = editRentalExtensionCollectionModal.querySelector('[data-max-collection-amount]');
    if (maxAmountLabel) {
      maxAmountLabel.textContent = trigger.dataset.max_amount ? `${trigger.dataset.max_amount} TL` : '-';
    }
  });
}

const deleteRentalExtensionModal = document.getElementById('deleteRentalExtensionModal');
if (deleteRentalExtensionModal) {
  deleteRentalExtensionModal.addEventListener('show.bs.modal', (event) => {
    const trigger = event.relatedTarget;
    if (!trigger) return;

    const previousEndDate = trigger.dataset.previous_end_date || '';
    const newEndDate = trigger.dataset.new_end_date || '';

    deleteRentalExtensionModal.querySelector('[name="extension_id"]').value = trigger.dataset.extension_id || '';
    deleteRentalExtensionModal.querySelector('[name="rental_id"]').value = trigger.dataset.rental_id || '';
    deleteRentalExtensionModal.querySelector('[name="customer_name_preview"]').value = trigger.dataset.customer_name || '';
    deleteRentalExtensionModal.querySelector('[name="extension_period_preview"]').value = previousEndDate && newEndDate ? `${previousEndDate} -> ${newEndDate}` : '';
    deleteRentalExtensionModal.querySelector('[name="collected_amount_preview"]').value = trigger.dataset.collected_amount ? `${trigger.dataset.collected_amount} TL` : '0 TL';
  });
}






fillModalForm('userModal', {
  createTitle: 'Kullanıcı Ekle',
  editTitle: 'Kullanıcı Düzenle',
  fields: ['id', 'full_name', 'username', 'role', 'password'],
});
