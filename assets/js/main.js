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
  fields: ['id', 'plate', 'brand', 'model', 'telematics_enabled', 'telematics_provider', 'telematics_device_id', 'year', 'inspection_date', 'insurance_date', 'maintenance_date', 'maintenance_note'],
  defaults: () => ({}),
});

fillModalForm('rentalModal', {
  createTitle: 'Yeni Kiralama Ekle',
  editTitle: 'Kiralama Düzenle',
  fields: ['id', 'customer_company_id', 'customer_name', 'customer_phone', 'customer_identity_no', 'car_id', 'start_date', 'rental_days', 'end_date', 'departure_km', 'income', 'expense'],
  defaults: () => ({
    start_date: currentDateTimeLocal(),
    customer_company_id: '',
    customer_phone: '0',
  }),
});

fillModalForm('customerCompanyModal', {
  createTitle: 'Musteri Firma Ekle',
  editTitle: 'Musteri Firma Duzenle',
  fields: ['id', 'company_name', 'contact_name', 'phone', 'email', 'tax_office', 'tax_number', 'address', 'notes'],
  defaults: () => ({}),
});

fillModalForm('platformUserModal', {
  createTitle: 'Firma Kullanicisi Ekle',
  editTitle: 'Firma Kullanicisini Duzenle',
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
  createTitle: 'Firma Kullanicisi Ekle',
  editTitle: 'Firma Kullanicisini Duzenle',
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
  });

  const rentalForm = rentalModal.querySelector('form');
  if (rentalForm) {
    rentalForm.addEventListener('submit', () => {
      if (departureKmInput) {
        departureKmInput.value = cleanKm(departureKmInput.value);
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
  createTitle: 'Kisi Ekle',
  editTitle: 'Kisi Duzenle',
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

  if (paymentStatusInput && paymentDueDateInput) {
    paymentStatusInput.addEventListener('change', () => {
      if (paymentStatusInput.value === 'collected') {
        paymentDueDateInput.value = '';
      }
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
