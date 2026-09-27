document.addEventListener('DOMContentLoaded', () => {
  const escaped = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const page = new URLSearchParams(location.search).get('page') || 'dashboard';
  if (page === 'requests') {
    const savedScroll = sessionStorage.getItem('access-portal-requests-scroll');
    if (savedScroll !== null) {
      sessionStorage.removeItem('access-portal-requests-scroll');
      requestAnimationFrame(() => requestAnimationFrame(() => window.scrollTo(0, Number(savedScroll))));
    }
  }

  function bindAccountVisibility(form) {
    const email = form.querySelector('input[name="email"]');
    const checkbox = form.querySelector('input[name="account_requested"]');
    if (!email || !checkbox) return;
    const container = checkbox.closest('.field');
    const requestButton = form.querySelector('[name="open_access_request"]');
    const status = form.querySelector('select[name="status"]');
    const gatedFields = status ? [...form.querySelectorAll('[name="passwords_received"], [name="user_confirmed_access"], select[name="status"]')] : [];
    let hint;
    if (status) {
      hint = document.createElement('p');
      hint.className = 'field-hint';
      hint.id = 'email-access-hint';
      hint.textContent = 'Enter a valid email address to edit access details. Saved values are preserved while these fields are locked.';
      email.after(hint);
      email.setAttribute('aria-describedby', hint.id);
    }
    const refresh = () => {
      const value = email.value.trim();
      const hasEmail = value !== '';
      container.hidden = hasEmail;
      const local = value.split('@')[0];
      const valid = hasEmail && value.length <= 254 && local.length <= 64
        && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) && !email.validity.typeMismatch
        && !local.startsWith('.') && !local.endsWith('.') && !local.includes('..');
      if (requestButton) requestButton.disabled = !valid;
      if (status) {
        email.setCustomValidity(hasEmail && !valid ? 'Enter a valid email address, for example name@primark.com.' : '');
        hint.hidden = valid;
        gatedFields.forEach(control => {
          control.disabled = !valid;
          control.closest('.field')?.classList.toggle('field-locked', !valid);
        });
        if (!valid) return;
      }
    };
    email.addEventListener('input', refresh);
    email.addEventListener('change', refresh);
    form.addEventListener('reset', () => setTimeout(refresh, 0));
    refresh();
  }
  if (page === 'newcomers') document.querySelectorAll('form').forEach(bindAccountVisibility);

  function clean(html) {
    if (!/<[a-z][\s\S]*>/i.test(html)) return html.split(/\r?\n/).map(x => '<p>'+escaped(x)+'</p>').join('');
    const box = document.createElement('template');
    box.innerHTML = html;
    const allowed = ['P','DIV','SPAN','BR','STRONG','B','EM','I','U','UL','OL','LI','H2','H3','A','PRE','CODE','BLOCKQUOTE'];
    box.content.querySelectorAll('script,style,iframe,object,embed').forEach(n => n.remove());
    box.content.querySelectorAll('*').forEach(n => {
      if (!allowed.includes(n.tagName)) { n.replaceWith(...n.childNodes); return; }
      [...n.attributes].forEach(a => {
        if (n.tagName === 'A' && a.name === 'href' && /^(https?:|mailto:)/i.test(a.value)) return;
        if (n.tagName === 'OL' && a.name === 'start' && /^\d+$/.test(a.value)) return;
        n.removeAttribute(a.name);
      });
      if (n.tagName === 'A') { n.target = '_blank'; n.rel = 'noopener noreferrer'; }
    });
    return box.innerHTML;
  }

  function initialiseRichEditor(textarea) {
    if (textarea.dataset.richEditorReady) return;
    textarea.dataset.richEditorReady = 'true';
    const toolbar = document.createElement('div');
    toolbar.className = 'rich-toolbar';
    toolbar.setAttribute('role','group');
    toolbar.setAttribute('aria-label','Text formatting');
    const editor = document.createElement('div');
    editor.className = 'rich-editor';
    editor.contentEditable = 'true';
    editor.setAttribute('role','textbox');
    editor.setAttribute('aria-label','Instruction content');
    editor.setAttribute('aria-multiline','true');
    editor.innerHTML = clean(textarea.value);
    [['bold','Bold'],['italic','Italic'],['underline','Underline'],['insertUnorderedList','• List'],['insertOrderedList','1. List'],['formatBlock','Heading'],['createLink','Link'],['removeFormat','Clear']].forEach(([cmd,label]) => {
      const b = document.createElement('button');
      b.type = 'button'; b.textContent = label;
      b.addEventListener('mousedown', e => e.preventDefault());
      b.onclick = () => {
        let value = null;
        if (cmd === 'createLink') {
          value = prompt('URL (https://... or mailto:...)');
          if (!value || !/^(https?:|mailto:)/i.test(value)) return;
        }
        if (cmd === 'formatBlock') value = '<h2>';
        editor.focus();
        document.execCommand(cmd, false, value);
      };
      toolbar.append(b);
    });
    textarea.hidden = true;
    textarea.before(toolbar, editor);
    textarea.form.addEventListener('submit', () => { textarea.value = clean(editor.innerHTML); });
  }
  document.querySelectorAll('textarea[name="content"]').forEach(initialiseRichEditor);

  function openModal(title, trigger, variant = '') {
    const shade = document.createElement('div');
    shade.className = 'modal-shade';
    shade.innerHTML = '<section class="modal-box '+variant+'" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1"><header class="modal-header"><div><div class="eyebrow">Access Portal</div><h2 id="modal-title"></h2></div><button type="button" class="btn secondary modal-close" aria-label="Close dialog">Close</button></header><div class="modal-content"></div></section>';
    shade.querySelector('h2').textContent = title;
    const body = shade.querySelector('.modal-content');
    const box = shade.querySelector('.modal-box');
    let changed = false;
    let backdropPointerStarted = false;
    const close = () => {
      if (changed && !window.confirm('You have unsaved changes. Close this window without saving them?')) return false;
      shade.remove(); document.body.classList.remove('modal-open'); trigger?.focus(); return true;
    };
    shade.querySelector('.modal-close').onclick = close;
    shade.addEventListener('input', e => { if (e.target.closest('form')) changed = true; });
    shade.addEventListener('change', e => { if (e.target.closest('form')) changed = true; });
    shade.addEventListener('pointerdown', e => { backdropPointerStarted = e.target === shade; });
    shade.addEventListener('click', e => {
      if (backdropPointerStarted && e.target === shade) close();
      backdropPointerStarted = false;
    });
    shade.addEventListener('keydown', e => {
      if (e.key === 'Escape') { e.preventDefault(); close(); }
      if (e.key !== 'Tab') return;
      const focusable = [...box.querySelectorAll('button:not([disabled]),a[href],input:not([type="hidden"]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]')].filter(n => n.getClientRects().length);
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    document.body.append(shade);
    document.body.classList.add('modal-open');
    shade.querySelector('.modal-close').focus();
    return { shade, body, close };
  }

  function initialiseCombobox(input) {
    if (input.dataset.comboboxReady) return;
    const source = document.getElementById(input.dataset.combobox);
    if (!source) return;
    input.dataset.comboboxReady = 'true';
    input.removeAttribute('list');
    const values = [...source.options].map(option => option.value).filter(Boolean);
    const wrapper = document.createElement('div');
    wrapper.className = 'combobox';
    input.before(wrapper);
    wrapper.append(input);
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'combobox-toggle';
    toggle.setAttribute('aria-label', 'Show options for '+(input.labels?.[0]?.textContent || 'field'));
    toggle.setAttribute('aria-expanded', 'false');
    toggle.textContent = '⌄';
    const menu = document.createElement('div');
    menu.className = 'combobox-options';
    menu.id = input.id+'-options';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', menu.id);
    wrapper.append(toggle, menu);
    const render = () => {
      const query = input.value.trim().toLocaleLowerCase();
      const matches = values.filter(value => value.toLocaleLowerCase().includes(query));
      menu.replaceChildren();
      if (!matches.length) {
        const empty = document.createElement('div');
        empty.className = 'combobox-empty';
        empty.textContent = 'No matching options';
        menu.append(empty);
        return;
      }
      matches.forEach(value => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'combobox-option';
        option.setAttribute('role', 'option');
        option.textContent = value;
        option.addEventListener('click', () => {
          input.value = value;
          input.dispatchEvent(new Event('change', {bubbles:true}));
          close();
          input.focus();
        });
        menu.append(option);
      });
    };
    const open = () => { render(); menu.hidden = false; toggle.setAttribute('aria-expanded', 'true'); };
    const close = () => { menu.hidden = true; toggle.setAttribute('aria-expanded', 'false'); };
    input.addEventListener('click', open);
    input.addEventListener('input', open);
    input.addEventListener('keydown', event => { if (event.key === 'Escape') { close(); input.focus(); } });
    toggle.addEventListener('click', () => { if (menu.hidden) { open(); input.focus(); } else close(); });
    document.addEventListener('pointerdown', event => { if (!wrapper.contains(event.target)) close(); });
  }

  function bindAccountRequestDetails(form) {
    const requested = form.querySelector('input[name="account_requested"]');
    const details = [...form.querySelectorAll('[data-account-request-details]')];
    if (!requested || !details.length) return;
    const refresh = () => details.forEach(field => { field.hidden = !requested.checked; });
    requested.addEventListener('change', refresh);
    refresh();
  }

  function bindLatinOnlyValidation(form) {
    const controls = [...form.querySelectorAll('input:not([type="hidden"]):not([type="checkbox"]), textarea, select')];
    const error = document.createElement('div');
    error.className = 'form-validation-error';
    error.setAttribute('role', 'alert');
    error.hidden = true;
    form.prepend(error);
    const hasCyrillic = control => /\p{Script=Cyrillic}/u.test(control.value);
    const validate = () => {
      const visible = controls.filter(control => !control.closest('[hidden]'));
      const cyrillicFields = visible.filter(hasCyrillic);
      const snowUrl = form.querySelector('[name="snow_request_url"]');
      const invalidSnowUrl = snowUrl && !snowUrl.closest('[hidden]') && snowUrl.value.trim() !== ''
        && !snowUrl.value.includes('https://primarkprod.service-now.com/') ? [snowUrl] : [];
      const invalid = [...new Set([...cyrillicFields, ...invalidSnowUrl])];
      controls.forEach(control => control.closest('.field')?.classList.toggle('field-invalid', invalid.includes(control)));
      const messages = [];
      if (cyrillicFields.length) messages.push('Only Latin characters, numbers and special symbols are allowed.');
      if (invalidSnowUrl.length) messages.push('SNOW Request URL must contain https://primarkprod.service-now.com/.');
      error.textContent = messages.join(' ');
      error.hidden = invalid.length === 0;
      return invalid;
    };
    controls.forEach(control => {
      control.addEventListener('input', validate);
      control.addEventListener('change', validate);
    });
    form.addEventListener('submit', event => {
      const invalid = validate();
      if (invalid.length) { event.preventDefault(); invalid[0].focus(); }
    });
  }

  function bindExistingUserRequest(form) {
    const checkbox = form.querySelector('[data-existing-user]');
    const knownUser = form.querySelector('[data-request-known-user]');
    const knownTeam = form.querySelector('[data-request-known-team]');
    const manualUser = form.querySelector('[data-request-manual-user]');
    const manualEmail = form.querySelector('[data-request-manual-email]');
    const manualTeam = form.querySelector('[data-request-manual-team]');
    const knownSelect = form.querySelector('[name="user_name"]');
    const knownTeamInput = form.querySelector('#request-known-team');
    const managerInput = form.querySelector('[name="manager"]');
    const userInput = form.querySelector('[name="manual_user_name"]');
    const emailInput = form.querySelector('[name="manual_user_email"]');
    if (!checkbox || !knownUser || !knownTeam || !manualUser || !manualEmail || !manualTeam || !knownSelect || !knownTeamInput || !userInput || !emailInput) return;
    const userOptions = document.getElementById('request-user-options');
    let lookupTimer;
    let fillingFromHistory = false;
    const optionFor = (listId, value) => [...(document.getElementById(listId)?.options || [])].find(item => item.value === value);
    const updateKnownTeam = () => {
      const option = optionFor('request-user-options', knownSelect.value);
      knownTeamInput.value = option?.dataset.team || '';
      if (managerInput) managerInput.value = option?.dataset.manager || '';
    };
    const refresh = () => {
      const isExisting = checkbox.checked;
      knownUser.hidden = isExisting;
      knownTeam.hidden = isExisting;
      manualUser.hidden = !isExisting;
      manualEmail.hidden = !isExisting;
      manualTeam.hidden = !isExisting;
      knownSelect.required = !isExisting;
      userInput.required = isExisting;
      emailInput.required = isExisting;
      if (!isExisting) updateKnownTeam();
      else if (managerInput) managerInput.value = '';
    };
    const fillFromLatestRequest = async () => {
      if (!checkbox.checked || fillingFromHistory) return;
      const name = userInput.value.trim();
      const email = emailInput.value.trim();
      if (!name && !email) return;
      try {
        const parameters = new URLSearchParams({request_user_history_json: '1', user_name: name, user_email: email});
        const response = await fetch(`?${parameters}`, {cache:'no-store'});
        if (!response.ok) return;
        const latest = await response.json();
        if (!latest.full_name && !latest.email) return;
        fillingFromHistory = true;
        if (latest.full_name && !name) userInput.value = latest.full_name;
        if (latest.email && !email) emailInput.value = latest.email;
        if (latest.team) {
          const teamInput = manualTeam.querySelector('[name="manual_team_name"]');
          if (teamInput) {
            teamInput.value = latest.team;
            teamInput.dispatchEvent(new Event('input', {bubbles:true}));
            teamInput.dispatchEvent(new Event('change', {bubbles:true}));
          }
        }
        if (managerInput && latest.manager) managerInput.value = latest.manager;
        const justification = form.querySelector('[name="justification"]');
        if (justification && !justification.value.trim() && latest.justification) justification.value = latest.justification;
      } catch { /* A manual request can still be completed without a prior request. */ }
      finally { fillingFromHistory = false; }
    };
    const scheduleHistoryLookup = () => {
      if (!checkbox.checked) return;
      clearTimeout(lookupTimer);
      lookupTimer = setTimeout(fillFromLatestRequest, 250);
    };
    checkbox.addEventListener('change', refresh);
    knownSelect.addEventListener('input', updateKnownTeam);
    knownSelect.addEventListener('change', updateKnownTeam);
    [userInput, emailInput].forEach(control => {
      control.addEventListener('input', scheduleHistoryLookup);
      control.addEventListener('change', scheduleHistoryLookup);
    });
    refresh();
  }

  function bindNewcomerTeamManager(form) {
    const team = form.querySelector('[name="team_name"]');
    const manager = form.querySelector('[name="manager"]');
    const options = document.getElementById('team-options');
    if (!team || !manager || !options) return;
    const refresh = () => {
      const option = [...options.options].find(item => item.value === team.value);
      if (option) manager.value = option.dataset.manager || '';
    };
    team.addEventListener('input', refresh);
    team.addEventListener('change', refresh);
  }

  function bindUserConfirmationGate(form) {
    const passwordInputs = [...form.querySelectorAll('[name="passwords_received"]')];
    const confirmationInputs = [...form.querySelectorAll('[name="user_confirmed_access"]')];
    const confirmationField = confirmationInputs[0]?.closest('.field');
    if (!passwordInputs.length || !confirmationInputs.length || !confirmationField) return;
    const refresh = () => {
      const passwords = passwordInputs.find(input => input.checked)?.value;
      const enabled = passwords === 'Both' && !passwordInputs[0].disabled;
      confirmationInputs.forEach(input => { input.disabled = !enabled; });
      confirmationField.classList.toggle('field-locked', !enabled);
      if (!enabled) {
        const no = confirmationInputs.find(input => input.value === '0');
        if (no) no.checked = true;
      }
    };
    passwordInputs.forEach(input => input.addEventListener('change', refresh));
    form.querySelector('input[name="email"]')?.addEventListener('input', refresh);
    form.querySelector('input[name="email"]')?.addEventListener('change', refresh);
    refresh();
  }

  function bindCompletionStatus(form) {
    const email = form.querySelector('input[name="email"]');
    const status = form.querySelector('select[name="status"]');
    const passwordInputs = [...form.querySelectorAll('[name="passwords_received"]')];
    const confirmationInputs = [...form.querySelectorAll('[name="user_confirmed_access"]')];
    if (!email || !status || !passwordInputs.length || !confirmationInputs.length) return;
    let previousStatus = status.value === 'Complete' ? 'App access requested' : status.value;
    const hasValidEmail = () => {
      const value = email.value.trim();
      const local = value.split('@')[0];
      return value !== '' && value.length <= 254 && local.length <= 64 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
        && !local.startsWith('.') && !local.endsWith('.') && !local.includes('..');
    };
    const refresh = () => {
      const passwords = passwordInputs.find(input => input.checked)?.value;
      const confirmed = confirmationInputs.find(input => input.checked)?.value;
      const complete = hasValidEmail() && passwords === 'Both' && confirmed === '1';
      const field = status.closest('.field');
      if (complete) {
        if (status.value !== 'Complete') previousStatus = status.value;
        status.value = 'Complete';
        status.disabled = true;
        status.dataset.completionLocked = 'true';
        field?.classList.add('field-locked');
      } else {
        if (status.dataset.completionLocked === 'true' && status.value === 'Complete') status.value = previousStatus || 'App access requested';
        status.dataset.completionLocked = '';
        status.disabled = !hasValidEmail();
        field?.classList.toggle('field-locked', !hasValidEmail());
      }
    };
    status.addEventListener('change', () => { if (status.value !== 'Complete') previousStatus = status.value; });
    [...passwordInputs, ...confirmationInputs, email].forEach(control => {
      control.addEventListener('input', refresh);
      control.addEventListener('change', refresh);
    });
    refresh();
  }

  async function bindNewcomerDuplicateCheck(form, currentId = '') {
    const name = form.querySelector('[name="full_name"]');
    const email = form.querySelector('[name="email"]');
    if (!name) return;
    const error = document.createElement('div');
    error.className = 'form-validation-error';
    error.hidden = true;
    form.prepend(error);
    try {
      const response = await fetch('?table_json=newcomers', {cache:'no-store'});
      if (!response.ok) throw Error('Could not load newcomers');
      const rows = (await response.json()).rows || [];
      const refresh = () => {
        const normalizedName = name.value.trim().toLocaleLowerCase();
        const normalizedEmail = email?.value.trim().toLocaleLowerCase() || '';
        const duplicateName = normalizedName !== '' && rows.some(row => String(row.id) !== String(currentId) && String(row.full_name || '').trim().toLocaleLowerCase() === normalizedName);
        const duplicateEmail = normalizedEmail !== '' && rows.some(row => String(row.id) !== String(currentId) && String(row.email || '').trim().toLocaleLowerCase() === normalizedEmail);
        name.setCustomValidity(duplicateName ? 'A newcomer with this First & Last user name already exists.' : '');
        if (email) email.setCustomValidity(duplicateEmail ? 'A newcomer with this Primark email already exists.' : '');
        name.closest('.field')?.classList.toggle('field-invalid', duplicateName);
        email?.closest('.field')?.classList.toggle('field-invalid', duplicateEmail);
        error.textContent = duplicateName || duplicateEmail ? 'A newcomer with this First & Last user name or Primark email already exists.' : '';
        error.hidden = !(duplicateName || duplicateEmail);
      };
      [name, email].filter(Boolean).forEach(control => {
        control.addEventListener('input', refresh);
        control.addEventListener('change', refresh);
      });
      refresh();
    } catch { /* Server-side duplicate validation remains active. */ }
  }

  async function bindRequestApplications(form) {
    const container = form.querySelector('[data-request-applications]');
    const knownUser = form.querySelector('[name="user_name"]');
    const manualTeam = form.querySelector('[name="manual_team_name"]');
    const existingUser = form.querySelector('[data-existing-user]');
    const fallbackJustification = form.querySelector('[name="justification"]');
    const payload = form.querySelector('[name="requests_json"]');
    const error = document.createElement('div');
    error.className = 'form-validation-error';
    error.hidden = true;
    container.before(error);
    if (!container || !knownUser || !manualTeam || !existingUser || !payload) return;
    let data;
    let renderedTeamId = null;
    let renderedMode = null;
    let renderedIdentity = null;
    let excludedApplicationIds = new Set();
    const teamIdFor = () => {
      const listId = existingUser.checked ? 'request-team-options' : 'request-user-options';
      const value = existingUser.checked ? manualTeam.value : knownUser.value;
      const option = [...(document.getElementById(listId)?.options || [])].find(item => item.value === value);
      return option?.dataset.teamId || null;
    };
    const identityFor = () => {
      if (existingUser.checked) return manualTeam.closest('form').querySelector('[name="manual_user_email"]')?.value.trim().toLowerCase() || '';
      const option = [...(document.getElementById('request-user-options')?.options || [])].find(item => item.value === knownUser.value);
      return option?.dataset.userId || '';
    };
    const rulesFor = teamId => data.rules.filter(rule => String(rule.team_id) === String(teamId));
    const ruleFor = (teamId, appId) => rulesFor(teamId).find(rule => String(rule.application_id) === String(appId));
    const rows = () => [...container.querySelectorAll('[data-request-app-row]')];
    const selectedIds = (except = null) => new Set(rows().filter(row => row !== except).map(row => row.querySelector('select')?.value).filter(Boolean));
    const renderOptions = row => {
      const select = row.querySelector('select');
      const current = select.value;
      const used = selectedIds(row);
      select.replaceChildren();
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Select an application';
      placeholder.disabled = row.dataset.fixed === 'true';
      placeholder.selected = !current;
      select.append(placeholder);
      data.applications.filter(app => (!excludedApplicationIds.has(String(app.id)) || String(app.id) === current) && (!used.has(String(app.id)) || String(app.id) === current)).forEach(app => {
        const option = document.createElement('option');
        option.value = app.id;
        option.textContent = app.name;
        option.selected = String(app.id) === current;
        select.append(option);
      });
    };
    const refreshAllOptions = () => rows().forEach(renderOptions);
    const syncMirror = row => {
      const select = row.querySelector('select');
      const mirror = row.querySelector('[data-request-mirror]');
      const rule = select.value ? ruleFor(renderedTeamId, select.value) : null;
      row.dataset.justification = rule?.justification || '';
      if (rule) {
        mirror.value = rule.mirror_id || '';
        mirror.readOnly = false;
        mirror.classList.remove('matrix-mirror');
      } else {
        mirror.value = '';
        mirror.readOnly = false;
        mirror.classList.remove('matrix-mirror');
      }
    };
    const createRow = ({ applicationId = '', fixed = false, active = true } = {}) => {
      const row = document.createElement('div');
      row.className = 'request-app-row';
      row.dataset.requestAppRow = '';
      row.dataset.fixed = String(fixed);
      row.dataset.active = String(active);
      row.innerHTML = '<div class="field"><label>Application</label><select></select></div><div class="field"><label>Mirror ID</label><input type="text" data-request-mirror></div><div class="request-app-action"></div>';
      container.append(row);
      const select = row.querySelector('select');
      const mirror = row.querySelector('[data-request-mirror]');
      renderOptions(row);
      select.value = String(applicationId);
      syncMirror(row);
      const action = row.querySelector('.request-app-action');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'btn secondary';
      action.append(button);
      const applyActive = () => {
        const enabled = row.dataset.active === 'true';
        select.disabled = !enabled;
        mirror.disabled = !enabled;
        row.classList.toggle('request-app-row--inactive', !enabled);
      };
      if (fixed && existingUser.checked) {
        button.textContent = 'Add';
        button.onclick = () => {
          row.dataset.active = 'true';
          button.textContent = 'Added';
          button.disabled = true;
          applyActive();
        };
      } else if (fixed) {
        button.textContent = 'Delete';
        button.classList.add('danger');
        button.onclick = () => { row.remove(); refreshAllOptions(); ensureTrailing(); };
      } else {
        button.textContent = applicationId ? 'Delete' : '—';
        button.disabled = !applicationId;
        button.classList.toggle('danger', Boolean(applicationId));
        button.onclick = () => { row.remove(); refreshAllOptions(); ensureTrailing(); };
      }
      applyActive();
      select.addEventListener('change', () => {
        syncMirror(row);
        if (!fixed) {
          button.textContent = select.value ? 'Delete' : '—';
          button.disabled = !select.value;
          button.classList.toggle('danger', Boolean(select.value));
        }
        refreshAllOptions();
        ensureTrailing();
      });
      return row;
    };
    const ensureTrailing = () => {
      if (!data || rows().length >= data.applications.filter(app => !excludedApplicationIds.has(String(app.id))).length) return;
      const blankRows = rows().filter(row => !row.dataset.fixed || row.dataset.fixed === 'false').filter(row => !row.querySelector('select').value);
      if (!blankRows.length) createRow();
      if (blankRows.length > 1) blankRows.slice(1).forEach(row => row.remove());
    };
    const renderForTeam = teamId => {
      container.replaceChildren();
      renderedTeamId = teamId;
      renderedMode = existingUser.checked ? 'manual' : 'listed';
      if (!teamId) {
        renderedTeamId = null;
        container.innerHTML = '<p class="muted">Select a User or Team to load Applications.</p>';
        return;
      }
      rulesFor(teamId).filter(rule => !excludedApplicationIds.has(String(rule.application_id))).sort((a,b) => String(a.application_id).localeCompare(String(b.application_id), undefined, {numeric:true})).forEach(rule => {
        createRow({applicationId:rule.application_id, fixed:true, active:!existingUser.checked});
      });
      refreshAllOptions();
      ensureTrailing();
    };
    const refreshTeam = async () => {
      const teamId = teamIdFor();
      const mode = existingUser.checked ? 'manual' : 'listed';
      const identity = identityFor();
      const identityChanged = identity !== renderedIdentity;
      if (identityChanged) {
        const parameters = new URLSearchParams({request_form_json: '1'});
        if (existingUser.checked) parameters.set('user_email', identity);
        else if (identity) parameters.set('user_id', identity);
        const response = await fetch(`?${parameters}`, {cache:'no-store'});
        if (!response.ok) throw Error('Could not load request form data');
        data = await response.json();
        excludedApplicationIds = new Set((data.existing_application_ids || []).map(String));
        renderedIdentity = identity;
      }
      if (!identityChanged && String(teamId || '') === String(renderedTeamId || '') && mode === renderedMode) return;
      renderForTeam(teamId);
    };
    try {
      const response = await fetch('?request_form_json=1', {cache:'no-store'});
      if (!response.ok) throw Error('Could not load request form data');
      data = await response.json();
      await refreshTeam();
      [knownUser, manualTeam, form.querySelector('[name="manual_user_email"]')].filter(Boolean).forEach(control => {
        control.addEventListener('input', refreshTeam);
        control.addEventListener('change', refreshTeam);
      });
      existingUser.addEventListener('change', refreshTeam);
      form.addEventListener('submit', event => {
        const activeRows = rows().filter(row => row.dataset.active === 'true' && row.querySelector('select').value);
        if (!activeRows.length) {
          event.preventDefault();
          error.textContent = 'Select or add at least one Application.';
          error.hidden = false;
          return;
        }
        const duplicate = new Set();
        const requests = activeRows.map(row => {
          const appId = row.querySelector('select').value;
          duplicate.add(appId);
          return {
            application_id: appId,
            mirror_id: row.querySelector('[data-request-mirror]').value,
            justification: row.dataset.justification || fallbackJustification?.value || '',
          };
        });
        if (duplicate.size !== requests.length) {
          event.preventDefault();
          error.textContent = 'Each Application can be selected only once.';
          error.hidden = false;
          return;
        }
        error.hidden = true;
        payload.value = JSON.stringify(requests);
      });
    } catch {
      container.innerHTML = '<p class="ui-error" role="alert">Unable to load Applications. Close the dialog and try again.</p>';
    }
  }

  document.querySelectorAll('[data-create-template]').forEach(button => {
    button.addEventListener('click', () => {
      const template = document.querySelector('#'+button.dataset.createTemplate);
      if (!template) return;
      const isInstruction = button.dataset.createTemplate === 'create-instructions-form';
      const isNewcomer = button.dataset.createTemplate === 'newcomer-create-form';
      const modal = openModal(button.textContent, button, isInstruction ? 'modal-box--instruction' : (isNewcomer ? 'modal-box--newcomer' : ''));
      modal.body.append(template.content.cloneNode(true));
      modal.body.querySelector('[data-cancel]').onclick = modal.close;
      modal.body.querySelectorAll('[data-combobox]').forEach(initialiseCombobox);
      if (isNewcomer) {
        const form = modal.body.querySelector('form');
        form.id = 'newcomer-create-form-active';
        const now = new Date();
        const dateValue = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
        modal.shade.querySelector('.modal-close').outerHTML = '<div class="modal-requested-datetime"><label for="newcomer-requested-datetime">Requested dateTime</label><input id="newcomer-requested-datetime" type="datetime-local" name="requested_datetime" form="newcomer-create-form-active" value="'+dateValue+'"></div>';
        bindAccountRequestDetails(form);
        bindLatinOnlyValidation(form);
        bindNewcomerTeamManager(form);
        bindNewcomerDuplicateCheck(form);
      }
      if (button.dataset.createTemplate === 'create-requests-form') {
        const form = modal.body.querySelector('form');
        bindExistingUserRequest(form);
        const newcomerId = new URLSearchParams(location.search).get('newcomer_id');
        if (new URLSearchParams(location.search).get('open_request') === '1' && newcomerId) {
          const option = [...(document.getElementById('request-user-options')?.options || [])].find(item => item.dataset.userId === newcomerId);
          const user = form.querySelector('[name="user_name"]');
          if (option && user) {
            user.value = option.value;
            user.dispatchEvent(new Event('input', {bubbles:true}));
            user.dispatchEvent(new Event('change', {bubbles:true}));
          }
        }
        bindRequestApplications(form);
      }
      modal.body.querySelectorAll('textarea[name="content"]').forEach(initialiseRichEditor);
      modal.body.querySelector('input:not([type=hidden]),select,textarea')?.focus();
    });
  });

  if (page === 'requests' && new URLSearchParams(location.search).get('open_request') === '1') {
    document.querySelector('[data-create-template="create-requests-form"]')?.click();
  }

  const field = (name,label,value='',type='text',required=false) => '<div class="field"><label for="edit-'+name+'">'+label+'</label><input id="edit-'+name+'" type="'+type+'" name="'+name+'" value="'+escaped(value)+'" '+(required?'required':'')+'></div>';
  const area = (name,label,value='') => '<div class="field full"><label for="edit-'+name+'">'+label+'</label><textarea id="edit-'+name+'" name="'+name+'">'+escaped(value)+'</textarea></div>';
  const check = (name,label,value) => '<div class="field checkbox-field"><label class="check-label"><input type="checkbox" name="'+name+'" '+(Number(value)?'checked':'')+'> '+label+'</label></div>';
  const pick = (name,label,value,items) => '<div class="field"><label for="edit-'+name+'">'+label+'</label><select id="edit-'+name+'" name="'+name+'">'+items.map(x => '<option value="'+escaped(x.id)+'" '+(String(x.id)===String(value)?'selected':'')+'>'+escaped(x.name)+'</option>').join('')+'</select></div>';
  const statuses = values => values.map(value => ({id:value,name:value}));
  const segments = (name,label,value,items) => '<fieldset class="field segment-field"><legend>'+label+'</legend><div class="segmented">'+items.map(([id,text,tone])=>'<label class="segment segment--'+tone+'"><input type="radio" name="'+name+'" value="'+escaped(id)+'" '+(String(id)===String(value)?'checked':'')+' required><span>'+text+'</span></label>').join('')+'</div></fieldset>';

  function bindSaveChangesState(form) {
    const save = form.querySelector('[data-save-changes]');
    if (!save) return;
    const signature = () => [...new FormData(form).entries()].map(([key, value]) => key+'='+value).sort().join('&');
    const initial = signature();
    const refresh = () => { save.disabled = signature() === initial; };
    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    document.querySelectorAll('[form="'+form.id+'"]').forEach(control => {
      control.addEventListener('input', refresh);
      control.addEventListener('change', refresh);
    });
    form.addEventListener('reset', () => setTimeout(refresh, 0));
    refresh();
  }

  function newcomerSlaState(createdAt, passwords) {
    if (String(passwords || '').toLowerCase() === 'both' || !createdAt) return 'ok';
    const start = new Date(String(createdAt).replace(' ', 'T'));
    const now = new Date();
    if (Number.isNaN(start.getTime()) || start >= now) return 'ok';
    let cursor = new Date(start);
    let elapsed = 0;
    while (cursor < now) {
      const dayEnd = new Date(cursor);
      dayEnd.setHours(24, 0, 0, 0);
      const segmentEnd = dayEnd < now ? dayEnd : now;
      if (cursor.getDay() !== 0 && cursor.getDay() !== 6) elapsed += segmentEnd - cursor;
      cursor = segmentEnd;
    }
    return elapsed >= 36 * 60 * 60 * 1000 ? 'expired' : (elapsed >= 30 * 60 * 60 * 1000 ? 'warning' : 'ok');
  }

  function bindNewcomerSlaWarning(form, createdAt) {
    const warning = document.createElement('div');
    warning.className = 'form-validation-error newcomer-sla-warning';
    form.prepend(warning);
    const refresh = () => {
      const passwords = form.querySelector('[name="passwords_received"]:checked')?.value;
      const requestedAt = document.querySelector('[form="'+form.id+'"][name="requested_datetime"]')?.value || createdAt;
      const state = newcomerSlaState(requestedAt, passwords);
      warning.hidden = state === 'ok';
      warning.textContent = state === 'expired'
        ? 'SLA exceeded: more than 36 business hours have elapsed without receiving both passwords.'
        : 'SLA warning: the 36-business-hour deadline for receiving both passwords is approaching.';
    };
    form.querySelectorAll('[name="passwords_received"]').forEach(control => control.addEventListener('change', refresh));
    document.querySelector('[form="'+form.id+'"][name="requested_datetime"]')?.addEventListener('change', refresh);
    refresh();
  }

  function accessRequestSlaState(createdAt, accessGranted, status) {
    if (accessGranted || status === 'Revoked' || !createdAt) return 'ok';
    const start = new Date(String(createdAt).replace(' ', 'T'));
    const now = new Date();
    if (Number.isNaN(start.getTime()) || start >= now) return 'ok';
    let cursor = new Date(start);
    let elapsed = 0;
    while (cursor < now) {
      const dayEnd = new Date(cursor);
      dayEnd.setHours(24, 0, 0, 0);
      const segmentEnd = dayEnd < now ? dayEnd : now;
      if (cursor.getDay() !== 0 && cursor.getDay() !== 6) elapsed += segmentEnd - cursor;
      cursor = segmentEnd;
    }
    return elapsed >= 108 * 60 * 60 * 1000 ? 'expired' : (elapsed >= 102 * 60 * 60 * 1000 ? 'warning' : 'ok');
  }

  function bindAccessRequestSlaWarning(form, createdAt, status) {
    const warning = document.createElement('div');
    warning.className = 'form-validation-error access-request-sla-warning';
    form.prepend(warning);
    const refresh = () => {
      const granted = Boolean(form.querySelector('[name="access_granted"]')?.checked);
      const requestedAt = document.querySelector('[form="'+form.id+'"][name="requested_datetime"]')?.value || createdAt;
      const state = accessRequestSlaState(requestedAt, granted, status);
      warning.hidden = state === 'ok';
      warning.textContent = state === 'expired'
        ? 'SLA exceeded: more than 108 business hours have elapsed without granting access.'
        : 'SLA warning: the 108-business-hour deadline for granting access is approaching.';
    };
    form.querySelector('[name="access_granted"]')?.addEventListener('change', refresh);
    document.querySelector('[form="'+form.id+'"][name="requested_datetime"]')?.addEventListener('change', refresh);
    refresh();
  }

  function editFields(table, r, data) {
    if (table === 'teams') return field('name','Team name',r.name,'text',true)+field('lead','Team lead',r.lead)+field('primark_manager','Primark Manager',r.primark_manager)+area('notes','Notes',r.notes);
    if (table === 'applications') return field('name','Application name',r.name,'text',true)+field('approver','Business approver',r.approver)+field('backup_approver','Backup approver',r.backup_approver)+field('ad_group','AD group',r.ad_group)+check('levy_copy','Copy Philip Levy / Emma Glennon',r.levy_copy)+check('enabled','Application enabled',r.enabled)+area('comment','Comment',r.comment);
    if (table === 'access_rules') return pick('team_id','Team',r.team_id,data.teams)+pick('application_id','Application',r.application_id,data.applications)+area('mirror_id','Mirror ID',r.mirror_id)+area('justification','Business justification',r.justification);
    if (table === 'newcomers') return field('full_name','First & Last user name',r.full_name,'text',true)+pick('team_id','Team',r.team_id,data.teams)+field('email','Primark email',r.email,'email')+field('manager','Primark manager',r.manager)
      +'<div class="newcomer-access-status-row">'
      +segments('passwords_received','Passwords received',r.passwords_received ?? 'None', [['None','None','red'],['TAP','TAP','orange'],['Network','Network','orange'],['Both','Both','green']])
      +segments('user_confirmed_access','User confirmed access',r.user_confirmed_access ?? 0, [['0','No','red'],['1','Yes','green']])
      +'</div><div class="newcomer-status-field">'+pick('status','Status',r.status,statuses(['Account pending','Passwords requested','App access requested','In progress','Complete']))+'</div>'
      +check('account_requested','Account requested',r.account_requested)+area('comment','Comment',r.comment);
    if (table === 'access_requests') return '<div class="access-request-status-row">'+pick('approval_status','Access request status',r.approval_status,statuses(['Not requested','No approval required','Requested','Approved','Rejected','Revoked']))+check('access_granted','Access granted',r.access_granted)+check('user_confirmation','User confirmation',r.user_confirmation)+'</div>'
      +'<div class="access-request-ticket-row">'+field('snow_ticket','ServiceNow ticket number',r.snow_ticket)+field('snow_ticket_url','ServiceNow ticket URL',r.snow_ticket_url,'url')+'</div>'
      +area('justification','Business justification',r.justification)+area('comments','Comments',r.comments);
    throw Error('Unknown table');
  }

  async function openRecordEditor(table, id, label, trigger) {
      const modal = openModal(table === 'newcomers' ? 'Newcomer '+label : 'Edit: '+label, trigger);
      modal.body.innerHTML = '<p role="status">Loading record…</p>';
      try {
        const response = await fetch('?table_json='+encodeURIComponent(table), {cache:'no-store'});
        if (!response.ok) throw Error('Could not load record');
        const data = await response.json();
        // Match the primary key, never the visual row position or sort order.
        const record = data.rows.find(row => String(row.id) === id);
        if (!record) throw Error('Record no longer exists');
        if (!modal.shade.isConnected) return;
        if (table === 'access_requests' || table === 'newcomers') {
          const formId = (table === 'access_requests' ? 'access-request-form-' : 'newcomer-form-') + escaped(id);
          modal.shade.querySelector('#modal-title').textContent = 'Access request to the '+(record.app || 'Application')+' for '+(record.full_name || 'User');
          if (table === 'newcomers') modal.shade.querySelector('#modal-title').textContent = 'Newcomer '+(record.full_name || 'User');
          const dateValue = String(record.created_at || '').replace(' ', 'T').slice(0, 16);
          const dateInputId = table === 'access_requests' ? 'edit-requested-datetime' : 'edit-newcomer-datetime';
          modal.shade.querySelector('.modal-close').outerHTML = '<div class="modal-requested-datetime"><label for="'+dateInputId+'">Requested dateTime</label><input id="'+dateInputId+'" type="datetime-local" name="requested_datetime" form="'+formId+'" value="'+escaped(dateValue)+'"></div>';
        }
        const recordActions = table === 'newcomers'
          ? '<button class="btn" data-save-changes>Save changes</button><button type="button" class="btn secondary" data-cancel>Cancel</button><button class="btn access-request-button" type="submit" name="open_access_request" value="1">Add applications access request</button>'
          : table === 'access_requests'
            ? '<button class="btn">Save changes</button><button type="button" class="btn secondary" data-cancel>Cancel</button>'+((record.approval_status === 'Revoked') ? '<button class="btn danger remove-revoked-request" type="submit" name="remove_revoked_request" value="1">Remove</button>' : '')
            : '<button class="btn">Save changes</button><button type="button" class="btn secondary" data-cancel>Cancel</button>';
        modal.body.innerHTML = '<form method="post"'+((table === 'access_requests' || table === 'newcomers') ? ' id="'+(table === 'access_requests' ? 'access-request-form-' : 'newcomer-form-')+escaped(id)+'"' : '')+'><input type="hidden" name="action" value="update_record"><input type="hidden" name="page" value="'+escaped(page)+'"><input type="hidden" name="table" value="'+escaped(table)+'"><input type="hidden" name="id" value="'+escaped(id)+'"><div class="form-grid">'+editFields(table,record,data)+'</div><div class="actions">'+recordActions+'</div></form>';
        modal.body.querySelector('[data-cancel]').onclick = modal.close;
        if (table === 'newcomers') {
          const form = modal.body.querySelector('form');
          bindAccountVisibility(form);
          bindUserConfirmationGate(form);
          bindCompletionStatus(form);
          bindNewcomerDuplicateCheck(form, id);
          bindSaveChangesState(form);
          bindNewcomerSlaWarning(form, record.created_at);
        }
        if (table === 'access_requests') {
          const form = modal.body.querySelector('form');
          bindAccessRequestSlaWarning(form, record.created_at, record.approval_status);
          form.addEventListener('submit', () => {
            sessionStorage.setItem('access-portal-requests-scroll', String(window.scrollY));
            sessionStorage.setItem('access-portal-requests-filter', document.querySelector('[data-request-search]')?.value || '');
          });
          form.querySelector('[name="remove_revoked_request"]')?.addEventListener('click', event => {
            if (!window.confirm('Remove this revoked access request? This action cannot be undone.')) event.preventDefault();
          });
        }
        modal.body.querySelector('input:not([type=hidden]),select,textarea')?.focus();
      } catch {
        modal.body.innerHTML = '<p class="ui-error" role="alert">Unable to load this record. Close the dialog and try again.</p>';
      }
  }

  document.querySelectorAll('[data-edit-table]').forEach(button => {
    button.addEventListener('click', () => openRecordEditor(button.dataset.editTable, button.dataset.recordId, button.dataset.recordLabel, button));
  });

  async function openInstructionEditor(id, trigger) {
    const template = document.querySelector('#create-instructions-form');
    if (!template) return;
    const modal = openModal('Edit: '+trigger.dataset.recordLabel, trigger, 'modal-box--instruction');
    modal.body.innerHTML = '<p role="status">Loading instruction…</p>';
    try {
      const response = await fetch('?instruction_json='+encodeURIComponent(id), {cache:'no-store'});
      if (!response.ok) throw Error('Could not load instruction');
      const data = await response.json();
      if (!data.id) throw Error('Instruction no longer exists');
      if (!modal.shade.isConnected) return;
      modal.body.append(template.content.cloneNode(true));
      const form = modal.body.querySelector('form');
      form.querySelector('[name="action"]').value = 'update_instruction';
      form.insertAdjacentHTML('afterbegin', '<input type="hidden" name="id" value="'+Number(data.id)+'">');
      form.querySelector('[name="application_id"]').value = data.application_id || '';
      form.querySelector('[name="title"]').value = data.title || '';
      form.querySelector('[name="content"]').value = data.content || '';
      form.querySelector('.actions .btn').textContent = 'Save instruction';
      form.querySelector('[data-cancel]').textContent = 'Cancel';
      form.querySelector('[data-cancel]').onclick = modal.close;
      form.querySelectorAll('textarea[name="content"]').forEach(initialiseRichEditor);
      form.querySelector('[name="title"]')?.focus();
    } catch {
      modal.body.innerHTML = '<p class="ui-error" role="alert">Unable to load this instruction. Close the dialog and try again.</p>';
    }
  }

  document.querySelectorAll('[data-edit-instruction]').forEach(button => {
    button.addEventListener('click', () => openInstructionEditor(button.dataset.editInstruction, button));
  });

  document.querySelectorAll('tr[data-open-table]').forEach(row => {
    const open = () => {
      if (row.dataset.openTable === 'instructions') openInstructionEditor(row.dataset.recordId, row);
      else openRecordEditor(row.dataset.openTable, row.dataset.recordId, row.dataset.recordLabel, row);
    };
    row.addEventListener('click', event => {
      if (event.target.closest('button,a,input,select,textarea,label,summary,details,.actions-cell')) return;
      open();
    });
    row.addEventListener('keydown', event => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      open();
    });
  });

  document.querySelectorAll('[data-instruction-id]').forEach(button => {
    button.addEventListener('click', async () => {
      const modal = openModal(button.textContent, button, 'modal-box--instruction');
      modal.body.innerHTML = '<p role="status">Loading instruction…</p>';
      try {
        const response = await fetch('?instruction_json='+encodeURIComponent(button.dataset.instructionId), {cache:'no-store'});
        if (!response.ok) throw Error('Could not load instruction');
        const data = await response.json();
        if (!data.id) throw Error('Instruction no longer exists');
        if (!modal.shade.isConnected) return;
        modal.body.innerHTML = '<div class="instruction-body">'+clean(data.content||'')+'</div><div class="actions"><button type="button" class="btn" data-edit-instruction="'+Number(data.id)+'" data-record-label="'+escaped(data.title||button.textContent)+'">Edit instruction</button></div>';
        modal.body.querySelector('[data-edit-instruction]').onclick = () => { modal.close(); openInstructionEditor(data.id, button); };
      } catch {
        modal.body.innerHTML = '<p class="ui-error" role="alert">Unable to load this instruction. Close the dialog and try again.</p>';
      }
    });
  });

  const instructionTable = document.querySelector('.table--instructions');
  if (instructionTable) {
    const body = instructionTable.tBodies[0];
    const rows = [...body.querySelectorAll('tr[data-instruction]')];
    const search = document.querySelector('[data-instruction-search]');
    const sortButtons = [...document.querySelectorAll('[data-instruction-sort]')];
    let sortKey = 'instruction';
    let sortDirection = 'ascending';

    const applySearch = () => {
      const query = (search?.value || '').trim().toLocaleLowerCase();
      rows.forEach(row => { row.hidden = query !== '' && !row.dataset.search.toLocaleLowerCase().includes(query); });
    };
    const applySort = () => {
      const factor = sortDirection === 'ascending' ? 1 : -1;
      rows.sort((a,b) => a.dataset[sortKey].localeCompare(b.dataset[sortKey], undefined, {sensitivity:'base', numeric:true}) * factor)
        .forEach(row => body.append(row));
      sortButtons.forEach(button => {
        const active = button.dataset.instructionSort === sortKey;
        button.dataset.sortDirection = active ? sortDirection : '';
        button.closest('th').setAttribute('aria-sort', active ? sortDirection : 'none');
      });
    };
    search?.addEventListener('input', applySearch);
    sortButtons.forEach(button => button.addEventListener('click', () => {
      const key = button.dataset.instructionSort;
      sortDirection = key === sortKey && sortDirection === 'ascending' ? 'descending' : 'ascending';
      sortKey = key;
      applySort();
    }));
    applySort();
    applySearch();
  }

  const teamTable = document.querySelector('.table--teams');
  if (teamTable) {
    const body = teamTable.tBodies[0];
    const rows = [...body.querySelectorAll('tr[data-team]')];
    const search = document.querySelector('[data-team-search]');
    const sortButtons = [...document.querySelectorAll('[data-team-sort]')];
    let sortKey = 'team';
    let sortDirection = 'ascending';
    const applySearch = () => {
      const query = (search?.value || '').trim().toLocaleLowerCase();
      rows.forEach(row => { row.hidden = query !== '' && !row.dataset.search.toLocaleLowerCase().includes(query); });
    };
    const applySort = () => {
      const factor = sortDirection === 'ascending' ? 1 : -1;
      rows.sort((a,b) => a.dataset[sortKey].localeCompare(b.dataset[sortKey], undefined, {sensitivity:'base', numeric:true}) * factor)
        .forEach(row => body.append(row));
      sortButtons.forEach(button => {
        const active = button.dataset.teamSort === sortKey;
        button.dataset.sortDirection = active ? sortDirection : '';
        button.closest('th').setAttribute('aria-sort', active ? sortDirection : 'none');
      });
    };
    search?.addEventListener('input', applySearch);
    sortButtons.forEach(button => button.addEventListener('click', () => {
      const key = button.dataset.teamSort;
      sortDirection = key === sortKey && sortDirection === 'ascending' ? 'descending' : 'ascending';
      sortKey = key;
      applySort();
    }));
    applySort();
    applySearch();
  }

  const applicationTable = document.querySelector('.table--applications');
  if (applicationTable) {
    const body = applicationTable.tBodies[0];
    const rows = [...body.querySelectorAll('tr[data-application]')];
    const search = document.querySelector('[data-application-search]');
    const sortButtons = [...document.querySelectorAll('[data-application-sort]')];
    let sortKey = 'application';
    let sortDirection = 'ascending';
    const applySearch = () => {
      const query = (search?.value || '').trim().toLocaleLowerCase();
      rows.forEach(row => { row.hidden = query !== '' && !row.dataset.search.toLocaleLowerCase().includes(query); });
    };
    const applySort = () => {
      const factor = sortDirection === 'ascending' ? 1 : -1;
      rows.sort((a,b) => a.dataset[sortKey].localeCompare(b.dataset[sortKey], undefined, {sensitivity:'base', numeric:true}) * factor)
        .forEach(row => body.append(row));
      sortButtons.forEach(button => {
        const active = button.dataset.applicationSort === sortKey;
        button.dataset.sortDirection = active ? sortDirection : '';
        button.closest('th').setAttribute('aria-sort', active ? sortDirection : 'none');
      });
    };
    search?.addEventListener('input', applySearch);
    sortButtons.forEach(button => button.addEventListener('click', () => {
      const key = button.dataset.applicationSort;
      sortDirection = key === sortKey && sortDirection === 'ascending' ? 'descending' : 'ascending';
      sortKey = key;
      applySort();
    }));
    applySort();
    applySearch();
  }

  const ruleTable = document.querySelector('.table--rules');
  if (ruleTable) {
    const body = ruleTable.tBodies[0];
    const rows = [...body.querySelectorAll('tr[data-team][data-application]')];
    const search = document.querySelector('[data-rule-search]');
    const sortButtons = [...document.querySelectorAll('[data-rule-sort]')];
    let sortKey = 'team';
    let sortDirection = 'ascending';
    const applySearch = () => {
      const query = (search?.value || '').trim().toLocaleLowerCase();
      rows.forEach(row => { row.hidden = query !== '' && !row.dataset.search.toLocaleLowerCase().includes(query); });
    };
    const applySort = () => {
      const factor = sortDirection === 'ascending' ? 1 : -1;
      rows.sort((a,b) => {
        const primary = a.dataset[sortKey].localeCompare(b.dataset[sortKey], undefined, {sensitivity:'base', numeric:true});
        const secondaryKey = sortKey === 'team' ? 'application' : 'team';
        return (primary || a.dataset[secondaryKey].localeCompare(b.dataset[secondaryKey], undefined, {sensitivity:'base', numeric:true})) * factor;
      }).forEach(row => body.append(row));
      sortButtons.forEach(button => {
        const active = button.dataset.ruleSort === sortKey;
        button.dataset.sortDirection = active ? sortDirection : '';
        button.closest('th').setAttribute('aria-sort', active ? sortDirection : 'none');
      });
    };
    search?.addEventListener('input', applySearch);
    sortButtons.forEach(button => button.addEventListener('click', () => {
      const key = button.dataset.ruleSort;
      sortDirection = key === sortKey && sortDirection === 'ascending' ? 'descending' : 'ascending';
      sortKey = key;
      applySort();
    }));
    applySort();
    applySearch();
  }

  const coverageMatrix = document.querySelector('[data-coverage-matrix]');
  if (coverageMatrix) {
    const headerRow = coverageMatrix.tHead.rows[0];
    const body = coverageMatrix.tBodies[0];
    const originalApplications = [...headerRow.querySelectorAll('[data-matrix-app-header]')].map(cell => cell.dataset.matrixAppId);
    const originalTeams = [...body.querySelectorAll('tr[data-matrix-team-id]')].map(row => row.dataset.matrixTeamId);
    let activeTeam = null;
    let activeApplication = null;
    const rowFor = id => body.querySelector('tr[data-matrix-team-id="'+id+'"]');
    const cellFor = (row, appId) => row?.querySelector('td[data-matrix-app-id="'+appId+'"]');
    const setActive = () => {
      coverageMatrix.querySelectorAll('[data-matrix-team]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.matrixTeam === activeTeam)));
      coverageMatrix.querySelectorAll('[data-matrix-application]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.matrixApplication === activeApplication)));
    };
    const orderColumns = ids => {
      headerRow.append(...ids.map(id => headerRow.querySelector('[data-matrix-app-header][data-matrix-app-id="'+id+'"]')));
      originalTeams.forEach(teamId => {
        const row = rowFor(teamId);
        row.append(...ids.map(id => cellFor(row, id)));
      });
    };
    const orderRows = ids => body.append(...ids.map(rowFor));
    coverageMatrix.querySelectorAll('[data-matrix-team]').forEach(button => button.addEventListener('click', () => {
      const teamId = button.dataset.matrixTeam;
      if (activeTeam === teamId) {
        activeTeam = null;
        orderColumns(originalApplications);
      } else {
        activeTeam = teamId;
        const row = rowFor(teamId);
        const ordered = [...originalApplications].sort((a,b) => Number(cellFor(row, b).classList.contains('matrix-hit')) - Number(cellFor(row, a).classList.contains('matrix-hit')));
        orderColumns(ordered);
      }
      setActive();
    }));
    coverageMatrix.querySelectorAll('[data-matrix-application]').forEach(button => button.addEventListener('click', () => {
      const appId = button.dataset.matrixApplication;
      if (activeApplication === appId) {
        activeApplication = null;
        orderRows(originalTeams);
      } else {
        activeApplication = appId;
        const ordered = [...originalTeams].sort((a,b) => Number(cellFor(rowFor(b), appId).classList.contains('matrix-hit')) - Number(cellFor(rowFor(a), appId).classList.contains('matrix-hit')));
        orderRows(ordered);
      }
      setActive();
    }));
    setActive();
  }

  const requestTable = document.querySelector('.table--requests');
  if (requestTable) {
    const rows = [...requestTable.tBodies[0].querySelectorAll('tr[data-request]')];
    const search = document.querySelector('[data-request-search]');
    const applySearch = () => {
      const query = search.value.trim().toLocaleLowerCase();
      requestTable.classList.toggle('is-filtering', query !== '');
      rows.forEach(row => { row.hidden = query !== '' && !row.dataset.search.toLocaleLowerCase().includes(query); });
    };
    const savedFilter = sessionStorage.getItem('access-portal-requests-filter');
    if (search && savedFilter !== null) {
      search.value = savedFilter;
      sessionStorage.removeItem('access-portal-requests-filter');
    }
    search?.addEventListener('input', applySearch);
    applySearch();
  }

  document.querySelectorAll('form').forEach(form => {
    const action = form.querySelector('input[name="action"]');
    if (action?.value !== 'delete') return;
    form.addEventListener('submit', event => {
      const label = form.querySelector('button')?.getAttribute('aria-label')?.replace(/^Remove\s+/i, '') || 'this record';
      if (!window.confirm(`Remove ${label}? This action cannot be undone.`)) event.preventDefault();
    });
  });
});
