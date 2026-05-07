(function () {
  const root = document.getElementById("tplBuilderRoot");
  if (!root) return;

  function escapeAttr(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  function escapeHtml(s) {
    const t = document.createElement("div");
    t.textContent = s;
    return t.innerHTML;
  }

  function slug(s) {
    return String(s || "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/^_|_$/g, "")
      .slice(0, 40);
  }

  const hiddenJson = document.getElementById("tplFieldsJson");
  const listEl = document.getElementById("tplFieldList");
  const settingsEl = document.getElementById("tplFieldSettings");
  const previewEl = document.getElementById("tplPreview");
  const modeEdit = document.getElementById("modeEdit");
  const modePreview = document.getElementById("modePreview");

  const types = [
    { id: "text", label: "Text Field" },
    { id: "paragraph", label: "Paragraph" },
    { id: "dropdown", label: "Dropdown" },
    { id: "checkbox", label: "Checkbox" },
    { id: "radio", label: "Radio" },
    { id: "date", label: "Date" },
    { id: "file", label: "File Upload" },
    { id: "divider", label: "Divider" },
    { id: "section", label: "Section" },
    { id: "user_selector", label: "User Selector" },
    { id: "custom", label: "Custom" },
  ];

  let fields = [];
  try {
    fields = JSON.parse(hiddenJson.value || "[]");
    if (!Array.isArray(fields)) fields = [];
  } catch (_) {
    fields = [];
  }
  let selectedIndex = fields.length ? 0 : -1;

  function uid() {
    return "f_" + String(Date.now()) + "_" + String(Math.random()).slice(2, 6);
  }

  function syncHidden() {
    hiddenJson.value = JSON.stringify(fields);
  }

  function renderList() {
    if (!listEl) return;
    listEl.innerHTML = "";
    if (!fields.length) {
      listEl.innerHTML = '<div class="text-muted small p-3 border rounded-3 bg-light">Drag fields here — or use Add Field on the left.</div>';
      return;
    }
    fields.forEach((f, i) => {
      const card = document.createElement("div");
      card.className = "border rounded-3 p-2 mb-2 tpl-field-card" + (i === selectedIndex ? " border-primary shadow-sm" : "");
      card.style.cursor = "pointer";
      card.innerHTML =
        '<div class="d-flex justify-content-between align-items-start gap-2">' +
        '<div><div class="fw-semibold small">' +
        (f.field_label || "Untitled") +
        '</div><div class="text-muted" style="font-size:0.72rem;">' +
        (f.field_type || "text") +
        "</div></div>" +
        '<div class="btn-group btn-group-sm">' +
        '<button type="button" class="btn btn-outline-muted btn-up" data-i="' +
        i +
        '"><i class="bi bi-arrow-up"></i></button>' +
        '<button type="button" class="btn btn-outline-muted btn-down" data-i="' +
        i +
        '"><i class="bi bi-arrow-down"></i></button>' +
        '<button type="button" class="btn btn-outline-danger btn-del" data-i="' +
        i +
        '"><i class="bi bi-trash"></i></button>' +
        "</div></div>";
      card.addEventListener("click", (e) => {
        if (e.target.closest("button")) return;
        selectedIndex = i;
        renderList();
        renderSettings();
      });
      listEl.appendChild(card);
    });
    listEl.querySelectorAll(".btn-up").forEach((b) =>
      b.addEventListener("click", (e) => {
        e.stopPropagation();
        const i = +b.getAttribute("data-i");
        if (i > 0) {
          const t = fields[i - 1];
          fields[i - 1] = fields[i];
          fields[i] = t;
          selectedIndex = i - 1;
          renderList();
          renderSettings();
          syncHidden();
        }
      })
    );
    listEl.querySelectorAll(".btn-down").forEach((b) =>
      b.addEventListener("click", (e) => {
        e.stopPropagation();
        const i = +b.getAttribute("data-i");
        if (i < fields.length - 1) {
          const t = fields[i + 1];
          fields[i + 1] = fields[i];
          fields[i] = t;
          selectedIndex = i + 1;
          renderList();
          renderSettings();
          syncHidden();
        }
      })
    );
    listEl.querySelectorAll(".btn-del").forEach((b) =>
      b.addEventListener("click", (e) => {
        e.stopPropagation();
        const i = +b.getAttribute("data-i");
        fields.splice(i, 1);
        selectedIndex = Math.min(selectedIndex, fields.length - 1);
        renderList();
        renderSettings();
        syncHidden();
      })
    );
  }

  function renderSettings() {
    if (!settingsEl) return;
    if (selectedIndex < 0 || !fields[selectedIndex]) {
      settingsEl.innerHTML = '<p class="small text-muted mb-0">Select a field to edit settings.</p>';
      return;
    }
    const f = fields[selectedIndex];
    settingsEl.innerHTML = "";
    const wrap = document.createElement("div");
    wrap.className = "vstack gap-2";
    wrap.innerHTML =
      '<label class="small fw-semibold">Field Label</label>' +
      '<input class="form-control form-control-sm" data-k="field_label" value="' +
      escapeAttr(f.field_label || "") +
      '">' +
      '<label class="small fw-semibold">Field Type</label>' +
      '<select class="form-select form-select-sm" data-k="field_type">' +
      types
        .map(
          (t) =>
            '<option value="' +
            t.id +
            '"' +
            (f.field_type === t.id ? " selected" : "") +
            ">" +
            t.label +
            "</option>"
        )
        .join("") +
      "</select>" +
      '<label class="small fw-semibold">Options (dropdown/radio/checkbox — one per line)</label>' +
      '<textarea class="form-control form-control-sm" rows="3" data-k="field_options">' +
      escapeAttr(f.field_options || "") +
      "</textarea>" +
      '<div class="form-check"><input class="form-check-input" type="checkbox" data-k="is_required" ' +
      (f.is_required ? "checked" : "") +
      '><label class="form-check-label small">Required</label></div>' +
      '<label class="small fw-semibold">Placeholder</label>' +
      '<input class="form-control form-control-sm" data-k="placeholder" value="' +
      escapeAttr(f.placeholder || "") +
      '">' +
      '<label class="small fw-semibold">Description</label>' +
      '<textarea class="form-control form-control-sm" rows="2" data-k="help_text">' +
      escapeAttr(f.help_text || "") +
      "</textarea>" +
      '<label class="small fw-semibold">Default Value</label>' +
      '<input class="form-control form-control-sm" data-k="default_value" value="' +
      escapeAttr(f.default_value || "") +
      '">';
    settingsEl.appendChild(wrap);
    wrap.querySelectorAll("[data-k]").forEach((el) => {
      const k = el.getAttribute("data-k");
      const handler = () => {
        if (!k) return;
        if (el.type === "checkbox") {
          f[k] = el.checked;
        } else {
          f[k] = el.value;
        }
        if (k === "field_label" && !f.field_name) {
          f.field_name = slug(f.field_label);
        }
        syncHidden();
        renderList();
      };
      el.addEventListener("input", handler);
      el.addEventListener("change", handler);
    });
  }

  function renderPreview() {
    if (!previewEl) return;
    previewEl.innerHTML = "";
    fields.forEach((f) => {
      const d = document.createElement("div");
      d.className = "mb-2 small";
      if (f.field_type === "divider") {
        d.innerHTML = "<hr>";
      } else if (f.field_type === "section") {
        d.innerHTML = '<div class="fw-bold">' + escapeHtml(f.field_label || "Section") + "</div>";
      } else {
        d.innerHTML =
          '<label class="form-label small mb-0">' +
          escapeHtml(f.field_label || "Field") +
          (f.is_required ? " *" : "") +
          "</label>" +
          '<div class="text-muted" style="font-size:0.75rem;">' +
          escapeHtml(f.field_type || "text") +
          "</div>";
      }
      previewEl.appendChild(d);
    });
    if (!fields.length) {
      previewEl.innerHTML = '<span class="text-muted small">No fields yet.</span>';
    }
  }

  function addField(type) {
    fields.push({
      id: uid(),
      field_type: type,
      field_label: types.find((x) => x.id === type)?.label || "Field",
      field_name: "",
      field_options: "",
      is_required: false,
      placeholder: "",
      help_text: "",
      default_value: "",
    });
    selectedIndex = fields.length - 1;
    syncHidden();
    renderList();
    renderSettings();
    renderPreview();
  }

  root.querySelectorAll("[data-add-field]").forEach((btn) => {
    btn.addEventListener("click", () => addField(btn.getAttribute("data-add-field") || "text"));
  });

  function setMode(edit) {
    root.querySelectorAll(".tpl-builder-panel").forEach((el) => {
      el.classList.toggle("d-none", !edit);
    });
    const prevWrap = document.getElementById("tplPrevWrap");
    if (prevWrap) prevWrap.classList.toggle("d-none", edit);
    if (!edit) renderPreview();
  }

  if (modeEdit)
    modeEdit.addEventListener("click", () => {
      modeEdit.classList.add("active");
      if (modePreview) modePreview.classList.remove("active");
      setMode(true);
    });
  if (modePreview)
    modePreview.addEventListener("click", () => {
      if (modeEdit) modeEdit.classList.remove("active");
      modePreview.classList.add("active");
      setMode(false);
    });

  renderList();
  renderSettings();
  renderPreview();
  syncHidden();

  const form = root.closest("form");
  if (form) {
    form.addEventListener("submit", function () {
      syncHidden();
    });
  }
})();
