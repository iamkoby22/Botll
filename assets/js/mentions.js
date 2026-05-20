/**
 * @mention autocomplete for ticket comment textareas.
 */
(function () {
  function initMentionInput(textarea) {
    if (!textarea || textarea.dataset.mentionInit === '1') return;
    textarea.dataset.mentionInit = '1';

    const wrap = document.createElement('div');
    wrap.className = 'mention-input-wrap position-relative';
    textarea.parentNode.insertBefore(wrap, textarea);
    wrap.appendChild(textarea);

    const menu = document.createElement('div');
    menu.className = 'mention-suggestions list-group position-absolute d-none shadow-sm';
    menu.style.cssText = 'z-index:1050;max-height:220px;overflow-y:auto;min-width:280px;';
    wrap.appendChild(menu);

    const hiddenWrap = document.createElement('div');
    hiddenWrap.className = 'mention-hidden-ids d-none';
    wrap.appendChild(hiddenWrap);

    const status = document.createElement('div');
    status.className = 'mention-status small text-muted mt-1 d-none';
    wrap.appendChild(status);

    const selected = new Map();
    let debounce = null;
    let activeIndex = -1;
    let lastQuery = '';
    let atStart = -1;

    function apiBase() {
      const base = document.body.dataset.appBase || '';
      return (base ? base.replace(/\/$/, '') + '/' : '') + 'api/user_mentions.php';
    }

    function showStatus(msg, isError) {
      status.textContent = msg;
      status.classList.remove('d-none', 'text-danger', 'text-muted');
      status.classList.add(isError ? 'text-danger' : 'text-muted');
    }

    function hideStatus() {
      status.classList.add('d-none');
    }

    function syncHidden() {
      hiddenWrap.innerHTML = '';
      selected.forEach((user) => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'mention_user_ids[]';
        inp.value = String(user.id);
        hiddenWrap.appendChild(inp);
      });
    }

    function hideMenu() {
      menu.classList.add('d-none');
      menu.innerHTML = '';
      activeIndex = -1;
      atStart = -1;
      hideStatus();
    }

    function renderUsers(users) {
      menu.innerHTML = '';
      if (!users.length) {
        showStatus('No matching active users found.', false);
        menu.classList.remove('d-none');
        return;
      }
      hideStatus();
      users.forEach((u, i) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'list-group-item list-group-item-action py-2 small';
        btn.textContent = u.label || u.name;
        btn.dataset.index = String(i);
        btn.addEventListener('mousedown', (e) => {
          e.preventDefault();
          pickUser(u);
        });
        menu.appendChild(btn);
      });
      menu.classList.remove('d-none');
      activeIndex = 0;
      highlightActive();
    }

    function highlightActive() {
      menu.querySelectorAll('.list-group-item').forEach((el, i) => {
        el.classList.toggle('active', i === activeIndex);
      });
    }

    function pickUser(user) {
      if (atStart < 0) return;
      const val = textarea.value;
      const before = val.slice(0, atStart);
      const after = val.slice(textarea.selectionStart);
      const insert = user.insert || ('@' + (user.username || user.name));
      textarea.value = before + insert + ' ' + after;
      const pos = (before + insert + ' ').length;
      textarea.setSelectionRange(pos, pos);
      selected.set(user.id, user);
      syncHidden();
      hideMenu();
      textarea.focus();
    }

    function findAtQuery() {
      const pos = textarea.selectionStart;
      const text = textarea.value.slice(0, pos);
      const match = text.match(/@([A-Za-z0-9_.-]*)$/);
      if (!match) return null;
      return { query: match[1], start: pos - match[0].length };
    }

    function search(q) {
      showStatus('Searching users...', false);
      fetch(apiBase() + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((data) => {
          if (!data.ok) throw new Error(data.error || 'Search failed');
          renderUsers(data.users || []);
        })
        .catch(() => {
          showStatus('Could not load user suggestions.', true);
          menu.classList.add('d-none');
        });
    }

    textarea.addEventListener('input', () => {
      const found = findAtQuery();
      if (!found) {
        hideMenu();
        return;
      }
      atStart = found.start;
      lastQuery = found.query;
      clearTimeout(debounce);
      debounce = setTimeout(() => search(lastQuery), 200);
    });

    textarea.addEventListener('keydown', (e) => {
      if (menu.classList.contains('d-none')) return;
      const items = menu.querySelectorAll('.list-group-item');
      if (!items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        highlightActive();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        highlightActive();
      } else if (e.key === 'Enter' && activeIndex >= 0) {
        e.preventDefault();
        items[activeIndex].dispatchEvent(new MouseEvent('mousedown'));
      } else if (e.key === 'Escape') {
        hideMenu();
      }
    });

    textarea.addEventListener('blur', () => {
      setTimeout(hideMenu, 150);
    });
  }

  document.querySelectorAll('textarea.mention-input').forEach(initMentionInput);
})();
