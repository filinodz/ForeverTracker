/* Forever Tracker — navigation, theme, command palette, filters and the Forge. */
(function () {
  'use strict';
  const FT = window.FT || { base: '/', lang: 'en', csrf: '' };
  const fr = FT.lang === 'fr';
  const L = (en, frText) => (fr ? frText : en);
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---------- sidebar (mobile) and theme
  const side = $('#side'), scrim = $('.scrim');
  const setSide = (open) => { side && side.classList.toggle('is-open', open); scrim && scrim.classList.toggle('is-open', open); document.body.style.overflow = open ? 'hidden' : ''; };
  $$('[data-side-open]').forEach((b) => b.addEventListener('click', () => setSide(true)));
  $$('[data-side-close]').forEach((b) => b.addEventListener('click', () => setSide(false)));
  $$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('ft-theme', next); } catch (e) { /* private mode */ }
  }));

  // ---------- filters submit themselves
  $$('[data-autosubmit-form]').forEach((form) => {
    form.addEventListener('change', (event) => { if (event.target.matches('[data-autosubmit]')) form.requestSubmit ? form.requestSubmit() : form.submit(); });
    const search = $('input[name="q"]', form);
    let timer = 0;
    if (search) search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => form.submit(), 650); });
    form.addEventListener('submit', () => { $$('input, select', form).forEach((field) => { if ((field.type !== 'checkbox' && field.type !== 'radio' && field.value === '') || (field.type === 'radio' && field.checked && field.value === '')) field.disabled = true; }); });
  });

  // ---------- animated numbers
  $$('[data-count]').forEach((el) => {
    const target = Number(el.getAttribute('data-count')) || 0;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const start = performance.now();
    const tick = (now) => { const p = Math.min(1, (now - start) / 1100); el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(tick); };
    requestAnimationFrame(tick);
  });

  // ---------- command palette
  const palette = $('#palette');
  if (palette) {
    const input = $('input', palette), results = $('.palette-results', palette), all = $('[data-palette-all]', palette);
    results.setAttribute('data-empty', L('Type a name or an ID', 'Tapez un nom ou un ID'));
    let items = [], active = 0, timer = 0, request = 0;
    const open = () => { palette.hidden = false; input.value = ''; results.innerHTML = ''; setTimeout(() => input.focus(), 10); setSide(false); };
    const close = () => { palette.hidden = true; };
    const draw = () => {
      results.innerHTML = items.map((x, i) => `<a class="palette-item ${i === active ? 'is-active' : ''}" href="${esc(x.url)}" role="option" data-tt="${esc(x.type + ':' + x.id)}">
        <span class="ic q${x.quality >= 0 ? x.quality : ''}">${x.icon ? `<img src="${esc(x.icon)}" alt="" onerror="this.remove()">` : ''}<i class="fas ${esc(x.glyph)}"></i></span>
        <div><strong class="${x.type === 'item' ? 'q' + x.quality : ''}">${esc(x.name)}</strong><small>${esc(x.typeLabel)} · ID ${x.id}${x.other && x.other !== x.name ? ' · ' + esc(x.other) : ''}</small></div>
        <span class="badge is-${esc(x.status)}"><i></i>${esc(x.statusLabel)}</span></a>`).join('');
      const current = $('.is-active', results);
      if (current) current.scrollIntoView({ block: 'nearest' });
    };
    const search = () => {
      const q = input.value.trim();
      all.href = `${FT.base}search?q=${encodeURIComponent(q)}`;
      if (!q) { items = []; draw(); return; }
      const id = ++request;
      fetch(`${FT.base}api/search?q=${encodeURIComponent(q)}&lang=${FT.lang}`).then((r) => r.json()).then((data) => {
        if (id !== request) return;
        items = data.results || []; active = 0;
        if (!items.length) results.setAttribute('data-empty', L('No resource found', 'Aucune ressource trouvée'));
        draw();
      }).catch(() => {});
    };
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 140); });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown') { active = Math.min(items.length - 1, active + 1); draw(); event.preventDefault(); }
      else if (event.key === 'ArrowUp') { active = Math.max(0, active - 1); draw(); event.preventDefault(); }
      else if (event.key === 'Enter') { event.preventDefault(); location.href = items[active] ? items[active].url : `${FT.base}search?q=${encodeURIComponent(input.value.trim())}`; }
    });
    palette.addEventListener('click', (event) => { if (event.target === palette) close(); });
    $$('[data-palette-open]').forEach((b) => b.addEventListener('click', open));
    document.addEventListener('keydown', (event) => {
      const typing = /input|textarea|select/i.test((event.target.tagName || '')) || event.target.isContentEditable;
      if ((event.key === 'k' && (event.ctrlKey || event.metaKey)) || (event.key === '/' && !typing)) { event.preventDefault(); open(); }
      else if (event.key === 'Escape') { close(); setSide(false); $$('.modal').forEach((m) => { m.hidden = true; }); }
    });
  }

  // ---------- toasts and modals
  const toast = (message, kind) => {
    const box = $('#toasts');
    if (!box) { alert(message); return; }
    const el = document.createElement('div');
    el.className = `toast is-${kind || 'ok'}`;
    el.innerHTML = `<i class="fas ${kind === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i><div>${message}</div>`;
    box.appendChild(el);
    setTimeout(() => el.remove(), kind === 'error' ? 9000 : 6000);
  };
  $$('.modal').forEach((modal) => {
    modal.addEventListener('click', (event) => { if (event.target === modal || event.target.closest('[data-modal-close]')) modal.hidden = true; });
  });

  // ---------- the Forge
  const api = (body) => fetch(`${FT.base}api/forge?lang=${FT.lang}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': FT.csrf }, body: JSON.stringify(body),
  }).then((r) => r.json().then((data) => { if (!r.ok) throw new Error(data.error || r.statusText); return data; }));

  const preview = $('#forge-preview'), confirmBox = $('#forge-confirm');
  let pending = null;  // {items, vendor, push}
  let sql = { apply: '', rollback: '' }, sqlPart = 'apply';

  const showSql = () => { $('[data-sql]', preview).textContent = sql[sqlPart] || ''; $$('[data-sql-tab]', preview).forEach((b) => b.classList.toggle('is-active', b.dataset.sqlTab === sqlPart)); };
  const download = (content, name) => {
    const url = URL.createObjectURL(new Blob([content], { type: 'application/sql' }));
    const a = Object.assign(document.createElement('a'), { href: url, download: name });
    document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  };

  function openPreview(selection) {
    pending = selection;
    return api({ action: 'preview', ...selection }).then((data) => {
      sql = { apply: data.apply, rollback: data.rollback }; sqlPart = 'apply';
      $('[data-forge-warnings]', preview).innerHTML = (data.warnings || []).map((w) => `<div class="alert"><i class="fas fa-triangle-exclamation"></i><div>${esc(w)}</div></div>`).join('');
      $('[data-sql-meta]', preview).textContent = `${data.count} ${L('fix(es)', 'correction(s)')} · ${data.rows} ${L('new rows', 'nouvelles lignes')}`
        + (data.skipped ? ` · ${data.skipped} ${L('already present', 'déjà présentes')}` : '') + (data.databases && data.databases.length ? ` · ${data.databases.join(', ')}` : '');
      showSql();
      preview.hidden = false;
      return data;
    }).catch((e) => toast(esc(e.message), 'error'));
  }
  function openConfirm(selection, count) {
    pending = selection;
    $('[data-confirm-text]', confirmBox).textContent = fmt(L('%d fix(es) will be written to your server databases.', '%d correction(s) vont être écrites dans les bases de votre serveur.'), count);
    $('[data-confirm-check]', confirmBox).checked = false;
    $('[data-confirm-go]', confirmBox).disabled = true;
    preview && (preview.hidden = true);
    confirmBox.hidden = false;
  }
  const fmt = (pattern, ...args) => { let i = 0; return pattern.replace(/%[sd]/g, () => args[i++]); };

  if (preview) {
    $$('[data-sql-tab]', preview).forEach((b) => b.addEventListener('click', () => { sqlPart = b.dataset.sqlTab; showSql(); }));
    $('[data-sql-copy]', preview).addEventListener('click', () => navigator.clipboard.writeText(sql[sqlPart]).then(() => toast(L('Copied.', 'Copié.'))));
    $('[data-sql-download]', preview).addEventListener('click', () => download(sql[sqlPart], `forever-forge-${sqlPart}.sql`));
    $('[data-sql-apply]', preview).addEventListener('click', () => pending && openConfirm(pending, pending.items.length));
  }
  if (confirmBox) {
    const check = $('[data-confirm-check]', confirmBox), go = $('[data-confirm-go]', confirmBox);
    check.addEventListener('change', () => { go.disabled = !check.checked; });
    go.addEventListener('click', () => {
      if (!pending) return;
      go.disabled = true;
      go.innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${L('Applying…', 'Application…')}`;
      api({ action: 'apply', confirm: true, ...pending }).then((data) => {
        confirmBox.hidden = true;
        if (data.ok && !data.log) {
          toast(esc(data.message || ''));
          markApplied(data.items || []);
        } else if (data.ok) {
          toast(fmt(L('Applied: %d row(s) written (operation #%d). Restart the worldserver, then run the analysis again.', 'Appliqué : %d ligne(s) écrite(s) (opération n°%d). Redémarrez le worldserver puis relancez l’analyse.'), data.rows, data.log) +
            (data.warnings && data.warnings.length ? '<br><small>' + esc(data.warnings.join(' ')) + '</small>' : ''));
          markApplied(data.items || []);
        } else {
          toast(esc(L('Nothing was changed: ', 'Rien n’a été modifié : ') + (data.message || '')), 'error');
        }
      }).catch((e) => toast(esc(e.message), 'error')).finally(() => { go.innerHTML = `<i class="fas fa-bolt"></i> ${L('Apply now', 'Appliquer maintenant')}`; });
    });
  }
  function markApplied(items) {
    const keys = new Set(items.map((x) => `${x.type}:${x.id}:${x.kind}`));
    $$('[data-pick]').forEach((box) => {
      const p = JSON.parse(box.dataset.pick);
      if (keys.has(`${p.type}:${p.id}:${p.kind}`)) { box.checked = false; box.disabled = true; box.closest('tr').classList.add('is-done'); }
    });
    $$('[data-fix]').forEach((card) => {
      const p = JSON.parse(card.dataset.fix);
      if (keys.has(`${p.type}:${p.id}:${p.kind}`)) $$('button', card).forEach((b) => { b.disabled = true; });
    });
    updateBar();
  }

  // single fix cards (resource page)
  $$('[data-fix]').forEach((card) => {
    const fix = JSON.parse(card.dataset.fix);
    const preview = $('[data-forge-preview]', card), apply = $('[data-forge-apply]', card);
    if (preview) preview.addEventListener('click', () => openPreview({ items: [fix] }));
    if (apply) apply.addEventListener('click', () => openConfirm({ items: [fix] }, 1));
  });

  // bulk selection (Forge page)
  const bar = $('[data-action-bar]'), table = $('[data-forge-table]');
  let vendor = 0;
  const selected = () => $$('[data-pick]:checked').map((b) => JSON.parse(b.dataset.pick));
  const selection = () => ({ items: selected(), vendor, push: !!($('[data-push]') && $('[data-push]').checked) });
  function updateBar() {
    if (!bar) return;
    const n = selected().length;
    bar.hidden = n === 0;
    $('[data-selected-count]', bar).textContent = n;
  }
  if (table) {
    table.addEventListener('change', (event) => {
      if (event.target.matches('[data-select-all]')) $$('[data-pick]:not(:disabled)', table).forEach((b) => { b.checked = event.target.checked; });
      updateBar();
    });
    const needsVendor = () => table.dataset.tab === 'vendor' && !vendor;
    $('[data-forge-preview-selection]', bar).addEventListener('click', () => { if (needsVendor()) return toast(L('Choose a vendor first.', 'Choisissez d’abord un marchand.'), 'error'); openPreview(selection()); });
    $('[data-forge-download]', bar).addEventListener('click', () => {
      if (needsVendor()) return toast(L('Choose a vendor first.', 'Choisissez d’abord un marchand.'), 'error');
      api({ action: 'preview', ...selection() }).then((data) => download(data.apply, 'forever-forge-apply.sql')).catch((e) => toast(esc(e.message), 'error'));
    });
    $('[data-forge-apply-selection]', bar).addEventListener('click', () => { if (needsVendor()) return toast(L('Choose a vendor first.', 'Choisissez d’abord un marchand.'), 'error'); openConfirm(selection(), selected().length); });
  }

  // vendor picker
  const picker = $('[data-vendor-pick]');
  if (picker) {
    const q = $('[data-vendor-q]', picker), list = $('[data-vendor-results]', picker), chosen = $('[data-vendor-chosen]', picker);
    let timer = 0;
    const choose = (v) => {
      vendor = v.entry;
      chosen.hidden = false; list.innerHTML = '';
      chosen.innerHTML = `<i class="fas fa-circle-check"></i><div><strong>${esc(v.name)} <span class="muted">#${v.entry}</span></strong><small>${esc(v.subname || '')} ${v.spawns ? fmt(L('· %d spawn(s)', '· %d placement(s)'), v.spawns) : L('· not spawned', '· non placé')} · ${fmt(L('%d item(s) sold', '%d objet(s) vendu(s)'), v.items)}</small></div><button class="btn is-small" type="button">${L('Change', 'Changer')}</button>`;
      $('button', chosen).addEventListener('click', () => { vendor = 0; chosen.hidden = true; q.focus(); });
    };
    const lookup = () => {
      const value = q.value.trim();
      if (!value) { list.innerHTML = ''; return; }
      api({ action: 'vendors', q: value }).then((data) => {
        list.innerHTML = (data.vendors || []).map((v, i) => `<button type="button" data-i="${i}"><i class="fas fa-store ${v.vendor ? 'is-added' : 'is-removed'}"></i><div><strong>${esc(v.name)} <span class="muted">#${v.entry}</span></strong><small>${v.spawns ? fmt(L('%d spawn(s)', '%d placement(s)'), v.spawns) : L('not spawned', 'non placé')} · ${fmt(L('%d item(s)', '%d objet(s)'), v.items)}${v.vendor ? '' : ' · ' + L('no vendor flag', 'pas marchand')}</small></div></button>`).join('')
          || `<p class="muted small">${L('No vendor found.', 'Aucun marchand trouvé.')}</p>`;
        $$('button', list).forEach((b) => b.addEventListener('click', () => choose(data.vendors[Number(b.dataset.i)])));
      }).catch((e) => toast(esc(e.message), 'error'));
    };
    q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(lookup, 250); });
    if (q.value) lookup();
  }

  // rollback (history)
  $$('[data-forge-rollback]').forEach((button) => button.addEventListener('click', () => {
    if (!confirm(L('Run the saved rollback of this operation?', 'Exécuter l’annulation enregistrée de cette opération ?'))) return;
    button.disabled = true;
    api({ action: 'rollback', log: Number(button.dataset.forgeRollback) }).then((data) => {
      if (data.ok) { toast(L('Rolled back.', 'Annulée.')); setTimeout(() => location.reload(), 900); }
      else { toast(esc(data.message || 'Error'), 'error'); button.disabled = false; }
    }).catch((e) => { toast(esc(e.message), 'error'); button.disabled = false; });
  }));

  // spawns (locator results)
  const spawnTable = $('[data-spawn-table]'), spawnBar = $('[data-spawn-bar]'), spawnDialog = $('#spawn-dialog');
  if (spawnTable && spawnBar && spawnDialog) {
    let everything = false;  // "All N": the page's filter instead of the ticked rows
    const picked = () => $$('[data-spawn-pick]:checked', spawnTable).map((b) => JSON.parse(b.dataset.spawnPick));
    const chosen = () => (everything ? { filter: JSON.parse(spawnTable.dataset.filter) } : { spawns: picked() });
    const refreshBar = () => {
      const n = everything ? Number(spawnTable.dataset.total) : picked().length;
      spawnBar.hidden = n === 0 && !$$('[data-spawn-pick]', spawnTable).length;
      $('[data-selected-count]', spawnBar).textContent = n;
      $('[data-spawn-open]', spawnBar).disabled = n === 0;
    };
    spawnTable.addEventListener('change', (event) => {
      if (event.target.matches('[data-select-all]')) $$('[data-spawn-pick]', spawnTable).forEach((b) => { b.checked = event.target.checked; });
      everything = false;
      refreshBar();
    });
    const all = $('[data-spawn-all]', spawnBar);
    if (all) all.addEventListener('click', () => { everything = true; $$('[data-spawn-pick]', spawnTable).forEach((b) => { b.checked = true; }); refreshBar(); });
    refreshBar();

    const setStatus = (el, ok, text) => { el.innerHTML = `<i class="fas ${ok ? 'fa-circle-check ok-text' : 'fa-circle-exclamation warn-text'}"></i> ${esc(text)}`; };
    const go = (mode) => $(`[data-spawn-go="${mode}"]`, spawnDialog);
    let check = null;
    $('[data-spawn-open]', spawnBar).addEventListener('click', () => {
      spawnDialog.hidden = false;
      $('[data-spawn-summary]', spawnDialog).textContent = L('Looking at the selection…', 'Examen de la sélection…');
      $('[data-spawn-warnings]', spawnDialog).innerHTML = '';
      $('[data-console-status]', spawnDialog).innerHTML = `<i class="fas fa-spinner fa-spin"></i> ${L('Checking the remote console…', 'Vérification de la console distante…')}`;
      go('server').disabled = true;
      api({ action: 'spawn_check', ...chosen() }).then((data) => {
        check = data;
        $('[data-spawn-summary]', spawnDialog).textContent = fmt(L('%d creature(s) or object(s): %d position(s) to spawn, %d to place by hand in dungeons.', '%d PNJ ou objet(s) : %d position(s) à placer, %d à placer à la main en donjon.'), data.entries, data.points, data.manual);
        $('[data-spawn-warnings]', spawnDialog).innerHTML = (data.warnings || []).map((w) => `<div class="alert"><i class="fas fa-triangle-exclamation"></i><div>${esc(w)}</div></div>`).join('');
        setStatus($('[data-console-status]', spawnDialog), data.console.ok, data.console.message);
        go('server').disabled = !data.console.ok || !data.points;
        const client = $('[data-client-status]', spawnDialog);
        if (data.client) { setStatus(client, true, fmt(L('Game client found: %s', 'Client du jeu trouvé : %s'), data.client)); go('install').hidden = false; }
        else { setStatus(client, false, L('Game client not configured ([client] path): download the addon and copy it into Interface/AddOns.', 'Client du jeu non configuré ([client] path) : téléchargez l’addon et copiez-le dans Interface/AddOns.')); go('install').hidden = true; }
        const dbStatus = $('[data-db-status]', spawnDialog);
        if (data.world_running) setStatus(dbStatus, false, L('The worldserver is running: stop it first (it hands out spawn ids itself).', 'Le worldserver tourne : arrêtez-le d’abord (il attribue lui-même les identifiants).'));
        else setStatus(dbStatus, data.database > 0, fmt(L('%d position(s) can be written, %d need the server or the addon.', '%d position(s) peuvent être écrites, %d nécessitent le serveur ou l’addon.'), data.database, data.database_left));
        go('database').disabled = data.world_running || !data.database;
      }).catch((e) => toast(esc(e.message), 'error'));
    });
    const busy = (button, on) => { button.disabled = on; button.classList.toggle('is-busy', on); };
    go('server').addEventListener('click', () => {
      if (!check || !confirm(fmt(L('Spawn %d position(s) on the running server now?', 'Placer %d position(s) sur le serveur maintenant ?'), check.points))) return;
      busy(go('server'), true);
      api({ action: 'spawn_server', confirm: true, ...chosen() }).then((data) => {
        if (data.ok) {
          toast(fmt(L('%d spawn(s) created (operation #%d).', '%d placement(s) créé(s) (opération n°%d).'), data.spawned, data.log) + (data.failed ? `<br><small>${esc(fmt(L('%d failed: ', '%d en échec : '), data.failed) + (data.errors || []).join(' · '))}</small>` : ''));
          spawnDialog.hidden = true;
        } else toast(esc(data.message || 'Error'), 'error');
      }).catch((e) => toast(esc(e.message), 'error')).finally(() => busy(go('server'), false));
    });
    go('database').addEventListener('click', () => {
      if (!check || !confirm(fmt(L('Write %d spawn(s) in the world database? Keep a backup; the rollback is saved in the history.', 'Écrire %d placement(s) dans la base world ? Gardez une sauvegarde ; l’annulation est enregistrée dans l’historique.'), check.database))) return;
      busy(go('database'), true);
      api({ action: 'spawn_database', confirm: true, ...chosen() }).then((data) => {
        if (data.ok) { toast(fmt(L('%d row(s) written (operation #%d). Start the worldserver to see them.', '%d ligne(s) écrite(s) (opération n°%d). Démarrez le worldserver pour les voir.'), data.rows, data.log)); spawnDialog.hidden = true; }
        else toast(esc(data.message || 'Error'), 'error');
      }).catch((e) => toast(esc(e.message), 'error')).finally(() => busy(go('database'), false));
    });
    go('sql').addEventListener('click', () => {
      api({ action: 'spawn_sql', ...chosen() }).then((data) => {
        sql = { apply: data.apply, rollback: data.rollback }; sqlPart = 'apply'; pending = null;
        $('[data-forge-warnings]', preview).innerHTML = (data.warnings || []).map((w) => `<div class="alert"><i class="fas fa-triangle-exclamation"></i><div>${esc(w)}</div></div>`).join('');
        $('[data-sql-meta]', preview).textContent = `${data.rows} ${L('new rows', 'nouvelles lignes')}`;
        $('[data-sql-apply]', preview).hidden = true;
        showSql(); preview.hidden = false;
      }).catch((e) => toast(esc(e.message), 'error'));
    });
    go('install').addEventListener('click', () => {
      busy(go('install'), true);
      api({ action: 'spawn_install', ...chosen() }).then((data) => toast(esc(data.message || ''), data.ok ? 'ok' : 'error')).catch((e) => toast(esc(e.message), 'error')).finally(() => busy(go('install'), false));
    });
    go('zip').addEventListener('click', () => {
      api({ action: 'spawn_zip', ...chosen() }).then((data) => {
        if (!data.ok) return toast(esc(data.message || ''), 'error');
        const bytes = Uint8Array.from(atob(data.zip), (c) => c.charCodeAt(0));
        const url = URL.createObjectURL(new Blob([bytes], { type: 'application/zip' }));
        const a = Object.assign(document.createElement('a'), { href: url, download: data.name });
        document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        toast(L('Unzip it into the game\'s Interface/AddOns folder, then /reload and /forge in game.', 'Décompressez-le dans le dossier Interface/AddOns du jeu, puis /reload et /forge en jeu.'));
      }).catch((e) => toast(esc(e.message), 'error'));
    });
    const commands = (asFile) => api({ action: 'spawn_commands', ...chosen() }).then((data) => {
      if (asFile) download(data.text, 'forever-spawns.txt');
      else navigator.clipboard.writeText(data.text).then(() => toast(L('Copied.', 'Copié.')));
    }).catch((e) => toast(esc(e.message), 'error'));
    go('commands').addEventListener('click', () => commands(false));
    go('commands-file').addEventListener('click', () => commands(true));
  }

  // background analysis
  const analysis = $('[data-analysis]');
  if (analysis) {
    const start = $('[data-analysis-start]', analysis), log = $('[data-analysis-log]', analysis), label = $('[data-analysis-text]', analysis);
    const poll = () => api({ action: 'analysis_status' }).then((data) => {
      analysis.dataset.running = data.running ? 1 : 0;
      if (data.log) { log.hidden = false; log.textContent = data.log; log.scrollTop = log.scrollHeight; }
      if (data.running) { label.textContent = L('Running…', 'En cours…'); setTimeout(poll, 2500); }
      else {
        start.disabled = false;
        label.textContent = data.failed ? L('Failed, see the log.', 'Échec, voir le journal.') : L('Finished. Reload to see the new results.', 'Terminée. Rechargez pour voir les nouveaux résultats.');
      }
    }).catch(() => setTimeout(poll, 5000));
    start.addEventListener('click', () => {
      start.disabled = true;
      api({ action: analysis.dataset.command === 'locate' ? 'locate' : 'analyse' }).then((data) => { if (!data.ok) toast(esc(data.message || ''), 'error'); poll(); }).catch((e) => { toast(esc(e.message), 'error'); start.disabled = false; });
    });
    if (analysis.dataset.running === '1') poll();
  }
})();
