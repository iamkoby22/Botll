(function () {
  const hidden = document.getElementById('rlFieldsJson');
  const list = document.getElementById('rlFieldList');
  const addBtn = document.getElementById('rlAddFieldBtn');
  if (!hidden || !list) return;

  const FIELD_TYPES = [
    { id: 'text', label: 'Short text' },
    { id: 'textarea', label: 'Long text / textarea' },
    { id: 'dropdown', label: 'Dropdown / select' },
    { id: 'radio', label: 'Radio' },
    { id: 'checkbox', label: 'Checkbox' },
    { id: 'date', label: 'Date' },
    { id: 'number', label: 'Number' },
    { id: 'email', label: 'Email' },
    { id: 'instruction', label: 'Instruction / info block' },
    { id: 'user_selector', label: 'User selector' },
  ];

  let fields = [];
  try {
    fields = JSON.parse(hidden.value || '[]');
    if (!Array.isArray(fields)) fields = [];
  } catch (_) {
    fields = [];
  }

  function slug(s) {
    return (
      String(s || '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '')
        .slice(0, 72) || 'field'
    );
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function sync() {
    fields.forEach((f, i) => {
      f.display_order = i;
      if (!f.field_key && f.field_label) f.field_key = slug(f.field_label) + '_' + (i + 1);
    });
    hidden.value = JSON.stringify(fields);
  }

  function optionTypes(t) {
    return ['dropdown', 'select', 'radio', 'checkbox'].includes(t);
  }

  function render() {
    list.innerHTML = '';
    if (!fields.length) {
      list.innerHTML =
        '<p class="text-muted small mb-0">No fields yet. Click <strong>Add Field</strong> to build the form.</p>';
      sync();
      return;
    }
    fields.forEach((f, i) => {
      const card = document.createElement('div');
      card.className = 'card border mb-3 rl-field-card';
      const type = f.field_type || 'text';
      const optsVisible = optionTypes(type) ? '' : 'd-none';
      const instrVisible = type === 'instruction' ? '' : 'd-none';
      const stdVisible = type === 'instruction' ? 'd-none' : '';
      card.innerHTML =
        '<div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">' +
        '<span class="fw-semibold small">Field ' +
        (i + 1) +
        '</span>' +
        '<div class="btn-group btn-group-sm">' +
        '<button type="button" class="btn btn-outline-muted rl-up" data-i="' +
        i +
        '" title="Move up"><i class="bi bi-arrow-up"></i></button>' +
        '<button type="button" class="btn btn-outline-muted rl-down" data-i="' +
        i +
        '" title="Move down"><i class="bi bi-arrow-down"></i></button>' +
        '<button type="button" class="btn btn-outline-danger rl-del" data-i="' +
        i +
        '" title="Remove"><i class="bi bi-trash"></i></button>' +
        '</div></div>' +
        '<div class="card-body">' +
        '<input type="hidden" class="rl-id" value="' +
        (f.id ? parseInt(f.id, 10) : 0) +
        '">' +
        '<div class="row g-2">' +
        '<div class="col-md-6 ' +
        stdVisible +
        '"><label class="form-label small">Field label</label><input type="text" class="form-control form-control-sm rl-label" value="' +
        escapeHtml(f.field_label || '') +
        '"></div>' +
        '<div class="col-md-3 ' +
        stdVisible +
        '"><label class="form-label small">Field type</label><select class="form-select form-select-sm rl-type">' +
        FIELD_TYPES.map(
          (t) =>
            '<option value="' +
            t.id +
            '"' +
            (type === t.id ? ' selected' : '') +
            '>' +
            t.label +
            '</option>'
        ).join('') +
        '</select></div>' +
        '<div class="col-md-3 ' +
        stdVisible +
        '"><label class="form-label small">Display order</label><input type="number" class="form-control form-control-sm rl-order" value="' +
        (f.display_order != null ? f.display_order : i) +
        '"></div>' +
        '<div class="col-md-4 ' +
        stdVisible +
        '"><label class="form-label small">Field key</label><input type="text" class="form-control form-control-sm rl-key" value="' +
        escapeHtml(f.field_key || '') +
        '" placeholder="auto from label"></div>' +
        '<div class="col-md-4 ' +
        stdVisible +
        '"><label class="form-label small">Placeholder</label><input type="text" class="form-control form-control-sm rl-placeholder" value="' +
        escapeHtml(f.placeholder || '') +
        '"></div>' +
        '<div class="col-md-4 ' +
        stdVisible +
        '"><div class="form-check mt-4"><input type="checkbox" class="form-check-input rl-required" ' +
        (f.is_required ? 'checked' : '') +
        '><label class="form-check-label small">Required</label></div>' +
        '<div class="form-check"><input type="checkbox" class="form-check-input rl-active" ' +
        (f.is_active !== false ? 'checked' : '') +
        '><label class="form-check-label small">Active</label></div></div>' +
        '<div class="col-12 ' +
        stdVisible +
        '"><label class="form-label small">Help text</label><input type="text" class="form-control form-control-sm rl-help" value="' +
        escapeHtml(f.help_text_display || f.help_text || '') +
        '"></div>' +
        '<div class="col-12 ' +
        optsVisible +
        ' rl-opts-wrap"><label class="form-label small">Options (one per line)</label><textarea class="form-control form-control-sm rl-options" rows="3">' +
        escapeHtml(f.field_options || '') +
        '</textarea></div>' +
        '<div class="col-12 ' +
        instrVisible +
        ' rl-instr-wrap"><label class="form-label small">Instruction title</label><input type="text" class="form-control form-control-sm rl-label-instr" value="' +
        escapeHtml(f.field_label || 'Instruction') +
        '">' +
        '<label class="form-label small mt-2">Instruction text</label><textarea class="form-control form-control-sm rl-instruction" rows="4">' +
        escapeHtml(f.instruction_text || '') +
        '</textarea></div>' +
        '</div></div>';
      list.appendChild(card);

      const read = () => {
        const t = card.querySelector('.rl-type')?.value || 'text';
        f.field_type = t;
        f.id = parseInt(card.querySelector('.rl-id')?.value || '0', 10) || 0;
        if (t === 'instruction') {
          f.field_label = card.querySelector('.rl-label-instr')?.value || 'Instruction';
          f.instruction_text = card.querySelector('.rl-instruction')?.value || '';
          f.is_required = false;
        } else {
          f.field_label = card.querySelector('.rl-label')?.value || '';
          f.field_key = card.querySelector('.rl-key')?.value || slug(f.field_label);
          f.placeholder = card.querySelector('.rl-placeholder')?.value || '';
          f.help_text = card.querySelector('.rl-help')?.value || '';
          f.help_text_display = f.help_text;
          f.field_options = card.querySelector('.rl-options')?.value || '';
          f.is_required = !!card.querySelector('.rl-required')?.checked;
        }
        f.is_active = !!card.querySelector('.rl-active')?.checked;
        f.display_order = parseInt(card.querySelector('.rl-order')?.value || String(i), 10);
        sync();
      };

      card.querySelectorAll('input,select,textarea').forEach((el) => {
        el.addEventListener('change', read);
        el.addEventListener('input', read);
      });
      card.querySelector('.rl-type')?.addEventListener('change', () => {
        read();
        render();
      });
      card.querySelector('.rl-up')?.addEventListener('click', () => {
        if (i > 0) {
          const tmp = fields[i - 1];
          fields[i - 1] = fields[i];
          fields[i] = tmp;
          render();
        }
      });
      card.querySelector('.rl-down')?.addEventListener('click', () => {
        if (i < fields.length - 1) {
          const tmp = fields[i + 1];
          fields[i + 1] = fields[i];
          fields[i] = tmp;
          render();
        }
      });
      card.querySelector('.rl-del')?.addEventListener('click', () => {
        if (confirm('Remove this field?')) {
          fields.splice(i, 1);
          render();
        }
      });
    });
    sync();
  }

  addBtn?.addEventListener('click', () => {
    fields.push({
      id: 0,
      field_label: 'New field',
      field_key: '',
      field_type: 'text',
      is_required: false,
      is_active: true,
      help_text: '',
      placeholder: '',
      field_options: '',
      display_order: fields.length,
    });
    render();
  });

  function bindFormSubmit(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', () => {
      sync();
    });
  }
  bindFormSubmit('rlCreateForm');
  bindFormSubmit('rlEditForm');

  render();
})();
