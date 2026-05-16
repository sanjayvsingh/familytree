/* app.js — family tree interactive UI */
'use strict';

const individuals = {};
const families    = {};
const detailCache = new Map();

// ── Auth helpers ───────────────────────────────────────────────────────────

function getAuthHeaders() {
  const token = localStorage.getItem('familytree:session');
  return token ? { 'Authorization': 'Bearer ' + token } : {};
}

function handleUnauthorized() {
  localStorage.removeItem('familytree:session');
  document.cookie = 'ft_session=; max-age=0; path=/; SameSite=Strict';
  window.location.reload();
}

// ── API calls ──────────────────────────────────────────────────────────────

async function fetchIndex() {
  const res = await fetch(`api.php?action=index&file=${encodeURIComponent(window.GEDCOM_FILE)}`, {
    headers: getAuthHeaders(),
  });
  if (res.status === 401) { handleUnauthorized(); return; }
  if (!res.ok) throw new Error('Index fetch failed');
  const data = await res.json();
  Object.assign(individuals, data.individuals);
  Object.assign(families,    data.families);
}

async function fetchPerson(id) {
  if (detailCache.has(id)) return detailCache.get(id);
  const res = await fetch(`api.php?action=person&file=${encodeURIComponent(window.GEDCOM_FILE)}&id=${encodeURIComponent(id)}`, {
    headers: getAuthHeaders(),
  });
  if (res.status === 401) { handleUnauthorized(); return null; }
  if (!res.ok) throw new Error('Person fetch failed');
  const data = await res.json();
  detailCache.set(id, data);
  return data;
}

// ── Helpers ────────────────────────────────────────────────────────────────

function fmtDate(ev) {
  return ev && ev.date ? ev.date : '';
}

function birthYear(ind) {
  const d = fmtDate(ind.birth);
  const m = d.match(/\b(\d{4})\b/);
  return m ? m[1] : '';
}

function lifespan(ind) {
  const b = birthYear(ind);
  const dd = fmtDate(ind.death);
  const dy = dd.match(/\b(\d{4})\b/);
  if (!b && !dy) return '';
  return (b || '?') + (dd ? ' – ' + (dy ? dy[1] : '?') : '');
}

function formatFullDate(gedStr) {
  const MONS = { JAN:'Jan',FEB:'Feb',MAR:'Mar',APR:'Apr',MAY:'May',JUN:'Jun',
                 JUL:'Jul',AUG:'Aug',SEP:'Sep',OCT:'Oct',NOV:'Nov',DEC:'Dec' };
  const m = gedStr.toUpperCase().match(/(\d{1,2})\s+([A-Z]{3})\s+(\d{4})/);
  if (!m || !MONS[m[2]]) return '';
  return `${MONS[m[2]]} ${parseInt(m[1])}, ${m[3]}`;
}

function cardDates(ind) {
  const deathDate = fmtDate(ind.death);
  if (deathDate) return lifespan(ind);          // deceased: show year range
  const full = formatFullDate(fmtDate(ind.birth));
  return full || birthYear(ind);                // living: full date or year
}

function getFamily(famId) {
  return families[famId] || null;
}

function getIndividual(id) {
  return individuals[id] || null;
}

function cardSexClass(ind) {
  if (!ind) return '';
  return ind.sex === 'M' ? 'sex-M' : ind.sex === 'F' ? 'sex-F' : '';
}

// ── Tree layout ────────────────────────────────────────────────────────────
// Centered-person layout:
//   Row -2: paternal grandparents  maternal grandparents
//   Row -1: father                 mother
//   Row  0: [siblings]  FOCUS  [spouses]
//   Row +1: children

const CARD_W = 160, CARD_H = 80, GAP_X = 24, GAP_Y = 48;
const COL_W  = CARD_W + GAP_X;
const ROW_H  = CARD_H + GAP_Y;

let focusId    = null;
let zoomScale  = 1;
let panX       = 0, panY = 0;
let isPanning  = false;
let panStartX  = 0, panStartY = 0;

const canvas   = document.getElementById('tree-canvas');
const viewport = document.getElementById('tree-viewport');

function buildLayout(rootId) {
  const nodes = [];   // { id, col, row, role }
  const seen  = new Set();

  function add(id, col, row, role) {
    if (!id || seen.has(id)) return;
    seen.add(id);
    nodes.push({ id, col, row, role });
  }

  const focus = getIndividual(rootId);
  if (!focus) return nodes;

  add(rootId, 0, 0, 'focus');

  // Spouses (from FAMS)
  let spouseCol = 1;
  const spouses = [];
  for (const famId of focus.fams || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    const spId = fam.husb === rootId ? fam.wife : fam.husb;
    if (spId && !seen.has(spId)) {
      add(spId, spouseCol, 0, 'spouse');
      spouses.push({ spId, famId });
      spouseCol++;
    }

    // Children (up to 8 shown)
    const children = (fam.children || []).slice(0, 8);
    const totalCh  = children.length;
    const baseCol  = 0;  // centre children under focus
    const startC   = baseCol - Math.floor(totalCh / 2);
    children.forEach((chId, i) => add(chId, startC + i, 1, 'child'));
  }

  // Parents
  for (const famId of focus.famc || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    if (fam.husb) { add(fam.husb, -1, -1, 'parent'); }
    if (fam.wife) { add(fam.wife,  0, -1, 'parent'); }

    // Paternal grandparents
    if (fam.husb) {
      const father = getIndividual(fam.husb);
      for (const gpFamId of father?.famc || []) {
        const gpFam = getFamily(gpFamId);
        if (!gpFam) continue;
        if (gpFam.husb) add(gpFam.husb, -2, -2, 'grandparent');
        if (gpFam.wife) add(gpFam.wife, -1, -2, 'grandparent');
      }
    }
    // Maternal grandparents
    if (fam.wife) {
      const mother = getIndividual(fam.wife);
      for (const gpFamId of mother?.famc || []) {
        const gpFam = getFamily(gpFamId);
        if (!gpFam) continue;
        if (gpFam.husb) add(gpFam.husb, 0, -2, 'grandparent');
        if (gpFam.wife) add(gpFam.wife, 1, -2, 'grandparent');
      }
    }
    break; // use first family as child only
  }

  // Siblings (from first parent family, max 6)
  for (const famId of focus.famc || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    const siblings = (fam.children || []).filter(id => id !== rootId).slice(0, 6);
    const half = Math.ceil(siblings.length / 2);
    siblings.forEach((sibId, i) => {
      const col = i < half ? -(i + 1) : (i - half + spouseCol);
      add(sibId, col, 0, 'sibling');
    });
    break;
  }

  return nodes;
}

function renderTree(rootId) {
  focusId = rootId;
  canvas.innerHTML = '';

  const nodes = buildLayout(rootId);
  if (!nodes.length) return;

  // Compute pixel positions
  const positioned = nodes.map(n => ({
    ...n,
    x: n.col * COL_W - CARD_W / 2,
    y: n.row * ROW_H - CARD_H / 2,
  }));

  // SVG connector layer
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.classList.add('connectors');
  canvas.appendChild(svg);

  // Draw cards
  for (const n of positioned) {
    const ind = getIndividual(n.id);
    if (!ind) continue;

    const card = document.createElement('div');
    card.className = `person-card ${cardSexClass(ind)}`;
    if (n.id === rootId) card.classList.add('selected');
    card.style.left = n.x + 'px';
    card.style.top  = n.y + 'px';
    card.dataset.id = n.id;

    const ls = cardDates(ind);
    const isMe = n.id === getMeId();
    card.innerHTML = `
      <div class="card-badge">${n.role !== 'focus' ? roleLabel(n.role) : ''}</div>
      <div class="card-name">${esc(ind.name)}</div>
      ${ls ? `<div class="card-dates">${esc(ls)}</div>` : ''}
      ${isMe ? '<div class="card-me">me</div>' : ''}
    `;
    card.addEventListener('click', () => navigateTo(n.id));
    canvas.appendChild(card);
  }

  // Draw connectors
  drawConnectors(svg, positioned, rootId);

  // Centre the focus card
  const focusNode = positioned.find(n => n.id === rootId);
  if (focusNode) {
    panX = -(focusNode.x + CARD_W / 2);
    panY = -(focusNode.y + CARD_H / 2);
    applyTransform();
  }

}

function roleLabel(role) {
  return { parent: 'Parent', grandparent: 'GP', sibling: 'Sibling', spouse: 'Spouse', child: 'Child' }[role] || '';
}

function drawConnectors(svg, nodes, rootId) {
  const byId = Object.fromEntries(nodes.map(n => [n.id, n]));
  const focus = byId[rootId];
  if (!focus) return;

  const focusCX = focus.x + CARD_W / 2;
  const focusTY = focus.y;
  const focusBY = focus.y + CARD_H;

  function elbow(x1, y1, x2, y2) {
    const mx = (x1 + x2) / 2;
    const my = (y1 + y2) / 2;
    return `M ${x1} ${y1} C ${x1} ${my}, ${x2} ${my}, ${x2} ${y2}`;
  }

  // Focus → parents
  nodes.filter(n => n.role === 'parent').forEach(n => {
    const nx = n.x + CARD_W / 2;
    const ny = n.y + CARD_H;
    addPath(svg, elbow(focusCX, focusTY, nx, ny));
  });

  // Focus → children
  nodes.filter(n => n.role === 'child').forEach(n => {
    const nx = n.x + CARD_W / 2;
    const ny = n.y;
    addPath(svg, elbow(focusCX, focusBY, nx, ny));
  });

  // Parents → grandparents
  nodes.filter(n => n.role === 'grandparent').forEach(n => {
    // find nearest parent
    const parents = nodes.filter(p => p.role === 'parent');
    if (!parents.length) return;
    const parent = parents.reduce((a, b) =>
      Math.abs(a.col - n.col) < Math.abs(b.col - n.col) ? a : b);
    const px = parent.x + CARD_W / 2;
    const py = parent.y;
    const nx = n.x + CARD_W / 2;
    const ny = n.y + CARD_H;
    addPath(svg, elbow(px, py, nx, ny));
  });

  // Spouse connector (dashed horizontal)
  nodes.filter(n => n.role === 'spouse').forEach(n => {
    const x1 = focus.x + CARD_W;
    const y1 = focus.y + CARD_H / 2;
    const x2 = n.x;
    const y2 = n.y + CARD_H / 2;
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.classList.add('spouse-line');
    path.setAttribute('d', `M ${x1} ${y1} L ${x2} ${y2}`);
    svg.appendChild(path);
  });
}

function addPath(svg, d) {
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', d);
  svg.appendChild(path);
}

// ── Navigation ─────────────────────────────────────────────────────────────

function navigateTo(id) {
  renderTree(id);
  showDetail(id);
  highlightPeopleList(id);
}

function buildPeopleList() {
  const list   = document.getElementById('people-list');
  const filter = document.getElementById('people-search');
  if (!list) return;

  // Extract sort key: [lastName, givenName]
  // Names with no recognisable surname (unknown/empty) sort last.
  function sortKey(ind) {
    const name = ind.name || '';
    if (!name || name === '(Unknown)' || /^\(/.test(name) || /^\?/.test(name)) {
      return ['￿', ''];
    }
    // Prefer explicit surn sub-tag, otherwise use last word of display name
    const surn  = ind.surn || name.split(/\s+/).pop();
    const given = name.endsWith(surn) ? name.slice(0, -surn.length).trim() : name;
    return [surn.toLowerCase(), given.toLowerCase()];
  }

  const sorted = Object.values(individuals).slice().sort((a, b) => {
    const [aLast, aGiven] = sortKey(a);
    const [bLast, bGiven] = sortKey(b);
    return aLast.localeCompare(bLast) || aGiven.localeCompare(bGiven);
  });

  const meId = getMeId();
  list.innerHTML = sorted.map(ind => {
    const isMe = ind.id === meId;
    return `<li data-id="${esc(ind.id)}" data-name="${esc(ind.name.toLowerCase())}">${esc(ind.name)}${isMe ? ' <span class="pl-me">me</span>' : ''}</li>`;
  }).join('');

  list.querySelectorAll('li').forEach(li => {
    li.addEventListener('click', () => {
      navigateTo(li.dataset.id);
      document.getElementById('people-panel')?.classList.remove('mobile-open');
    });
  });

  if (filter) {
    filter.addEventListener('input', () => {
      const q = filter.value.trim().toLowerCase();
      list.querySelectorAll('li').forEach(li => {
        li.hidden = q.length > 0 && !li.dataset.name.includes(q);
      });
    });
  }
}

function highlightPeopleList(id) {
  const list = document.getElementById('people-list');
  if (!list) return;
  list.querySelectorAll('li').forEach(li =>
    li.classList.toggle('active', li.dataset.id === id)
  );
  const active = list.querySelector('li.active');
  if (active) active.scrollIntoView({ block: 'nearest' });
}

// nav-back listener is attached after the element is created below

// ── Pan & zoom ─────────────────────────────────────────────────────────────

function applyTransform() {
  canvas.style.transform = `translate(${panX}px, ${panY}px) scale(${zoomScale})`;
}

viewport.addEventListener('mousedown', e => {
  if (e.target.closest('.person-card')) return;
  isPanning = true;
  panStartX = e.clientX - panX;
  panStartY = e.clientY - panY;
});
window.addEventListener('mousemove', e => {
  if (!isPanning) return;
  panX = e.clientX - panStartX;
  panY = e.clientY - panStartY;
  applyTransform();
});
window.addEventListener('mouseup', () => { isPanning = false; });

viewport.addEventListener('wheel', e => {
  e.preventDefault();
  const delta = e.deltaY > 0 ? 0.9 : 1.1;
  zoomScale   = Math.min(2.5, Math.max(0.3, zoomScale * delta));
  applyTransform();
}, { passive: false });

// Touch pan
let lastTouches = null;
viewport.addEventListener('touchstart', e => {
  if (e.touches.length === 1) {
    panStartX = e.touches[0].clientX - panX;
    panStartY = e.touches[0].clientY - panY;
  }
  lastTouches = e.touches;
}, { passive: true });
viewport.addEventListener('touchmove', e => {
  if (e.touches.length === 1 && !e.target.closest('.person-card')) {
    panX = e.touches[0].clientX - panStartX;
    panY = e.touches[0].clientY - panStartY;
    applyTransform();
  }
  lastTouches = e.touches;
}, { passive: true });

// Zoom buttons
const zoomEl = document.createElement('div');
zoomEl.id = 'zoom-controls';
zoomEl.innerHTML = `<button id="zoom-in" title="Zoom in">+</button><button id="zoom-out" title="Zoom out">−</button><button id="zoom-reset" title="Reset" style="font-size:13px">⌂</button>`;
document.getElementById('tree-container').appendChild(zoomEl);

document.getElementById('zoom-in').addEventListener('click', () => { zoomScale = Math.min(2.5, zoomScale * 1.2); applyTransform(); });
document.getElementById('zoom-out').addEventListener('click', () => { zoomScale = Math.max(0.3, zoomScale / 1.2); applyTransform(); });
document.getElementById('zoom-reset').addEventListener('click', () => { zoomScale = 1; if (focusId) navigateTo(focusId); });

// ── Detail panel ───────────────────────────────────────────────────────────

async function showDetail(id) {
  const slim  = getIndividual(id);
  if (!slim) return;

  const panel   = document.getElementById('detail-panel');
  const content = document.getElementById('detail-content');
  panel.hidden  = false;

  // Show name/sex immediately while full record loads
  content.innerHTML = `
    <h2>${esc(slim.name)}</h2>
    <div class="detail-sub">${esc(slim.sex === 'M' ? 'Male' : slim.sex === 'F' ? 'Female' : '')}${lifespan(slim) ? '  ·  ' + esc(lifespan(slim)) : ''}</div>
    <p class="detail-loading">Loading…</p>
  `;

  let ind;
  try {
    ind = await fetchPerson(id);
  } catch(e) {
    const p = content.querySelector('.detail-loading');
    if (p) { p.textContent = 'Failed to load details.'; p.style.color = '#b91c1c'; }
    return;
  }

  if (id !== focusId) return; // user navigated away while fetching

  const rows = [];

  function eventRows(label, ev) {
    if (!ev) return;
    const parts = [ev.date, ev.place].filter(Boolean);
    if (parts.length) rows.push(`<div class="detail-row"><span class="detail-label">${label}</span><span class="detail-val">${esc(parts.join(' · '))}</span></div>`);
  }

  const isLiving = ind.birth?.date && !ind.death?.date;
  if (ind.birth?.date) {
    const fullBirth = isLiving ? (formatFullDate(ind.birth.date) || ind.birth.date) : ind.birth.date;
    const parts = [fullBirth, ind.birth.place].filter(Boolean);
    rows.push(`<div class="detail-row"><span class="detail-label">Born</span><span class="detail-val">${esc(parts.join(' · '))}</span></div>`);
  } else if (ind.birth?.place) {
    rows.push(`<div class="detail-row"><span class="detail-label">Born</span><span class="detail-val">${esc(ind.birth.place)}</span></div>`);
  }
  eventRows('Died', ind.death);
  eventRows('Buried', ind.burial);
  eventRows('Christened', ind.chr);
  if (ind.occu) rows.push(`<div class="detail-row"><span class="detail-label">Occupation</span><span class="detail-val">${esc(ind.occu)}</span></div>`);
  if (ind.reli) rows.push(`<div class="detail-row"><span class="detail-label">Religion</span><span class="detail-val">${esc(ind.reli)}</span></div>`);
  if (ind.resi) rows.push(`<div class="detail-row"><span class="detail-label">Residence</span><span class="detail-val">${esc(ind.resi)}</span></div>`);

  // Parents (use slim family + slim individual data — already loaded)
  let parentHtml = '';
  for (const famId of ind.famc || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    if (fam.husb) parentHtml += relLink(fam.husb, 'Father');
    if (fam.wife) parentHtml += relLink(fam.wife, 'Mother');
  }

  // Spouses & children
  let spouseHtml = '';
  for (const famId of ind.fams || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    const spId = fam.husb === id ? fam.wife : fam.husb;
    if (spId) {
      const marrStr = fam.marr?.date ? ` (m. ${fam.marr.date})` : '';
      spouseHtml += relLink(spId, 'Spouse', marrStr);
    }
    for (const chId of fam.children || []) {
      spouseHtml += relLink(chId, 'Child');
    }
  }

  // Siblings
  let sibHtml = '';
  for (const famId of ind.famc || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    for (const sibId of fam.children || []) {
      if (sibId !== id) sibHtml += relLink(sibId, 'Sibling');
    }
    break;
  }

  // Grandchildren — dedup, sort oldest first
  const gcSeen = new Set();
  const gcIds  = [];
  for (const famId of ind.fams || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    for (const chId of fam.children || []) {
      const ch = getIndividual(chId);
      if (!ch) continue;
      for (const chFamId of ch.fams || []) {
        const chFam = getFamily(chFamId);
        if (!chFam) continue;
        for (const gcId of chFam.children || []) {
          if (!gcSeen.has(gcId)) { gcSeen.add(gcId); gcIds.push(gcId); }
        }
      }
    }
  }
  gcIds.sort((a, b) => {
    const ay = parseInt(birthYear(getIndividual(a)) || '9999');
    const by = parseInt(birthYear(getIndividual(b)) || '9999');
    return ay - by;
  });
  const gcHtml = gcIds.map(gcId => relLink(gcId, 'Grandchild')).join('');

  const noteHtml = ind.note
    ? `<div class="detail-section"><h3>Notes</h3><p style="font-size:13px;white-space:pre-wrap">${esc(ind.note)}</p></div>`
    : '';

  content.innerHTML = `
    <h2>${esc(ind.name)}</h2>
    <div class="detail-sub">${esc(ind.sex === 'M' ? 'Male' : ind.sex === 'F' ? 'Female' : '')}${lifespan(ind) ? '  ·  ' + esc(lifespan(ind)) : ''}</div>
    ${rows.length ? `<div class="detail-section"><h3>Vital records</h3>${rows.join('')}</div>` : ''}
    ${parentHtml ? `<div class="detail-section"><h3>Parents</h3>${parentHtml}</div>` : ''}
    ${spouseHtml ? `<div class="detail-section"><h3>Spouses &amp; children</h3>${spouseHtml}</div>` : ''}
    ${gcHtml     ? `<div class="detail-section"><h3>Grandchildren</h3>${gcHtml}</div>` : ''}
    ${sibHtml    ? `<div class="detail-section"><h3>Siblings</h3>${sibHtml}</div>` : ''}
    ${noteHtml}
  `;

  content.querySelectorAll('.rel-link').forEach(el => {
    el.addEventListener('click', () => navigateTo(el.dataset.id));
  });
}

function relLink(id, role, extra = '') {
  const ind = getIndividual(id);
  if (!ind) return '';
  const ls = lifespan(ind);
  return `<div class="rel-link" data-id="${esc(id)}">
    <span class="rel-role" style="font-size:11px;color:var(--text-muted);margin-right:6px">${role}</span>
    <span class="rel-name">${esc(ind.name)}</span>
    ${extra ? `<span class="rel-dates">${esc(extra)}</span>` : ''}
    ${ls ? `<span class="rel-dates">  ${esc(ls)}</span>` : ''}
  </div>`;
}

document.getElementById('panel-close').addEventListener('click', () => {
  document.getElementById('detail-panel').hidden = true;
});

// ── Upcoming dates ─────────────────────────────────────────────────────────

function buildUpcoming() {
  const upcomingList = document.getElementById('upcoming-list');
  if (!upcomingList) return;

  const today   = new Date();
  const year    = today.getFullYear();

  const events = [];

  const meId    = getMeId();
  const distMap = meId ? buildDistanceMap(meId) : null;
  const ME_MAX  = 4;
  const inRange = id => !distMap || (distMap[id] ?? Infinity) <= ME_MAX;

  // Month abbreviations used in GEDCOM dates
  const MONTHS = { JAN:0,FEB:1,MAR:2,APR:3,MAY:4,JUN:5,JUL:6,AUG:7,SEP:8,OCT:9,NOV:10,DEC:11 };

  function parseGedDate(str) {
    if (!str) return null;
    const m = str.toUpperCase().match(/(?:ABT|CAL|EST|BEF|AFT)?\s*(\d{1,2}\s+)?([A-Z]{3})\s+(\d{4})/);
    if (!m) return null;
    const day   = m[1] ? parseInt(m[1]) : 1;
    const month = MONTHS[m[2]];
    if (month === undefined) return null;
    return { day, month, year: parseInt(m[3]) };
  }

  function ordinal(n) {
    const v = n % 100;
    const s = ['th', 'st', 'nd', 'rd'];
    return n + (s[(v - 20) % 10] || s[v] || s[0]);
  }

  // Birthdays
  for (const ind of Object.values(individuals)) {
    if (!inRange(ind.id)) continue;
    const parsed = parseGedDate(ind.birth?.date);
    if (!parsed) continue;
    if (ind.death?.date) continue; // skip known deceased
    const age = year - parsed.year;
    if (age > 90) continue; // likely deceased
    const thisYear = new Date(year, parsed.month, parsed.day);
    const diff     = thisYear - today;
    const daysAway = Math.ceil(diff / 86400000);
    if (daysAway >= -1 && daysAway <= 90) {
      const type = age > 0 ? `${ordinal(age)} birthday` : 'Birthday';
      events.push({ ind, type, date: thisYear, daysAway });
    }
  }

  // Anniversaries (from FAM records)
  for (const fam of Object.values(families)) {
    if (!inRange(fam.husb) && !inRange(fam.wife)) continue;
    if (!fam.marr?.date) continue;
    const parsed = parseGedDate(fam.marr.date);
    if (!parsed) continue;
    const h   = fam.husb ? getIndividual(fam.husb) : null;
    const w   = fam.wife ? getIndividual(fam.wife) : null;
    // Skip if both are deceased
    if (h?.death?.date && w?.death?.date) continue;
    const thisYear = new Date(year, parsed.month, parsed.day);
    const diff     = thisYear - today;
    const daysAway = Math.ceil(diff / 86400000);
    if (daysAway >= -1 && daysAway <= 90) {
      const years = year - parsed.year;
      const type  = years > 0 ? `${ordinal(years)} anniversary` : 'Anniversary';
      const names = [h?.name, w?.name].filter(Boolean).join(' & ');
      events.push({ fam, type, date: thisYear, daysAway, names, id: h?.id || w?.id });
    }
  }

  events.sort((a, b) => a.daysAway - b.daysAway);

  if (!events.length) {
    upcomingList.innerHTML = '<p style="color:var(--text-muted);font-size:13px">No upcoming dates in the next 90 days.</p>';
    return;
  }

  upcomingList.innerHTML = events.slice(0, 20).map(ev => {
    const label = ev.daysAway === 0 ? 'Today!' :
                  ev.daysAway === 1 ? 'Tomorrow' :
                  ev.daysAway < 0   ? `${Math.abs(ev.daysAway)}d ago` :
                  `in ${ev.daysAway}d`;
    const dateStr = ev.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    const name    = ev.ind ? ev.ind.name : ev.names;
    const navId   = ev.ind ? ev.ind.id : ev.id;
    return `<div class="upcoming-card" data-id="${esc(navId)}">
      <div class="uc-date">${esc(dateStr)} <span style="font-weight:400;font-size:11px">${esc(label)}</span></div>
      <div class="uc-name">${esc(name)}</div>
      <div class="uc-type">${esc(ev.type)}</div>
    </div>`;
  }).join('');

  upcomingList.querySelectorAll('.upcoming-card').forEach(el => {
    el.addEventListener('click', () => navigateTo(el.dataset.id));
  });
}

// ── Bootstrap ──────────────────────────────────────────────────────────────

function esc(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── "Set as me" ────────────────────────────────────────────────────────────

function meStorageKey() {
  return `familytree:me:${window.GEDCOM_FILE || ''}`;
}

function getMeId() {
  const id = localStorage.getItem(meStorageKey());
  return id && individuals[id] ? id : null;
}

function setAsMe(id) {
  localStorage.setItem(meStorageKey(), id);
  renderTree(focusId);
  buildPeopleList();
  highlightPeopleList(focusId);
  buildUpcoming();
}

function clearMe() {
  localStorage.removeItem(meStorageKey());
  renderTree(focusId);
  buildPeopleList();
  highlightPeopleList(focusId);
  buildUpcoming();
}

let _distMapCache = { id: null, map: null };

function buildDistanceMap(fromId) {
  if (_distMapCache.id === fromId) return _distMapCache.map;
  const dist  = { [fromId]: 0 };
  const queue = [fromId];
  while (queue.length) {
    const cur = queue.shift();
    const ind = individuals[cur];
    if (!ind) continue;
    const next = [];
    for (const famId of ind.famc || []) {
      const fam = families[famId];
      if (!fam) continue;
      if (fam.husb) next.push(fam.husb);
      if (fam.wife) next.push(fam.wife);
    }
    for (const famId of ind.fams || []) {
      const fam = families[famId];
      if (!fam) continue;
      if (fam.husb && fam.husb !== cur) next.push(fam.husb);
      if (fam.wife && fam.wife !== cur) next.push(fam.wife);
      for (const chId of fam.children || []) next.push(chId);
    }
    for (const nb of next) {
      if (!(nb in dist)) { dist[nb] = dist[cur] + 1; queue.push(nb); }
    }
  }
  _distMapCache = { id: fromId, map: dist };
  return dist;
}

// Context menu
const ctxMenu = document.createElement('div');
ctxMenu.id = 'ctx-menu';
ctxMenu.hidden = true;
document.body.appendChild(ctxMenu);

function showCtxMenu(x, y, id) {
  const isMe = id === getMeId();
  ctxMenu.innerHTML = `
    <button data-action="me">${isMe ? 'Not me' : 'Set as me'}</button>
    <button data-action="full-tree">See full tree</button>
  `;
  ctxMenu.hidden = false;
  ctxMenu.style.left = Math.min(x, window.innerWidth  - 170) + 'px';
  ctxMenu.style.top  = Math.min(y, window.innerHeight -  80) + 'px';
  ctxMenu.querySelector('[data-action="me"]').addEventListener('click', () => {
    isMe ? clearMe() : setAsMe(id);
    hideCtxMenu();
  });
  ctxMenu.querySelector('[data-action="full-tree"]').addEventListener('click', () => {
    hideCtxMenu();
    enterFullTree(id);
  });
}

function hideCtxMenu() { ctxMenu.hidden = true; }

canvas.addEventListener('contextmenu', e => {
  const card = e.target.closest('.person-card');
  if (!card) return;
  e.preventDefault();
  showCtxMenu(e.clientX, e.clientY, card.dataset.id);
});

document.getElementById('people-list').addEventListener('contextmenu', e => {
  const li = e.target.closest('li');
  if (!li) return;
  e.preventDefault();
  showCtxMenu(e.clientX, e.clientY, li.dataset.id);
});

document.addEventListener('click',   () => hideCtxMenu());
document.addEventListener('keydown', e => { if (e.key === 'Escape') hideCtxMenu(); });

// ── Full tree mode ─────────────────────────────────────────────────────────

let fullTreeActive = false;
let fullTreePrevFocusId = null;

function buildFullTreeLayout(rootId) {
  const addedSet = new Set();
  const byGen   = {};

  function addPerson(id, gen) {
    if (!id || addedSet.has(id)) return;
    addedSet.add(id);
    if (!byGen[gen]) byGen[gen] = [];
    byGen[gen].push(id);
  }

  const rootInd = getIndividual(rootId);

  // Row 0: siblings, root, spouses
  for (const famId of rootInd?.famc || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    for (const sibId of fam.children || []) addPerson(sibId, 0);
  }
  addPerson(rootId, 0);
  for (const famId of rootInd?.fams || []) {
    const fam = getFamily(famId);
    if (!fam) continue;
    const spId = fam.husb === rootId ? fam.wife : fam.husb;
    if (spId) addPerson(spId, 0);
  }

  // Ancestor BFS
  let front = [rootId], gen = 0;
  while (front.length) {
    const next = [];
    for (const id of front) {
      const ind = getIndividual(id);
      for (const famId of ind?.famc || []) {
        const fam = getFamily(famId);
        if (!fam) continue;
        for (const pid of [fam.husb, fam.wife].filter(Boolean)) {
          if (addedSet.has(pid)) continue;
          addPerson(pid, gen - 1);
          const pind = getIndividual(pid);
          for (const pfamId of pind?.fams || []) {
            const pfam = getFamily(pfamId);
            if (!pfam) continue;
            const sp = pfam.husb === pid ? pfam.wife : pfam.husb;
            if (sp && !addedSet.has(sp)) { addPerson(sp, gen - 1); next.push(sp); }
          }
          next.push(pid);
        }
      }
    }
    front = next; gen--;
    if (gen < -15) break;
  }

  // Descendant BFS
  front = [rootId]; gen = 0;
  const dVisited = new Set([rootId]);
  while (front.length) {
    const next = [];
    for (const id of front) {
      const ind = getIndividual(id);
      for (const famId of ind?.fams || []) {
        const fam = getFamily(famId);
        if (!fam) continue;
        for (const chId of fam.children || []) {
          if (dVisited.has(chId)) continue;
          dVisited.add(chId);
          addPerson(chId, gen + 1);
          const chInd = getIndividual(chId);
          for (const chFamId of chInd?.fams || []) {
            const chFam = getFamily(chFamId);
            if (!chFam) continue;
            const sp = chFam.husb === chId ? chFam.wife : chFam.husb;
            if (sp && !addedSet.has(sp)) addPerson(sp, gen + 1);
          }
          next.push(chId);
        }
      }
    }
    front = next; gen++;
    if (gen > 15) break;
  }

  // ── Position assignment ──────────────────────────────────────────────────
  // Each row is ordered so that groups sit near their relatives in adjacent
  // rows, minimising line crossings.
  const colOf = {};

  function mean(arr) {
    return arr.length ? arr.reduce((a, b) => a + b, 0) / arr.length : 0;
  }

  // Row 0: fixed order — siblings | root | spouses
  {
    const row0Set = new Set(byGen[0] || []);
    const sibs = [], sps = [];
    for (const famId of rootInd?.famc || []) {
      const fam = getFamily(famId);
      if (!fam) continue;
      for (const sibId of fam.children || []) {
        if (sibId !== rootId && row0Set.has(sibId)) sibs.push(sibId);
      }
    }
    for (const famId of rootInd?.fams || []) {
      const fam = getFamily(famId);
      if (!fam) continue;
      const spId = fam.husb === rootId ? fam.wife : fam.husb;
      if (spId && row0Set.has(spId)) sps.push(spId);
    }
    const ordered = [...sibs, rootId, ...sps];
    const start   = -Math.floor(ordered.length / 2);
    ordered.forEach((id, i) => { colOf[id] = start + i; });
  }

  function childCols(id) {
    const ind = getIndividual(id);
    const cols = [];
    for (const famId of ind?.fams || []) {
      const fam = getFamily(famId);
      if (!fam) continue;
      for (const chId of fam.children || []) {
        if (colOf[chId] !== undefined) cols.push(colOf[chId]);
      }
    }
    return cols;
  }

  function parentCols(id) {
    const ind = getIndividual(id);
    const cols = [];
    for (const famId of ind?.famc || []) {
      const fam = getFamily(famId);
      if (!fam) continue;
      if (colOf[fam.husb] !== undefined) cols.push(colOf[fam.husb]);
      if (colOf[fam.wife] !== undefined) cols.push(colOf[fam.wife]);
    }
    return cols;
  }

  function positionRow(people, getRelCols) {
    if (!people?.length) return;
    const peopleSet = new Set(people);

    // Build couple groups (husband left of wife)
    const seen   = new Set();
    const groups = [];
    for (const id of people) {
      if (seen.has(id)) continue;
      seen.add(id);
      let group = [id];
      const ind = getIndividual(id);
      for (const famId of ind?.fams || []) {
        const fam = getFamily(famId);
        if (!fam) continue;
        const spId = fam.husb === id ? fam.wife : fam.husb;
        if (spId && peopleSet.has(spId) && !seen.has(spId)) {
          seen.add(spId);
          group = ind?.sex === 'F' ? [spId, id] : [id, spId];
          break;
        }
      }
      groups.push(group);
    }

    // Ideal column for each group = mean column of their relatives
    const order = groups.map(g => {
      const cols = g.flatMap(id => getRelCols(id));
      return { g, ideal: cols.length ? mean(cols) : Infinity };
    });
    order.sort((a, b) => a.ideal - b.ideal);

    const flat         = order.flatMap(({ g }) => g);
    const validIdeals  = order.map(x => x.ideal).filter(v => v !== Infinity);
    const centre       = validIdeals.length ? Math.round(mean(validIdeals)) : 0;
    const start        = centre - Math.floor(flat.length / 2);
    flat.forEach((id, i) => { colOf[id] = start + i; });
  }

  const allGens = Object.keys(byGen).map(Number).sort((a, b) => a - b);
  // Ancestors: closest row first so each row references already-placed row below
  for (const g of allGens.filter(g => g < 0).reverse()) positionRow(byGen[g], childCols);
  // Descendants: closest row first
  for (const g of allGens.filter(g => g > 0))           positionRow(byGen[g], parentCols);

  const nodes = [];
  for (const g of allGens) {
    for (const id of byGen[g]) {
      const role = id === rootId ? 'focus' : g < 0 ? 'ancestor' : g > 0 ? 'descendant' : 'peer';
      nodes.push({ id, col: colOf[id] ?? 0, row: g, role });
    }
  }
  return nodes;
}

function drawFullTreeConnectors(svg, positioned) {
  const byId = Object.fromEntries(positioned.map(n => [n.id, n]));

  for (const fam of Object.values(families)) {
    const hNode    = fam.husb ? byId[fam.husb] : null;
    const wNode    = fam.wife ? byId[fam.wife] : null;
    const children = (fam.children || []).map(id => byId[id]).filter(Boolean);

    // Spouse line (dashed, same row only)
    if (hNode && wNode && hNode.row === wNode.row) {
      const left  = hNode.x <= wNode.x ? hNode : wNode;
      const right = hNode.x <= wNode.x ? wNode : hNode;
      const sp = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      sp.classList.add('spouse-line');
      sp.setAttribute('d', `M ${left.x + CARD_W} ${left.y + CARD_H / 2} L ${right.x} ${right.y + CARD_H / 2}`);
      svg.appendChild(sp);
    }

    if (!children.length) continue;

    // Parent stem: midpoint between both parents, or the single parent
    let pX, pY;
    if (hNode && wNode && hNode.row === wNode.row) {
      pX = ((hNode.x + CARD_W / 2) + (wNode.x + CARD_W / 2)) / 2;
      pY = hNode.y + CARD_H;
    } else if (hNode) {
      pX = hNode.x + CARD_W / 2; pY = hNode.y + CARD_H;
    } else if (wNode) {
      pX = wNode.x + CARD_W / 2; pY = wNode.y + CARD_H;
    } else {
      continue;
    }

    const childY = Math.min(...children.map(ch => ch.y));
    const midY   = pY + (childY - pY) * 0.4;

    if (children.length === 1) {
      const chX = children[0].x + CARD_W / 2;
      addPath(svg, `M ${pX} ${pY} L ${pX} ${midY} L ${chX} ${midY} L ${chX} ${children[0].y}`);
    } else {
      // T-bar: stem down → horizontal bar → drop to each child
      const childXs = children.map(ch => ch.x + CARD_W / 2);
      addPath(svg, `M ${pX} ${pY} L ${pX} ${midY}`);
      addPath(svg, `M ${Math.min(...childXs)} ${midY} L ${Math.max(...childXs)} ${midY}`);
      for (const ch of children) {
        addPath(svg, `M ${ch.x + CARD_W / 2} ${midY} L ${ch.x + CARD_W / 2} ${ch.y}`);
      }
    }
  }
}

function renderFullTree(rootId) {
  canvas.innerHTML = '';
  const nodes = buildFullTreeLayout(rootId);
  if (!nodes.length) return;

  const positioned = nodes.map(n => ({
    ...n,
    x: n.col * COL_W - CARD_W / 2,
    y: n.row * ROW_H - CARD_H / 2,
  }));

  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.classList.add('connectors');
  canvas.appendChild(svg);

  for (const n of positioned) {
    const ind = getIndividual(n.id);
    if (!ind) continue;
    const card = document.createElement('div');
    card.className = `person-card ${cardSexClass(ind)}`;
    if (n.id === rootId) card.classList.add('selected');
    card.style.left = n.x + 'px';
    card.style.top  = n.y + 'px';
    card.dataset.id = n.id;
    const ls  = cardDates(ind);
    const isMe = n.id === getMeId();
    card.innerHTML = `
      <div class="card-name">${esc(ind.name)}</div>
      ${ls ? `<div class="card-dates">${esc(ls)}</div>` : ''}
      ${isMe ? '<div class="card-me">me</div>' : ''}
    `;
    canvas.appendChild(card);
  }

  drawFullTreeConnectors(svg, positioned);

  const focusNode = positioned.find(n => n.id === rootId);
  if (focusNode) {
    zoomScale = 0.65;
    panX = -(focusNode.x + CARD_W / 2);
    panY = -(focusNode.y + CARD_H / 2);
    applyTransform();
  }
}

function enterFullTree(rootId) {
  fullTreeActive = true;
  fullTreePrevFocusId = focusId;
  document.body.classList.add('full-tree-mode');
  renderFullTree(rootId);
}

function exitFullTree() {
  fullTreeActive = false;
  document.body.classList.remove('full-tree-mode');
  const restoreId = fullTreePrevFocusId || focusId;
  if (restoreId) navigateTo(restoreId);
}

// ── People panel & upcoming toggle ────────────────────────────────────────

(function () {
  const searchBtn     = document.getElementById('mobile-search-btn');
  const closeBtn      = document.getElementById('people-close');
  const peoplePanel   = document.getElementById('people-panel');
  const upcomingPanel = document.getElementById('upcoming-panel');
  const upcomingToggle = document.getElementById('upcoming-toggle');

  function isMobile() { return window.matchMedia('(max-width: 768px)').matches; }

  if (searchBtn && peoplePanel) {
    searchBtn.addEventListener('click', () => {
      if (isMobile()) {
        peoplePanel.classList.add('mobile-open');
      } else {
        peoplePanel.classList.remove('people-hidden');
      }
      document.getElementById('people-search')?.focus();
    });
  }

  if (closeBtn && peoplePanel) {
    closeBtn.addEventListener('click', () => {
      if (isMobile()) {
        peoplePanel.classList.remove('mobile-open');
      } else {
        peoplePanel.classList.add('people-hidden');
      }
    });
  }

  if (upcomingToggle && upcomingPanel) {
    upcomingToggle.addEventListener('click', () => {
      const collapsed = upcomingPanel.classList.toggle('collapsed');
      upcomingToggle.textContent = collapsed ? '▸' : '▾';
      upcomingToggle.setAttribute('aria-label', collapsed ? 'Expand upcoming' : 'Collapse upcoming');
    });
  }

  document.getElementById('full-tree-close').addEventListener('click', exitFullTree);

  // Tap the tree (not a card) to dismiss the detail panel on mobile
  document.getElementById('tree-viewport')?.addEventListener('click', e => {
    if (e.target.closest('.person-card')) return;
    if (isMobile()) {
      document.getElementById('detail-panel').hidden = true;
    }
  });
}());

// ── Logout button ──────────────────────────────────────────────────────────

(function () {
  const headerInner = document.querySelector('.header-inner');
  if (!headerInner) return;
  const btn = document.createElement('button');
  btn.id = 'logout-btn';
  btn.textContent = 'Sign out';
  btn.addEventListener('click', function () {
    fetch('auth.php?action=logout', {
      method: 'POST',
      headers: getAuthHeaders(),
    }).finally(function () {
      handleUnauthorized();
    });
  });
  // Insert after the search icon if present, so both sit together on the right
  const searchBtn = document.getElementById('mobile-search-btn');
  if (searchBtn) {
    searchBtn.insertAdjacentElement('afterend', btn);
  } else {
    headerInner.appendChild(btn);
  }
}());

// ── Bootstrap ───────────────────────────────────────────────────────────────

async function init() {
  _distMapCache = { id: null, map: null };
  canvas.innerHTML = '<p style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:var(--text-muted)">Loading…</p>';

  try {
    await fetchIndex();
  } catch(e) {
    canvas.innerHTML = '<p style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#b91c1c">Failed to load data.</p>';
    return;
  }

  const ids = Object.keys(individuals);
  if (!ids.length) return;

  const savedId = getMeId();
  const startId = savedId || ids.reduce((best, id) => {
    const ind       = individuals[id];
    const bestInd   = individuals[best];
    const score     = (ind.fams?.length || 0) * 2     + (ind.famc?.length || 0);
    const bestScore = (bestInd.fams?.length || 0) * 2 + (bestInd.famc?.length || 0);
    return score > bestScore ? id : best;
  }, ids[0]);

  buildPeopleList();
  navigateTo(startId);
  buildUpcoming();
}

init();
