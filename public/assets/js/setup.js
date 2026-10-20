/* ForeverTracker setup wizard: detection, choices, configuration and the first run. */
(function () {
  'use strict';
  const FT = window.FT;
  const fr = FT.lang === 'fr';
  const L = (en, frText) => (fr ? frText : en);
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const api = (body) => fetch(`${FT.base}api/setup?lang=${FT.lang}`, {
    method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': FT.csrf }, body: JSON.stringify(body),
  }).then((r) => r.json().then((data) => { if (!r.ok) throw new Error(data.error || r.statusText); return data; }));
  const toast = (message, kind) => {
    const el = document.createElement('div');
    el.className = `toast is-${kind || 'ok'}`;
    el.innerHTML = `<i class="fas ${kind === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'}"></i><div>${esc(message)}</div>`;
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), kind === 'error' ? 9000 : 5000);
  };
  $$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = next;
    try { localStorage.setItem('ft-theme', next); } catch (e) { /* private mode */ }
  }));

  const state = { step: 0, checks: [], servers: [], clients: [], server: null, client: null, source: null, db: null, python: 'python' };
  const sections = $$('[data-step]');
  const show = (step) => {
    state.step = step;
    sections.forEach((s) => { s.hidden = Number(s.dataset.step) !== step; });
    $$('[data-step-dot]').forEach((d) => { const i = Number(d.dataset.stepDot); d.classList.toggle('is-active', i === step); d.classList.toggle('is-done', i < step); });
    if (step === 2) fillDatabase();
    if (step === 3) drawClients();
    if (step === 5) drawSummary();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };
  const nextButton = (step) => $(`[data-step="${step}"] [data-next]`);
  $$('[data-next]').forEach((b) => b.addEventListener('click', () => {
    if (state.step === 4 && !checkAccess()) return;
    show(state.step + 1);
  }));
  $$('[data-back]').forEach((b) => b.addEventListener('click', () => show(state.step - 1)));

  const yes = (ok, text, small) => `<li class="${ok ? 'is-ok' : 'is-bad'}"><i class="fas ${ok ? 'fa-circle-check' : 'fa-circle-exclamation'}"></i><div><strong>${esc(text)}</strong>${small ? `<small>${esc(small)}</small>` : ''}</div></li>`;

  // ---------- 0: detection
  api({ action: 'detect' }).then((data) => {
    // complete installs first: DB2 files, maps, a worldserver binary, database settings
    const score = (s) => (s.locales.length ? 8 : 0) + (s.maps ? 4 : 0) + (s.worldserver ? 2 : 0) + (s.world ? 1 : 0);
    state.checks = data.checks; state.servers = data.servers.sort((a, b) => score(b) - score(a)); state.clients = data.clients;
    const python = data.checks.find((c) => c[0] === 'python');
    if (python && python[4]) state.python = python[4];
    const lines = data.checks.map((c) => yes(c[1] || c[0] === 'gmp', c[2] + (c[1] ? '' : (c[0] === 'gmp' ? L(' (optional, missing)', ' (optionnel, absent)') : L(' — missing', ' — absent'))), c[3]));
    lines.push(yes(data.servers.length > 0, data.servers.length ? `${data.servers.length} ${L('server(s) found', 'serveur(s) trouvé(s)')}` : L('No server found by itself', 'Aucun serveur trouvé automatiquement'),
      data.servers.length ? data.servers.map((s) => s.folder).join(' · ') : L('You will choose its folder in the next step.', 'Vous choisirez son dossier à l’étape suivante.')));
    lines.push(yes(data.clients.length > 0, data.clients.length ? `${data.clients.length} ${L('game client(s) found', 'client(s) de jeu trouvé(s)')}` : L('No game client found by itself', 'Aucun client de jeu trouvé automatiquement'),
      data.clients.length ? data.clients.map((c) => `${c.version} · ${c.folder}`).join(' · ') : L('You will choose its folder later.', 'Vous choisirez son dossier plus tard.')));
    $('[data-checks]').innerHTML = lines.join('');
    const blocking = data.checks.filter((c) => !c[1] && ['php', 'mysqli', 'python', 'writable'].includes(c[0]));
    nextButton(0).disabled = blocking.some((c) => c[0] !== 'python');
    if (blocking.length) toast(L('Fix the missing requirements, then reload this page.', 'Corrigez les prérequis manquants, puis rechargez la page.'), 'error');
    drawServers();
    if (state.servers.length && score(state.servers[0]) >= 12) chooseServer(state.servers[0]);
  }).catch((e) => toast(e.message, 'error'));

  // ---------- 1: server
  const tick = (ok, label) => `<span class="${ok ? 'ok-text' : 'warn-text'}">${label}${ok ? '' : ' ✗'}</span>`;
  function drawServers() {
    $('[data-servers]').innerHTML = state.servers.map((s, i) => `<label class="choice"><input type="radio" name="server" value="${i}" ${state.server === s ? 'checked' : ''}>
      <div><strong>${esc(s.folder)}</strong><small>${s.locales.length ? 'DB2 ' + esc(s.locales.join(', ')) : L('no DB2 folder', 'pas de dossier DB2')} · ${tick(s.maps, 'maps')} · ${tick(s.vmaps, 'vmaps')} · ${tick(s.mmaps, 'mmaps')}${s.world ? ' · ' + esc(s.world.user + '@' + s.world.host + ':' + s.world.port) : ''}</small></div></label>`).join('')
      || `<p class="muted">${L('No server was found by itself: type its folder below.', 'Aucun serveur trouvé automatiquement : tapez son dossier ci-dessous.')}</p>`;
    $$('[data-servers] input').forEach((r) => r.addEventListener('change', () => chooseServer(state.servers[Number(r.value)])));
  }
  function chooseServer(server) {
    state.server = server;
    state.db = null;
    drawServers();
    const d = $('[data-server-detail]');
    d.hidden = false;
    d.innerHTML = `<ul class="setup-checks is-compact">
      ${yes(server.locales.length > 0, L('DB2 files of the server', 'Fichiers DB2 du serveur'), server.locales.length ? `${server.data}/dbc/${server.locales.join(', ')}` : L('Not found in the data folder: extract the client data with mapextractor first.', 'Introuvables dans le dossier de données : extrayez d’abord les données du client avec mapextractor.'))}
      ${yes(server.maps && server.vmaps, L('Maps and vmaps', 'Maps et vmaps'), server.data)}
      ${yes(!!server.mysql, L('mysql client', 'Client mysql'), server.mysql || L('Not found: the analysis needs mysql.exe (from MySQL / MariaDB).', 'Introuvable : l’analyse a besoin de mysql.exe (de MySQL / MariaDB).'))}
      ${yes(server.ra.enabled, L('Remote console', 'Console distante'), server.ra.enabled ? `${server.ra.host}:${server.ra.port}` : L('Ra.Enable = 0: needed only to spawn from the website.', 'Ra.Enable = 0 : utile seulement pour placer depuis le site.'))}
    </ul>`;
    $('[data-ra="host"]').value = server.ra.host || '127.0.0.1';
    $('[data-ra="port"]').value = server.ra.port || 3443;
    nextButton(1).disabled = server.locales.length === 0 && !server.extractor;
  }
  const inspect = (action, input, done) => {
    const folder = $(input).value.trim();
    if (!folder) return;
    api({ action, folder }).then((data) => (data.ok ? done(data) : toast(data.message, 'error'))).catch((e) => toast(e.message, 'error'));
  };
  $('[data-server-inspect]').addEventListener('click', () => inspect('server', '[data-server-path]', (data) => { state.servers.push(data.server); chooseServer(data.server); }));

  // ---------- 2: databases
  const field = (name) => $(`[data-db="${name}"]`);
  function fillDatabase() {
    const s = state.server;
    if (!s || state.dbFilledFor === s.folder) return;
    state.dbFilledFor = s.folder;
    const main = s.world || s.auth || s.hotfixes;
    if (main) { field('host').value = main.host; field('port').value = main.port; field('user').value = main.user; field('password').value = main.password; }
    if (s.auth) field('auth').value = s.auth.database;
    if (s.world) field('world').value = s.world.database;
    if (s.hotfixes) field('hotfixes').value = s.hotfixes.database;
    nextButton(2).disabled = true;
    if (main) testDatabase();
  }
  const dbValues = () => ({ host: field('host').value.trim(), port: Number(field('port').value) || 3306, user: field('user').value.trim(), password: field('password').value,
    names: { auth: field('auth').value.trim(), world: field('world').value.trim(), hotfixes: field('hotfixes').value.trim(), tracker: field('tracker').value.trim() } });
  function testDatabase() {
    const box = $('[data-db-result]');
    box.hidden = false; box.innerHTML = `<p class="muted"><i class="fas fa-spinner fa-spin"></i> ${L('Connecting…', 'Connexion…')}</p>`;
    api({ action: 'database', db: dbValues() }).then((data) => {
      if (!data.ok) { box.innerHTML = `<ul class="setup-checks is-compact">${yes(false, data.message)}</ul>`; nextButton(2).disabled = true; return; }
      state.db = data;
      const v = dbValues();
      box.innerHTML = `<ul class="setup-checks is-compact">
        ${yes(true, L('Connected', 'Connecté'), 'MySQL ' + data.version)}
        ${yes(data.checks.auth, `auth: ${v.names.auth}`, data.build ? L(`realms accept client build ${data.build}`, `les royaumes acceptent le build client ${data.build}`) : '')}
        ${yes(data.checks.world, `world: ${v.names.world}`)}
        ${yes(data.checks.hotfixes, `hotfixes: ${v.names.hotfixes}`)}
        ${yes(data.tracker_exists || data.can_create, `ForeverTracker: ${v.names.tracker}`, data.tracker_exists ? L('exists, its ft_* tables will be (re)created', 'existe, ses tables ft_* seront (re)créées') : (data.can_create ? L('will be created', 'sera créée') : L('missing and this user cannot create it: create it or choose an existing database', 'absente et cet utilisateur ne peut pas la créer : créez-la ou choisissez une base existante')))}
      </ul>`;
      if (!data.tracker_exists && !data.can_create) {
        box.innerHTML += `<div class="setup-help">${data.suggest.length ? `<p>${L('Databases this user can write to:', 'Bases où cet utilisateur peut écrire :')} ${data.suggest.map((db) => `<button class="tag is-plain" type="button" data-use-db="${esc(db)}">${esc(db)}</button>`).join(' ')}</p>` : ''}
          <p class="muted small">${L('Or create a dedicated one as MySQL root:', 'Ou créez-en une dédiée en root MySQL :')}</p>
          <pre class="code">CREATE DATABASE ${esc(v.names.tracker)} CHARACTER SET utf8mb4;\nGRANT ALL PRIVILEGES ON ${esc(v.names.tracker)}.* TO '${esc(data.user)}'@'${esc(v.host)}';</pre></div>`;
        $$('[data-use-db]', box).forEach((b) => b.addEventListener('click', () => { field('tracker').value = b.dataset.useDb; testDatabase(); }));
      }
      nextButton(2).disabled = !(data.checks.auth && data.checks.world && data.checks.hotfixes && (data.tracker_exists || data.can_create));
    }).catch((e) => toast(e.message, 'error'));
  }
  $('[data-db-test]').addEventListener('click', testDatabase);
  $$('[data-db]').forEach((input) => input.addEventListener('input', () => { nextButton(2).disabled = true; }));

  // ---------- 3: game client and data source
  function drawClients() {
    $('[data-clients]').innerHTML = state.clients.map((c, i) => `<label class="choice"><input type="radio" name="client" value="${i}" ${state.client === c ? 'checked' : ''}>
      <div><strong>${esc(c.version)} <span class="muted">· ${esc(c.product)}</span></strong><small>${esc(c.folder)}${c.locales.length ? ' · ' + esc(c.locales.join(', ')) : ''}</small></div></label>`).join('')
      || `<p class="muted">${L('No game client was found by itself: type its folder below, or use the server\'s DB2 files.', 'Aucun client trouvé automatiquement : tapez son dossier ci-dessous, ou utilisez les DB2 du serveur.')}</p>`;
    $$('[data-clients] input').forEach((r) => r.addEventListener('change', () => { state.client = state.clients[Number(r.value)]; drawSources(); }));
    if (!state.client && state.clients.length) state.client = state.clients.slice().sort((a, b) => b.build - a.build)[0];
    $$('[data-clients] input').forEach((r) => { r.checked = state.clients[Number(r.value)] === state.client; });
    drawSources();
  }
  $('[data-client-inspect]').addEventListener('click', () => inspect('client', '[data-client-path]', (data) => { state.clients.push(data.client); state.client = data.client; drawClients(); }));
  function drawSources() {
    const s = state.server, c = state.client, realmBuild = state.db ? state.db.build : 0;
    const sources = [];
    if (c && s && s.extractor) sources.push({ key: 'extract', build: c.build, title: L(`Extract from the game client (build ${c.build})`, `Extraire du client du jeu (build ${c.build})`),
      text: L('Recommended: reads the official data of the installed client with your core\'s mapextractor (DB2 files only, a minute or two).', 'Conseillé : lit les données officielles du client installé avec le mapextractor de votre core (fichiers DB2 seulement, une à deux minutes).') });
    if (s && s.locales.length) sources.push({ key: 'server', build: realmBuild || (c ? c.build : 0), title: L(`Use the server's DB2 files${realmBuild ? ` (build ${realmBuild})` : ''}`, `Utiliser les DB2 du serveur${realmBuild ? ` (build ${realmBuild})` : ''}`),
      text: L('Fastest: the files your server loads, extracted from the client of its build. Choose this when the client and the server have the same build.', 'Le plus rapide : les fichiers chargés par votre serveur, extraits du client de son build. À choisir quand client et serveur ont le même build.') });
    if (!state.source || !sources.find((x) => x.key === state.source.key)) state.source = sources[0] || null;
    else state.source = sources.find((x) => x.key === state.source.key);
    $('[data-sources]').innerHTML = sources.map((x) => `<label class="choice"><input type="radio" name="source" value="${x.key}" ${state.source && state.source.key === x.key ? 'checked' : ''}><div><strong>${esc(x.title)}</strong><small>${esc(x.text)}</small></div></label>`).join('')
      || `<p class="warn-text">${L('Neither mapextractor next to the worldserver nor DB2 files in the server\'s data folder: extract the client data first (docs/INSTALL.md).', 'Ni mapextractor à côté du worldserver, ni fichiers DB2 dans le dossier de données du serveur : extrayez d’abord les données du client (docs/INSTALL.md).')}</p>`;
    $$('[data-sources] input').forEach((r) => r.addEventListener('change', () => { state.source = sources.find((x) => x.key === r.value); drawLocales(); }));
    drawLocales();
    nextButton(3).disabled = !state.source || !state.source.build;
  }
  function drawLocales() {
    const available = state.source && state.source.key === 'extract' ? (state.client ? state.client.locales : []) : (state.server ? state.server.locales : []);
    $$('[data-locale]').forEach((box) => {
      const has = !available.length || available.includes(box.dataset.locale);
      box.disabled = !has;
      if (!has) box.checked = false;
    });
    if (!$$('[data-locale]').some((b) => b.checked)) { const first = $$('[data-locale]').find((b) => !b.disabled); if (first) first.checked = true; }
  }

  // ---------- 4: access
  function checkAccess() {
    const name = $('[data-admin="name"]').value.trim(), pass = $('[data-admin="password"]').value, again = $('[data-admin="confirm"]').value;
    if (FT.configured && pass === '') return true;
    if (!/^[A-Za-z0-9_.-]{2,32}$/.test(name)) { toast(L('Administrator name: 2 to 32 letters, digits, dots, dashes or underscores.', 'Nom d’administrateur : 2 à 32 lettres, chiffres, points, tirets ou soulignés.'), 'error'); return false; }
    if (pass.length < 10) { toast(L('The password needs at least 10 characters.', 'Le mot de passe doit faire au moins 10 caractères.'), 'error'); return false; }
    if (pass !== again) { toast(L('The two passwords differ.', 'Les deux mots de passe diffèrent.'), 'error'); return false; }
    return true;
  }

  // ---------- 5: summary and install
  const locales = () => $$('[data-locale]:checked').map((b) => b.dataset.locale);
  function drawSummary() {
    const s = state.server, v = dbValues(), c = state.client;
    const rows = [
      [L('Server', 'Serveur'), s ? s.folder : '—'],
      [L('Databases', 'Bases'), `${v.user}@${v.host}:${v.port} · ${v.names.auth}, ${v.names.world}, ${v.names.hotfixes}`],
      ['ForeverTracker', v.names.tracker],
      [L('Official data', 'Données officielles'), state.source ? `${state.source.title} · ${locales().join(', ')}` : '—'],
      [L('Game client', 'Client du jeu'), c ? c.folder : '—'],
      [L('Administrator', 'Administrateur'), $('[data-admin="name"]').value.trim() || '—'],
    ];
    $('[data-summary]').innerHTML = rows.map(([k, val]) => `<dt>${esc(k)}</dt><dd>${esc(val)}</dd>`).join('');
  }
  const mark = (step, stateName) => {
    const li = $(`[data-progress-step="${step}"]`);
    if (!li) return;
    li.className = `is-${stateName}`;
    $('i', li).className = `fas ${stateName === 'done' ? 'fa-circle-check' : stateName === 'run' ? 'fa-spinner fa-spin' : stateName === 'fail' ? 'fa-circle-xmark' : 'fa-circle'}`;
  };
  $('[data-install]').addEventListener('click', () => {
    const s = state.server, v = dbValues(), c = state.client;
    const install = $('[data-install]');
    install.disabled = true;
    const config = {
      language: $('[data-language]').value, python: state.python,
      db: { host: v.host, port: v.port, user: v.user, password: v.password, auth: v.names.auth, world: v.names.world, hotfixes: v.names.hotfixes, tracker: v.names.tracker },
      server_folder: s.folder, dbc: s.locales.length ? `${s.data}/dbc/${s.locales.includes('enUS') ? 'enUS' : s.locales[0]}` : `${s.data}/dbc/enUS`,
      maps: `${s.data}/maps`, vmaps: `${s.data}/vmaps`, mmaps: `${s.data}/mmaps`, mysql: s.mysql, build: state.source.build,
      ra_host: $('[data-ra="host"]').value.trim(), ra_port: Number($('[data-ra="port"]').value) || 3443, ra_user: $('[data-ra="user"]').value.trim(), ra_password: $('[data-ra="password"]').value,
      client: c ? c.folder : '', admin_name: $('[data-admin="name"]').value.trim(), admin_password: $('[data-admin="password"]').value,
    };
    const locate = $('[data-locate]').checked;
    $('[data-progress]').hidden = false;
    $('[data-progress-step="extract"]').hidden = state.source.key !== 'extract';
    $('[data-progress-step="locate"]').hidden = !locate;
    mark('config', 'run');
    api({ action: 'save', config }).then((data) => {
      if (!data.ok) { mark('config', 'fail'); install.disabled = false; return toast(data.message, 'error'); }
      if (data.csrf) FT.csrf = data.csrf;
      mark('config', 'done');
      const plan = { extract: state.source.key === 'extract', extractor: s.extractor, client: c ? c.folder : '', product: c ? c.product : '', locales: locales(),
        source: `${s.data}/dbc`, build: state.source.build, locate };
      return api({ action: 'run', plan }).then((run) => {
        if (!run.ok) { install.disabled = false; return toast(run.message, 'error'); }
        poll();
      });
    }).catch((e) => { install.disabled = false; toast(e.message, 'error'); });
  });
  function poll() {
    api({ action: 'status' }).then((data) => {
      const order = ['extract', 'snapshot', 'analyse', 'locate'].filter((k) => !$(`[data-progress-step="${k}"]`).hidden);
      const reached = order.filter((k) => data.steps[k]);
      order.forEach((k) => {
        const i = order.indexOf(k), last = reached.length ? order.indexOf(reached[reached.length - 1]) : -1;
        if (data.done || i < last) mark(k, 'done');
        else if (i === last) mark(k, data.failed ? 'fail' : 'run');
        else mark(k, 'wait');
      });
      const log = $('[data-log]');
      if (data.log) { log.hidden = false; log.textContent = data.log; log.scrollTop = log.scrollHeight; }
      if (data.done) {
        $('[data-open]').hidden = false; $('[data-install]').hidden = true;
        $$('[data-step="5"] [data-back]').forEach((b) => { b.hidden = true; });
        toast(L('ForeverTracker is ready.', 'ForeverTracker est prêt.'));
      } else if (data.failed) {
        $('[data-install]').disabled = false;
        toast(L('A step failed: read the log, fix it and press install again.', 'Une étape a échoué : lisez le journal, corrigez puis relancez l’installation.'), 'error');
      } else setTimeout(poll, 2000);
    }).catch(() => setTimeout(poll, 4000));
  }
  show(0);
})();
