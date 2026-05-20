/**
 * Render dynamic request_logic_fields on new_request.php
 */
window.RequestLogicIntakeFields = {
  escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  },

  optionsList(f) {
    const raw = (f.field_options || '').toString();
    return raw
      .split(/\r?\n/)
      .map((x) => x.trim())
      .filter(Boolean);
  },

  renderFields(container, fields) {
    if (!container) return;
    if (!fields || !fields.length) {
      container.innerHTML = '';
      return;
    }
    let html =
      '<div class="col-12"><hr class="my-2"></div><div class="col-12"><div class="card border-0 bg-light-subtle"><div class="card-body p-3"><h2 class="h6 fw-bold mb-3">Request-specific information</h2><div class="row g-3">';
    const esc = this.escapeHtml.bind(this);
    fields.forEach((f) => {
      const type = (f.field_type || 'text').toLowerCase();
      if (type === 'instruction') {
        const title = f.field_label ? '<div class="fw-semibold mb-1">' + esc(f.field_label) + '</div>' : '';
        html +=
          '<div class="col-12"><div class="alert alert-info border small mb-0">' +
          title +
          '<div style="white-space:pre-wrap;">' +
          esc(f.instruction_text || '') +
          '</div></div></div>';
        return;
      }
      const req = f.is_required ? ' required' : '';
      const name = 'custom_fields[' + f.id + ']';
      const help = f.help_text_display || f.help_text || '';
      const ph = f.placeholder ? ' placeholder="' + esc(f.placeholder) + '"' : '';
      html +=
        '<div class="col-12"><label class="form-label fw-semibold small">' +
        esc(f.field_label) +
        (f.is_required ? ' <span class="text-danger">*</span>' : '') +
        '</label>';
      if (type === 'textarea' || type === 'paragraph') {
        html += '<textarea class="form-control" name="' + name + '" rows="4"' + req + ph + '></textarea>';
      } else if (type === 'dropdown' || type === 'select') {
        const opts = this.optionsList(f);
        html += '<select class="form-select" name="' + name + '"' + req + '>';
        html += '<option value="">Select…</option>';
        opts.forEach((opt) => {
          html += '<option value="' + esc(opt) + '">' + esc(opt) + '</option>';
        });
        html += '</select>';
      } else if (type === 'radio') {
        const opts = this.optionsList(f);
        const use = opts.length ? opts : ['Yes', 'No'];
        html += '<div class="d-flex flex-wrap gap-3">';
        use.forEach((opt, i) => {
          html +=
            '<div class="form-check"><input class="form-check-input" type="radio" name="' +
            name +
            '" value="' +
            esc(opt) +
            '" id="rf_' +
            f.id +
            '_' +
            i +
            '"' +
            req +
            '><label class="form-check-label small" for="rf_' +
            f.id +
            '_' +
            i +
            '">' +
            esc(opt) +
            '</label></div>';
        });
        html += '</div>';
      } else if (type === 'checkbox') {
        const opts = this.optionsList(f);
        if (opts.length > 1) {
          opts.forEach((opt, i) => {
            html +=
              '<div class="form-check"><input class="form-check-input" type="checkbox" name="' +
              name +
              '[]" value="' +
              esc(opt) +
              '" id="cf_' +
              f.id +
              '_' +
              i +
              '"><label class="form-check-label small" for="cf_' +
              f.id +
              '_' +
              i +
              '">' +
              esc(opt) +
              '</label></div>';
          });
        } else {
          html +=
            '<div class="form-check"><input class="form-check-input" type="checkbox" name="' +
            name +
            '" value="1" id="cf_' +
            f.id +
            '"><label class="form-check-label small" for="cf_' +
            f.id +
            '">' +
            esc(ph ? f.placeholder : 'Yes') +
            '</label></div>';
        }
      } else {
        let inputType = 'text';
        if (type === 'email') inputType = 'email';
        else if (type === 'number') inputType = 'number';
        else if (type === 'date') inputType = 'date';
        html +=
          '<input class="form-control" type="' +
          inputType +
          '" name="' +
          name +
          '"' +
          req +
          ph +
          '>';
      }
      if (help && !String(help).startsWith('placeholder:')) {
        html += '<div class="form-text text-muted small">' + esc(help) + '</div>';
      }
      html += '</div>';
    });
    html += '</div></div></div></div></div>';
    container.innerHTML = html;
  },
};
