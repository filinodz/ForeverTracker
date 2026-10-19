/* Forever Tracker — in-game tooltips (items, spells and every other resource), Wowhead style.
   Hover any element with data-tt="type:id"; elements with data-tt-static render their embedded JSON in place. */
(function () {
  'use strict';
  const FT = window.FT || { base: '/', lang: 'en' };
  const fr = FT.lang === 'fr';
  const L = (en, frText) => (fr ? frText : en);
  const T = (value) => (value && typeof value === 'object' ? (fr ? value.fr || value.en : value.en || value.fr) || '' : value || '');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (n, d) => {
    const v = Number(n) || 0;
    const text = d === undefined ? (Math.abs(v - Math.round(v)) < 0.05 ? String(Math.round(v)) : v.toFixed(1)) : v.toFixed(d);
    return fr ? text.replace('.', ',') : text;
  };
  const fmt = (pattern, ...args) => { let i = 0; return pattern.replace(/%[sd]/g, () => args[i++]); };
  const time = (ms) => {
    const s = ms / 1000;
    if (s < 60) return fr ? num(s) + ' s' : num(s) + ' sec';
    if (s < 3600) return num(s / 60) + ' min';
    if (s < 172800) return fr ? num(s / 3600) + ' h' : num(s / 3600) + ' hr';
    return fr ? num(s / 86400) + ' j' : num(s / 86400) + ' days';
  };

  const SLOTS = {
    1: ['Head', 'Tête'], 2: ['Neck', 'Cou'], 3: ['Shoulder', 'Épaule'], 4: ['Shirt', 'Chemise'], 5: ['Chest', 'Torse'], 6: ['Waist', 'Taille'],
    7: ['Legs', 'Jambes'], 8: ['Feet', 'Pieds'], 9: ['Wrist', 'Poignets'], 10: ['Hands', 'Mains'], 11: ['Finger', 'Doigt'], 12: ['Trinket', 'Bijou'],
    13: ['One-Hand', 'À une main'], 14: ['Off Hand', 'Main gauche'], 15: ['Ranged', 'À distance'], 16: ['Back', 'Dos'], 17: ['Two-Hand', 'Deux mains'],
    18: ['Bag', 'Sac'], 19: ['Tabard', 'Tabard'], 20: ['Chest', 'Torse'], 21: ['Main Hand', 'Main droite'], 22: ['Off Hand', 'Main gauche'],
    23: ['Held In Off-hand', 'Tenu en main gauche'], 24: ['Projectile', 'Projectile'], 25: ['Thrown', 'Armes de jet'], 26: ['Ranged', 'À distance'],
    27: ['Quiver', 'Carquois'], 28: ['Relic', 'Relique'],
  };
  const BIND = { 1: ['Binds when picked up', 'Lié quand ramassé'], 2: ['Binds when equipped', 'Lié quand équipé'], 3: ['Binds when used', 'Lié quand utilisé'],
    4: ['Quest Item', 'Objet de quête'], 7: ['Binds to Blizzard account', 'Lié au compte Blizzard'], 8: ['Binds to Battle.net account', 'Lié au compte Battle.net'], 9: ['Binds to Battle.net account', 'Lié au compte Battle.net'] };
  const BASE_STATS = { 0: ['Mana', 'Mana'], 1: ['Health', 'Points de vie'], 3: ['Agility', 'Agilité'], 4: ['Strength', 'Force'], 5: ['Intellect', 'Intelligence'],
    6: ['Spirit', 'Esprit'], 7: ['Stamina', 'Endurance'], 50: ['Armor', 'Armure'], 51: ['Fire Resistance', 'Résistance au Feu'], 52: ['Frost Resistance', 'Résistance au Givre'],
    53: ['Holy Resistance', 'Résistance au Sacré'], 54: ['Shadow Resistance', 'Résistance à l’Ombre'], 55: ['Nature Resistance', 'Résistance à la Nature'], 56: ['Arcane Resistance', 'Résistance aux Arcanes'] };
  const RATINGS = {
    12: ['defense rating', 'le score de défense'], 13: ['dodge rating', 'le score d’esquive'], 14: ['parry rating', 'le score de parade'], 15: ['block rating', 'le score de blocage'],
    16: ['melee hit rating', 'le score de toucher en mêlée'], 17: ['ranged hit rating', 'le score de toucher à distance'], 18: ['spell hit rating', 'le score de toucher des sorts'],
    19: ['melee critical strike rating', 'le score de coup critique en mêlée'], 20: ['ranged critical strike rating', 'le score de coup critique à distance'], 21: ['spell critical strike rating', 'le score de coup critique des sorts'],
    28: ['melee haste rating', 'le score de hâte en mêlée'], 29: ['ranged haste rating', 'le score de hâte à distance'], 30: ['spell haste rating', 'le score de hâte des sorts'],
    31: ['hit rating', 'le score de toucher'], 32: ['critical strike rating', 'le score de coup critique'], 35: ['resilience rating', 'le score de résilience'], 36: ['haste rating', 'le score de hâte'],
    37: ['expertise rating', 'le score d’expertise'], 38: ['attack power', 'la puissance d’attaque'], 39: ['ranged attack power', 'la puissance d’attaque à distance'],
    41: ['healing done by spells', 'les soins prodigués par les sorts'], 42: ['damage done by spells', 'les dégâts infligés par les sorts'], 44: ['armor penetration rating', 'le score de pénétration d’armure'],
    45: ['spell power', 'la puissance des sorts'], 47: ['spell penetration', 'la pénétration des sorts'], 48: ['the block value of your shield', 'la valeur de blocage de votre bouclier'], 49: ['mastery rating', 'le score de maîtrise'],
  };
  const SCHOOLS = { 1: ['Holy', 'Sacré'], 2: ['Fire', 'Feu'], 3: ['Nature', 'Nature'], 4: ['Frost', 'Givre'], 5: ['Shadow', 'Ombre'], 6: ['Arcane', 'Arcanes'] };
  const TRIGGERS = { 0: ['Use: ', 'Utiliser : '], 1: ['Equip: ', 'Équipé : '], 2: ['Chance on hit: ', 'Chances quand vous touchez : '], 4: ['Use: ', 'Utiliser : '], 5: ['Use: ', 'Utiliser : '], 6: ['Use: ', 'Utiliser : '] };
  const POWERS = { 0: ['Mana', 'Mana'], 1: ['Rage', 'Rage'], 2: ['Focus', 'Focalisation'], 3: ['Energy', 'Énergie'], 4: ['Combo Points', 'points de combo'], 6: ['Runic Power', 'puissance runique'], 9: ['Holy Power', 'puissance sacrée'] };
  const REP = ['Hated', 'Hostile', 'Unfriendly', 'Neutral', 'Friendly', 'Honored', 'Revered', 'Exalted'];
  const REP_FR = ['Détesté', 'Hostile', 'Inamical', 'Neutre', 'Amical', 'Honoré', 'Révéré', 'Exalté'];
  const KINDS = { 1: ['Dungeon', 'Donjon'], 2: ['Raid', 'Raid'], 3: ['Battleground', 'Champ de bataille'], 4: ['Arena', 'Arène'] };
  const pick = (map, key) => (map[key] ? L(map[key][0], map[key][1]) : '');

  const line = (html, cls) => `<div class="wt-line ${cls || ''}">${html}</div>`;
  const row = (left, right, cls) => `<div class="wt-row ${cls || ''}"><span>${left}</span><span>${right || ''}</span></div>`;
  const text = (value, cls) => (T(value) ? T(value).split('\n').map((part) => line(esc(part) || '&nbsp;', cls)).join('') : '');
  const money = (copper) => {
    const g = Math.floor(copper / 10000), s = Math.floor((copper % 10000) / 100), c = copper % 100;
    return `<span class="money">${g ? `<b class="g">${g}</b>` : ''}${s ? `<b class="s">${s}</b>` : ''}${c || (!g && !s) ? `<b class="c">${c}</b>` : ''}</span>`;
  };

  function item(t) {
    const out = [line(esc(T(t.n)), `wt-name q${t.q || 0}`)];
    if (t.nd) out.push(line(esc(T(t.nd)), 'c-green'));
    if (t.ilvl && t.q !== undefined) out.push(line(fmt(L('Item Level %d', 'Niveau d’objet %d'), t.ilvl), 'c-gold'));
    if (t.bind) out.push(line(esc(pick(BIND, t.bind))));
    if (t.uniq) out.push(line(t.uniq > 1 ? fmt(L('Unique (%d)', 'Unique (%d)'), t.uniq) : L('Unique', 'Unique')));
    if (t.start) out.push(line(L('This Item Begins a Quest', 'Cet objet permet de lancer une quête'), 'c-gold'));
    const slot = t.slot ? pick(SLOTS, t.slot) : '';
    if (slot || t.sub) out.push(row(esc(slot), esc(T(t.sub))));
    else if (t.type) out.push(line(esc(T(t.type))));
    if (t.dmg) {
      const school = pick(SCHOOLS, t.dmg[2]);
      const dmg = fr ? `${L('', 'Dégâts')}${school ? ' (' + school + ')' : ''} : ${t.dmg[0]} - ${t.dmg[1]}` : `${t.dmg[0]} - ${t.dmg[1]}${school ? ' ' + school : ''} Damage`;
      out.push(row(esc(dmg), fmt(L('Speed %s', 'Vitesse %s'), num(t.speed / 1000, 2))));
      out.push(line(fmt(L('(%s damage per second)', '(%s dégâts par seconde)'), num(t.dps, 1))));
    }
    if (t.armor) out.push(line(fmt(L('%d Armor', 'Armure : %d'), t.armor)));
    const equip = [];
    (t.stats || []).forEach(([type, value]) => {
      if (BASE_STATS[type]) out.push(line(`${value > 0 ? '+' : ''}${value} ${esc(pick(BASE_STATS, type))}`, type >= 51 ? 'c-white' : ''));
      else equip.push([type, value]);
    });
    if (t.bag) out.push(line(fmt(L('%d Slot Bag', 'Sac de %d emplacements'), t.bag)));
    if (t.classes) out.push(line(L('Classes: ', 'Classes : ') + t.classes.map((c) => `<span class="cls-${c[0]}">${esc(T(c[1]))}</span>`).join(', ')));
    if (t.races) out.push(line(L('Races: ', 'Races : ') + t.races.map((r) => esc(T(r))).join(', ')));
    if (t.req) out.push(line(fmt(L('Requires Level %d', 'Niveau %d requis'), t.req)));
    if (t.skill) out.push(line(fmt(L('Requires %s (%d)', 'Requiert %s (%d)'), esc(T(t.skill[0])), t.skill[1])));
    if (t.ability) out.push(line(fmt(L('Requires %s', 'Requiert %s'), esc(T(t.ability)))));
    if (t.rep) out.push(line(fmt(L('Requires %s - %s', 'Requiert %s - %s'), esc(T(t.rep[0])), (fr ? REP_FR : REP)[t.rep[1]] || t.rep[1])));
    equip.forEach(([type, value]) => {
      if (type === 43 || type === 46) {
        out.push(line(fmt(type === 43 ? L('Equip: Restores %d mana per 5 sec.', 'Équipé : Rend %d points de mana toutes les 5 s.') : L('Equip: Restores %d health per 5 sec.', 'Équipé : Rend %d points de vie toutes les 5 s.'), value), 'c-green'));
        return;
      }
      const name = RATINGS[type] ? L(RATINGS[type][0], RATINGS[type][1]) : L('stat ', 'caractéristique ') + type;
      out.push(line(L('Equip: Increases ', 'Équipé : Augmente ') + esc(name) + L(' by ', ' de ') + value + '.', 'c-green'));
    });
    (t.fx || []).forEach((fx) => {
      out.push(line(esc(pick(TRIGGERS, fx.t) || L('Use: ', 'Utiliser : ')) + esc(T(fx.d)).replace(/\n/g, '<br>') + (fx.cd ? ` (${fmt(L('%s Cooldown', 'Recharge %s'), time(fx.cd))})` : ''), 'c-green'));
      if (fx.c && fx.c > 1) out.push(line(fmt(L('%d Charges', '%d charges'), fx.c)));
    });
    if (t.set) {
      const items = t.set.items || [];
      out.push(`<div class="wt-set">${line(`${esc(T(t.set.n))} (0/${items.length})`, 'c-gold')}${items.map((i) => line(esc(T(i[1])), 'c-grey')).join('')}</div>`);
      if (t.set.b && t.set.b.length) {
        out.push('<div class="wt-gap"></div>');
        t.set.b.forEach(([count, desc]) => out.push(line(`(${count & 255}) ${L('Set: ', 'Ensemble : ')}${esc(T(desc))}`, 'c-grey')));
      }
    }
    if (t.desc) out.push(line(`“${esc(T(t.desc))}”`, 'c-quote'));
    if (t.sell) out.push(line(L('Sell Price: ', 'Prix de vente : ') + money(t.sell)));
    return out;
  }

  function spell(t) {
    const out = [row(esc(T(t.n)), t.rank ? `<span class="c-grey">${esc(T(t.rank))}</span>` : '', 'wt-name')];
    if (t.ranks) out.push(line(fmt(L('Rank 1/%d', 'Rang 1/%d'), t.ranks), 'c-grey'));
    if (t.tree) out.push(line(esc(t.tree), 'c-muted'));
    const cost = (t.cost || []).map(([power, amount, pct, perSecond]) => {
      const name = pick(POWERS, power) || L('Power', 'Énergie');
      let s = pct ? fmt(L('%s%% of base mana', '%s%% du mana de base'), num(pct)) : `${amount} ${name}`;
      if (perSecond) s += fmt(L(', plus %d per sec', ', plus %d par seconde'), perSecond);
      return s;
    }).join(', ');
    let range = '';
    if (t.range) range = t.range[2] || t.range[1] <= 5 ? L('Melee Range', 'Allonge') : (t.range[0] > 0 ? fmt(L('%s-%s yd range', 'Portée de %s-%s m'), num(t.range[0]), num(t.range[1])) : fmt(L('%s yd range', 'Portée de %s m'), num(t.range[1])));
    if (cost || range) out.push(row(esc(cost), esc(range)));
    let cast = t.passive ? L('Passive', 'Passif') : t.chan ? L('Channeled', 'Canalisée') : t.swing ? L('Next melee', 'Prochaine attaque') : t.cast ? fmt(L('%s sec cast', 'Incantation %s s'), num(t.cast / 1000, t.cast % 1000 ? 1 : 0)) : L('Instant', 'Incantation immédiate');
    out.push(row(esc(cast), t.cd ? esc(fmt(L('%s cooldown', 'Recharge %s'), time(t.cd))) : ''));
    if (t.skill) out.push(line(fmt(L('Requires %s (%d)', 'Requiert %s (%d)'), esc(T(t.skill[0])), t.skill[1])));
    if (t.tools) out.push(line(L('Tools: ', 'Outils : ') + t.tools.map((x) => esc(T(x[1]))).join(', ')));
    if (t.reag) out.push(line(L('Reagents: ', 'Composants : ') + t.reag.map(([, count, name]) => esc(T(name)) + (count > 1 ? ` (${count})` : '')).join(', ')));
    if (t.lvl && !t.col) out.push(line(fmt(L('Requires Level %d', 'Niveau %d requis'), t.lvl)));
    if (t.desc) out.push(text(t.desc, 'c-gold'));
    else if (t.aura) out.push(text(t.aura, 'c-gold'));
    if (t.src) out.push('<div class="wt-gap"></div>' + text(t.src, 'c-muted'));
    return out;
  }

  function other(t, type) {
    const out = [line(esc(T(t.n)), 'wt-name c-gold')];
    if (t.k === 'quest') {
      if (t.lvl) out.push(line(fmt(L('Level %d', 'Niveau %d'), t.lvl)));
      if (t.min) out.push(line(fmt(L('Requires Level %d', 'Niveau %d requis'), t.min)));
      if (t.zone) out.push(line(esc(T(t.zone)), 'c-muted'));
      if (t.text) out.push('<div class="wt-gap"></div>' + text(t.text));
    } else if (t.k === 'dungeon') {
      out.push(row(esc(pick(KINDS, t.kind)), t.players ? fmt(L('%d players', '%d joueurs'), t.players) : ''));
    } else if (t.k === 'boss') {
      out.push(line(esc(T(t.map)), 'c-muted'));
      out.push(line(fmt(L('Encounter %d', 'Rencontre %d'), t.order)));
    } else if (t.k === 'zone' || t.k === 'taxi') {
      if (T(t.cont)) out.push(line(esc(T(t.cont)), 'c-muted'));
      if (t.k === 'taxi') out.push(line(L('Flight point', 'Point de vol')));
      if (t.lvl) out.push(line(fmt(L('Exploration level %d', 'Niveau d’exploration %d'), t.lvl)));
    } else if (t.k === 'achievement') {
      if (t.desc) out.push(text(t.desc));
      out.push(line(fmt(L('%d points', '%d points'), t.pts || 0), 'c-gold'));
      if (t.reward) out.push(line(esc(T(t.reward)), 'c-green'));
    }
    return out;
  }

  function render(data, options) {
    const t = data.tip || {};
    const body = t.k === 'item' ? item(t) : t.k === 'spell' ? spell(t) : other(t, data.type);
    if (t.col && t.k === 'item' && t.src) body.push('<div class="wt-gap"></div>' + text(t.src, 'c-muted'));
    if (!options.static && data.status) {
      const fails = (data.fails || []).slice(0, 2).map(esc).join(' · ');
      body.push(`<div class="wt-foot"><span class="wt-status is-${data.status}"><i></i>${esc(data.statusLabel)}${data.status === 'partial' ? ' · ' + data.score + '%' : ''}</span>${fails ? `<small>${fails}</small>` : ''}<span class="wt-brand">Forever Tracker · ${esc(data.type)}</span></div>`);
    }
    const quality = t.k === 'item' ? ` q${t.q || 0}` : '';
    const icon = data.icon ? `<span class="wt-icon${quality}" style="background-image:url('${esc(data.icon)}')"></span>` : '';
    return `<div class="wt">${icon}<div class="wt-box">${body.join('')}</div></div>`;
  }

  // ---------- hover
  const box = () => document.getElementById('tt');
  const cache = new Map();
  let current = null, timer = 0, mouse = { x: 0, y: 0 };

  function load(key) {
    if (!cache.has(key)) {
      const [type, id] = key.split(':');
      cache.set(key, fetch(`${FT.base}api/tooltip/${encodeURIComponent(type)}/${encodeURIComponent(id)}?lang=${FT.lang}`, { headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : null)).catch(() => null));
    }
    return cache.get(key);
  }

  function place() {
    const el = box();
    if (!el || el.hidden) return;
    const pad = 16, w = el.offsetWidth, h = el.offsetHeight;
    let x = mouse.x + pad, y = mouse.y + pad;
    if (x + w > window.innerWidth - 8) x = Math.max(8, mouse.x - w - pad);
    if (y + h > window.innerHeight - 8) y = Math.max(8, window.innerHeight - h - 8);
    el.style.transform = `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
  }

  function show(target) {
    const key = target.getAttribute('data-tt');
    if (!key) return;
    current = key;
    const el = box();
    clearTimeout(timer);
    timer = setTimeout(() => {
      if (current !== key) return;
      el.innerHTML = `<div class="wt-loading">${L('Loading…', 'Chargement…')}</div>`;
      el.hidden = false;
      place();
      load(key).then((data) => {
        if (current !== key) return;
        if (!data) { el.hidden = true; return; }
        el.innerHTML = render(data, {});
        place();
      });
    }, 90);
  }

  function hide() {
    current = null;
    clearTimeout(timer);
    const el = box();
    if (el) el.hidden = true;
  }

  document.addEventListener('pointerover', (event) => {
    if (event.pointerType === 'touch') return;
    const target = event.target.closest('[data-tt]');
    if (target && target.getAttribute('data-tt') !== current) show(target);
  });
  document.addEventListener('pointerout', (event) => {
    const target = event.target.closest('[data-tt]');
    if (target && !target.contains(event.relatedTarget)) hide();
  });
  document.addEventListener('pointermove', (event) => { mouse = { x: event.clientX, y: event.clientY }; place(); }, { passive: true });
  window.addEventListener('scroll', hide, { passive: true });
  document.addEventListener('focusin', (event) => {
    const target = event.target.closest('[data-tt]');
    if (!target) return;
    const rect = target.getBoundingClientRect();
    mouse = { x: rect.right, y: rect.top };
    show(target);
  });
  document.addEventListener('focusout', hide);

  // ---------- static tooltips (resource pages)
  function statics() {
    document.querySelectorAll('[data-tt-static]').forEach((el) => {
      const json = el.querySelector('script[type="application/json"]');
      if (!json) return;
      try {
        const tip = JSON.parse(json.textContent);
        el.innerHTML = render({ tip, icon: el.getAttribute('data-icon') || '' }, { static: true });
      } catch (e) { /* keep empty */ }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', statics); else statics();
  window.FTTooltip = { render };
})();
