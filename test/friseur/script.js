/* ===========================================================
   Friseursalon – Terminbuchung & Interaktion
   Datenhaltung: eigenes PHP/MySQL-Backend (api/index.php) bei netcup –
   echter, geräteübergreifender Kalender, Login per E-Mail/Name + Passwort.
   =========================================================== */

/* ---------- API-Verbindung ---------- */
const API_BASE = "api/index.php";
async function api(action, { method = "GET", body } = {}) {
  const url = `${API_BASE}?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, {
    method,
    headers: body !== undefined ? { "Content-Type": "application/json" } : undefined,
    body: body !== undefined ? JSON.stringify(body) : undefined,
    credentials: "same-origin"
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok) {
    const err = new Error(json.error || "Es ist ein Fehler aufgetreten.");
    err.status = res.status;
    throw err;
  }
  return json;
}
function apiGet(action, params = {}) {
  const qs = new URLSearchParams(params).toString();
  return fetch(`${API_BASE}?action=${encodeURIComponent(action)}${qs ? "&" + qs : ""}`, { credentials: "same-origin" })
    .then(async r => {
      const json = await r.json().catch(() => ({}));
      if (!r.ok) { const err = new Error(json.error || "Fehler"); err.status = r.status; throw err; }
      return json;
    });
}

/* ---------- Konfiguration ---------- */

// Mitarbeiter:innen für Kalender & Terminplanung
const STAFF = [
  { id: "andreas", name: "Andreas" },
  { id: "yvonne",  name: "Yvonne" },
  { id: "caro",    name: "Caro" }
];
function staffNameById(staffId) {
  return STAFF.find(s => s.id === staffId)?.name || staffId;
}

// Buchbare Anwendungen mit Dauer (Minuten) und Richtpreis
// Alle Leistungen aus der Übersicht sind online buchbar.
const SERVICES = [
  { id: "nur-schneiden", name: "Nur Schneiden",              duration: 20,  price: "ab 18 €" },
  { id: "schneiden",     name: "Waschen, Schneiden & Föhnen", duration: 45,  price: "ab 32 €" },
  { id: "foehnen",       name: "Föhnen & Styling",            duration: 20,  price: "ab 15 €" },
  { id: "dauerwelle",    name: "Dauerwelle",                  duration: 120, price: "ab 75 €" },
  { id: "faerben",       name: "Färben",                      duration: 90,  price: "ab 55 €" },
  { id: "straehnen",     name: "Strähnen & Balayage",         duration: 120, price: "ab 65 €" },
  { id: "herren-bart",   name: "Herren- & Bartpflege",        duration: 25,  price: "ab 12 €" },
  { id: "kinderhaarschnitt", name: "Kinderhaarschnitt",       duration: 20,  price: "ab 14 €" }
];
function findServiceIdByName(name) {
  return SERVICES.find(s => s.name === name)?.id || SERVICES[0].id;
}

// Öffnungszeiten: 0=So ... 6=Sa
const OPENING_HOURS = {
  1: { open: "09:00", close: "18:00" }, // Mo geschlossen -> siehe CLOSED_DAYS
  2: { open: "09:00", close: "18:00" },
  3: { open: "09:00", close: "18:00" },
  4: { open: "09:00", close: "18:00" },
  5: { open: "09:00", close: "20:00" },
  6: { open: "09:00", close: "14:00" }
};
const CLOSED_DAYS = [0, 1]; // So, Mo geschlossen

const SLOT_STEP = 15; // Minuten-Raster für Terminvorschläge

/* ---------- Datum-Helpers ---------- */
function addDays(date, n) { const d = new Date(date); d.setDate(d.getDate() + n); return d; }
function fmtDate(d) { return d.toISOString().slice(0, 10); }
function toMinutes(hhmm) { const [h, m] = hhmm.split(":").map(Number); return h * 60 + m; }
function toHHMM(mins) {
  const h = Math.floor(mins / 60).toString().padStart(2, "0");
  const m = (mins % 60).toString().padStart(2, "0");
  return `${h}:${m}`;
}
function weekdayLabel(d) {
  return d.toLocaleDateString("de-DE", { weekday: "short", day: "2-digit", month: "2-digit" });
}
function nextAvailableDays(count) {
  const days = [];
  let d = new Date();
  while (days.length < count) {
    if (!CLOSED_DAYS.includes(d.getDay())) days.push(new Date(d));
    d = addDays(d, 1);
  }
  return days;
}
function allSlotsForDate(dateStr) {
  const d = new Date(dateStr + "T00:00:00");
  const hours = OPENING_HOURS[d.getDay()];
  if (!hours || CLOSED_DAYS.includes(d.getDay())) return [];
  const slots = [];
  for (let start = toMinutes(hours.open); start < toMinutes(hours.close); start += SLOT_STEP) {
    slots.push(toHHMM(start));
  }
  return slots;
}
function fullDaySlots() {
  const slots = [];
  for (let start = 0; start < 24 * 60; start += SLOT_STEP) slots.push(toHHMM(start));
  return slots;
}
function manualTimeOptionsHTML(selected) {
  return fullDaySlots().map(t => `<option value="${t}" ${t === selected ? "selected" : ""}>${t} Uhr</option>`).join("");
}

/* ===========================================================
   Auth & Profil (Supabase Auth + profiles-Tabelle)
   =========================================================== */
// Gemeinsamer Session-Zustand: dieselbe Anmeldung gilt sowohl für den
// Kundenbereich als auch für den Salon-Team-Bereich (#salon).
let currentProfile = null; // { id, email, role: 'customer'|'staff'|'owner', staffId, name } oder null

async function loadCurrentProfile() {
  try {
    const { profile } = await apiGet("me");
    if (!profile) { currentProfile = null; return null; }
    currentProfile = {
      id: profile.id,
      email: profile.identifier,
      role: profile.role,
      staffId: profile.staff_id,
      name: profile.display_name
    };
    return currentProfile;
  } catch (e) {
    currentProfile = null;
    return null;
  }
}

async function signUpCustomer(email, password, displayName) {
  try {
    const { profile } = await api("signup", { method: "POST", body: { email, password, display_name: displayName } });
    // Backend legt nach der Registrierung noch keine Session an, solange die
    // E-Mail-Adresse nicht über den zugesendeten Link bestätigt wurde.
    currentProfile = profile ? { id: profile.id, email: profile.identifier, role: profile.role, staffId: profile.staff_id, name: profile.display_name } : null;
    return { data: { profile: currentProfile, session: !!profile }, error: null };
  } catch (e) {
    return { data: null, error: { message: e.message } };
  }
}
async function signInEmail(email, password) {
  try {
    const { profile } = await api("signin", { method: "POST", body: { email, password } });
    currentProfile = profile ? { id: profile.id, email: profile.identifier, role: profile.role, staffId: profile.staff_id, name: profile.display_name } : null;
    return { data: { profile: currentProfile }, error: null };
  } catch (e) {
    return { data: null, error: { message: e.message } };
  }
}
async function signOutUser() {
  try { await api("signout", { method: "POST", body: {} }); } catch (e) {}
  currentProfile = null;
}

/* ===========================================================
   Datenzugriff (Supabase-Tabellen)
   =========================================================== */
function mapBooking(row) {
  return {
    id: row.id,
    date: row.date,
    start: row.start_time.slice(0, 5),
    end: row.end_time.slice(0, 5),
    service: row.service,
    user: row.customer_name,
    staffId: row.staff_id,
    staff: row.staff_name,
    manual: row.manual,
    customerId: row.customer_id
  };
}

// Für Kund:innen: nur Zeiten/Mitarbeiter:in (keine Namen)
async function dbFetchBusySlots(dateStr, staffId) {
  try {
    const { rows } = await apiGet("busy_slots", { date: dateStr, staff_id: staffId });
    return rows.map(r => ({ start: r.start_time.slice(0, 5), end: r.end_time.slice(0, 5) }));
  } catch (e) { console.error(e); return []; }
}

// Für Team/Inhaber: volle Buchungsdaten (Zugriff wird serverseitig begrenzt)
async function dbFetchBookingsAdmin({ date, staffId, fromDate } = {}) {
  try {
    const params = {};
    if (date) params.date = date;
    if (fromDate) params.from = fromDate;
    if (staffId && staffId !== "alle") params.staff_id = staffId;
    const { bookings } = await apiGet("bookings_list", params);
    return bookings.map(mapBooking);
  } catch (e) { console.error(e); return []; }
}

async function dbFetchBookingsForCustomer(customerId) {
  try {
    // customerId ist gesetzt, wenn ein:e Inhaber:in gezielt die Buchungen
    // einer bestimmten Kundschaft abruft; ohne Parameter liefert das Backend
    // ohnehin nur die eigenen Buchungen der eingeloggten Person zurück.
    const params = customerId ? { customer_id: customerId } : {};
    const { bookings } = await apiGet("bookings_list", params);
    return bookings.map(mapBooking);
  } catch (e) { console.error(e); return []; }
}

async function dbInsertBooking({ date, start, end, service, user, staffId, staff, manual, customerId }) {
  const { id } = await api("booking_create", {
    method: "POST",
    body: {
      customer_name: user, service, date,
      start_time: start, end_time: end,
      staff_id: staffId, staff_name: staff,
      manual: !!manual
    }
  });
  return mapBooking({ id, customer_id: customerId || null, customer_name: user, service, date, start_time: start, end_time: end, staff_id: staffId, staff_name: staff, manual: !!manual });
}

async function dbUpdateBooking(id, patch) {
  const dbPatch = { id };
  if (patch.date) dbPatch.date = patch.date;
  if (patch.start) dbPatch.start_time = patch.start;
  if (patch.end) dbPatch.end_time = patch.end;
  if (patch.service) dbPatch.service = patch.service;
  if (patch.user) dbPatch.customer_name = patch.user;
  if (patch.staffId) dbPatch.staff_id = patch.staffId;
  if (patch.staff) dbPatch.staff_name = patch.staff;
  await api("booking_update", { method: "POST", body: dbPatch });
}

async function dbDeleteBooking(id) {
  await api("booking_delete", { method: "POST", body: { id } });
}

async function dbFetchReleasedSlots(dateStr, staffId) {
  try {
    const { times } = await apiGet("released_slots_list", { date: dateStr, staff_id: staffId });
    return times.map(t => t.slice(0, 5));
  } catch (e) { console.error(e); return []; }
}

async function toggleReleasedSlot(dateStr, staffId, time) {
  await api("released_slot_toggle", { method: "POST", body: { staff_id: staffId, date: dateStr, time } });
}

async function setAllReleased(dateStr, staffId, released) {
  if (!released) {
    await api("released_slots_clear_day", { method: "POST", body: { staff_id: staffId, date: dateStr } });
    return;
  }
  const rows = allSlotsForDate(dateStr).map(t => ({ staff_id: staffId, date: dateStr, time: t }));
  if (rows.length === 0) return;
  await api("released_slots_bulk", { method: "POST", body: { rows } });
}

async function hasAnyReleaseForDate(dateStr) {
  const lists = await Promise.all(STAFF.map(s => dbFetchReleasedSlots(dateStr, s.id)));
  return lists.some(l => l.length > 0);
}

async function dbFetchCustomerProfiles() {
  try {
    const { profiles } = await api("profiles_list", {});
    return profiles;
  } catch (e) { console.error(e); return []; }
}
/* ---------- Freie Zeitfenster berechnen (Kundenseite) ---------- */
async function computeFreeSlotsForStaff(dateStr, duration, staffId) {
  const d = new Date(dateStr + "T00:00:00");
  const hours = OPENING_HOURS[d.getDay()];
  if (!hours || CLOSED_DAYS.includes(d.getDay())) return [];

  const openMin = toMinutes(hours.open);
  const closeMin = toMinutes(hours.close);
  const [busy, released] = await Promise.all([
    dbFetchBusySlots(dateStr, staffId),
    dbFetchReleasedSlots(dateStr, staffId)
  ]);
  const releasedSet = new Set(released);
  const now = new Date();
  const isToday = fmtDate(now) === dateStr;
  const nowMin = now.getHours() * 60 + now.getMinutes();

  const free = [];
  for (let start = openMin; start + duration <= closeMin; start += SLOT_STEP) {
    const startHHMM = toHHMM(start);
    if (!releasedSet.has(startHHMM)) continue;
    const end = start + duration;
    const overlaps = busy.some(b => start < toMinutes(b.end) && end > toMinutes(b.start));
    const pastToday = isToday && start <= nowMin;
    if (!overlaps && !pastToday) free.push(startHHMM);
  }
  return free;
}

async function computeFreeSlots(dateStr, duration, staffId) {
  if (staffId === "egal") {
    const lists = await Promise.all(STAFF.map(s => computeFreeSlotsForStaff(dateStr, duration, s.id)));
    const set = new Set();
    lists.forEach(l => l.forEach(t => set.add(t)));
    return [...set].sort();
  }
  return computeFreeSlotsForStaff(dateStr, duration, staffId);
}

async function resolveStaffForSlot(dateStr, start, duration, staffId) {
  if (staffId !== "egal") return staffId;
  for (const s of STAFF) {
    const slots = await computeFreeSlotsForStaff(dateStr, duration, s.id);
    if (slots.includes(start)) return s.id;
  }
  return STAFF[0].id;
}

// Kollisionsprüfung für Team/Inhaber (manuelles Eintragen/Bearbeiten) – nutzt die
// vollen Buchungsdaten inkl. ID, damit der eigene Termin beim Bearbeiten ausgenommen werden kann
async function hasOverlap(date, staffId, start, duration, ignoreId) {
  const bookings = await dbFetchBookingsAdmin({ date, staffId });
  const startMin = toMinutes(start), endMin = startMin + duration;
  return bookings.some(b => {
    if (b.id === ignoreId) return false;
    return startMin < toMinutes(b.end) && endMin > toMinutes(b.start);
  });
}

/* ---------- Kalender-Helpers (ICS-Download & Google-Kalender-Link) ---------- */
const SALON_ADDRESS = "Friseursalon, München";

function bookingToICSDates(booking) {
  const toICS = (dateStr, timeStr) => {
    const local = new Date(`${dateStr}T${timeStr}:00`);
    return local.toISOString().replace(/[-:]/g, "").split(".")[0] + "Z";
  };
  return { start: toICS(booking.date, booking.start), end: toICS(booking.date, booking.end) };
}

function downloadICS(booking) {
  const { start, end } = bookingToICSDates(booking);
  const stamp = new Date().toISOString().replace(/[-:]/g, "").split(".")[0] + "Z";
  const ics = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    "PRODID:-//Friseursalon//Terminbuchung//DE",
    "CALSCALE:GREGORIAN",
    "BEGIN:VEVENT",
    `UID:${booking.id}@friseur-andreas-graf.local`,
    `DTSTAMP:${stamp}`,
    `DTSTART:${start}`,
    `DTEND:${end}`,
    `SUMMARY:${booking.service} bei ${booking.staff} – Friseursalon`,
    `LOCATION:${SALON_ADDRESS}`,
    `DESCRIPTION:Termin für ${booking.user} bei ${booking.staff}, Friseursalon.`,
    "BEGIN:VALARM",
    "TRIGGER:-PT1H",
    "ACTION:DISPLAY",
    "DESCRIPTION:Erinnerung: Friseurtermin in 1 Stunde",
    "END:VALARM",
    "END:VEVENT",
    "END:VCALENDAR"
  ].join("\r\n");

  const blob = new Blob([ics], { type: "text/calendar;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `termin-${booking.date}-${booking.start.replace(":", "")}.ics`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function googleCalendarLink(booking) {
  const { start, end } = bookingToICSDates(booking);
  const params = new URLSearchParams({
    action: "TEMPLATE",
    text: `${booking.service} bei ${booking.staff} – Friseursalon`,
    dates: `${start}/${end}`,
    location: SALON_ADDRESS,
    details: `Termin für ${booking.user} bei ${booking.staff}, Friseursalon.`
  });
  return `https://calendar.google.com/calendar/render?${params.toString()}`;
}

/* ===========================================================
   Routing (Hash-basiert): #home #leistungen #termin #kontakt
   #impressum #datenschutz #salon
   =========================================================== */
function handleRoute() {
  const hash = (location.hash || "#home").replace("#", "");
  const standalonePages = ["impressum", "datenschutz", "salon"];
  const mainEl = document.getElementById("site-main");
  const footerEl = document.querySelector("footer");

  standalonePages.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.add("hidden");
  });

  if (standalonePages.includes(hash)) {
    mainEl.classList.add("hidden");
    footerEl.classList.add("hidden");
    document.getElementById(hash).classList.remove("hidden");
    window.scrollTo(0, 0);
    if (hash === "salon") renderAdmin();
  } else {
    mainEl.classList.remove("hidden");
    footerEl.classList.remove("hidden");
    const target = document.getElementById(hash);
    if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
    else window.scrollTo(0, 0);
  }
  closeMobileNav();
}
function closeMobileNav() {
  document.getElementById("nav-links")?.classList.remove("open");
}

/* ===========================================================
   Terminbuchung (Kundenbereich)
   =========================================================== */
const bookingState = { serviceId: null, date: null, start: null, staffId: null };
let customerView = "buchen"; // "buchen" | "meine"
let customerAuthMode = "login"; // "login" | "signup"

function resetBookingState() {
  bookingState.serviceId = null; bookingState.date = null; bookingState.start = null; bookingState.staffId = null;
}

// Merkt sich die aktuelle Terminauswahl über den Registrierungs-/Bestätigungs-Umweg
// hinweg (E-Mail-Link öffnet meist einen neuen Tab, in dem der Auswahl-Zustand sonst
// verloren wäre), damit man nach dem Anmelden direkt weiterbuchen kann.
const BOOKING_DRAFT_KEY = "friseur_booking_draft";
function saveBookingDraft() {
  try {
    localStorage.setItem(BOOKING_DRAFT_KEY, JSON.stringify({ ...bookingState, savedAt: Date.now() }));
  } catch (e) { /* localStorage evtl. nicht verfügbar */ }
}
function restoreBookingDraftIfAny() {
  try {
    const raw = localStorage.getItem(BOOKING_DRAFT_KEY);
    if (!raw) return;
    const draft = JSON.parse(raw);
    if (!draft.savedAt || Date.now() - draft.savedAt > 24 * 60 * 60 * 1000) {
      localStorage.removeItem(BOOKING_DRAFT_KEY);
      return;
    }
    if (draft.serviceId) bookingState.serviceId = draft.serviceId;
    if (draft.date) bookingState.date = draft.date;
    if (draft.start) bookingState.start = draft.start;
    if (draft.staffId) bookingState.staffId = draft.staffId;
  } catch (e) { /* ignorieren */ }
}
function clearBookingDraft() {
  try { localStorage.removeItem(BOOKING_DRAFT_KEY); } catch (e) { /* ignorieren */ }
}

async function renderBooking() {
  const root = document.getElementById("booking-app");
  if (!root) return;
  root.innerHTML = bookingShellHTML(currentProfile);
  await bindBookingShell(currentProfile);
}

// embedded=true: wird innerhalb eines anderen Schritts (z. B. Buchungs-Assistent) gezeigt,
// ohne den einleitenden Infokasten doppelt anzuzeigen.
function authFormHTML(embedded) {
  const isSignup = customerAuthMode === "signup";
  return `
    ${embedded ? "" : `
      <div class="alert alert-info">
        Die Online-Terminbuchung steht allen registrierten Kund:innen zur Verfügung.
        Neu hier? Einfach mit E-Mail-Adresse registrieren – in wenigen Sekunden erledigt.
      </div>
    `}
    <div class="steps" style="margin-bottom:20px;">
      <span class="step-pill ${!isSignup ? "active" : ""}" id="auth-tab-login" style="cursor:pointer;">Anmelden</span>
      <span class="step-pill ${isSignup ? "active" : ""}" id="auth-tab-signup" style="cursor:pointer;">Registrieren</span>
    </div>
    <form id="auth-form">
      <div id="auth-error"></div>
      ${isSignup ? `
        <div class="form-row">
          <label for="auth-name">Ihr Name</label>
          <input id="auth-name" type="text" autocomplete="name" placeholder="Vor- und Nachname" required>
        </div>
      ` : ""}
      <div class="form-row">
        <label for="auth-email">${isSignup ? "E-Mail-Adresse" : "E-Mail-Adresse oder Benutzername"}</label>
        <input id="auth-email" type="${isSignup ? "email" : "text"}" autocomplete="${isSignup ? "email" : "username"}" placeholder="${isSignup ? "ihre@email.de" : "ihre@email.de oder Benutzername"}" required>
      </div>
      <div class="form-row">
        <label for="auth-pass">Passwort</label>
        <input id="auth-pass" type="password" autocomplete="${isSignup ? "new-password" : "current-password"}" placeholder="mind. 6 Zeichen" required minlength="6">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">${isSignup ? "Registrieren" : "Anmelden"}</button>
      </div>
    </form>
  `;
}

// rerender: wird nach Tab-Wechsel (Anmelden/Registrieren) und nach erfolgreichem
// Login/Registrieren aufgerufen – zeigt je nach Kontext die Buchungsübersicht,
// "Meine Termine" oder (mitten im Assistenten) direkt den Bestätigen-Schritt.
function bindAuthForm(rerender) {
  document.getElementById("auth-tab-login").addEventListener("click", () => {
    customerAuthMode = "login";
    rerender();
  });
  document.getElementById("auth-tab-signup").addEventListener("click", () => {
    customerAuthMode = "signup";
    rerender();
  });

  document.getElementById("auth-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const email = document.getElementById("auth-email").value.trim();
    const pass = document.getElementById("auth-pass").value;
    const errBox = document.getElementById("auth-error");
    const submitBtn = e.target.querySelector("button[type=submit]");
    submitBtn.disabled = true;

    try {
      if (customerAuthMode === "signup") {
        const name = document.getElementById("auth-name").value.trim();
        const { data, error } = await signUpCustomer(email, pass, name);
        if (error) throw error;
        if (!data.session) {
          saveBookingDraft();
          errBox.innerHTML = `<div class="alert alert-ok">✅ Fast fertig! Bitte bestätigen Sie die E-Mail, die wir an ${email} geschickt haben, und melden Sie sich danach an. Ihre Terminauswahl bleibt dabei erhalten.</div>`;
          customerAuthMode = "login";
          submitBtn.disabled = false;
          return;
        }
      } else {
        const { error } = await signInEmail(email, pass);
        if (error) throw error;
      }
      await loadCurrentProfile();
      renderNavUser();
      rerender();
    } catch (err) {
      errBox.innerHTML = `<div class="alert alert-error">${err.message || "Anmeldung fehlgeschlagen."}</div>`;
      submitBtn.disabled = false;
    }
  });
}

function bookingShellHTML(profile) {
  return `
    ${profile ? `
      <p class="hint" style="text-align:right;">
        Angemeldet als <strong>${profile.name}</strong> · <button class="link-danger" id="logout-btn">abmelden</button>
      </p>
    ` : ""}
    <div class="steps">
      <span class="step-pill ${customerView === "buchen" ? "active" : ""}" id="ctab-buchen" style="cursor:pointer;">Termin buchen</span>
      <span class="step-pill ${customerView === "meine" ? "active" : ""}" id="ctab-meine" style="cursor:pointer;">Meine Termine</span>
    </div>
    <div id="customer-tab-content"><p class="hint">Lädt …</p></div>
  `;
}

async function bindBookingShell(profile) {
  document.getElementById("logout-btn")?.addEventListener("click", async () => {
    await signOutUser();
    resetBookingState();
    customerView = "buchen";
    renderBooking();
    renderNavUser();
  });

  document.getElementById("ctab-buchen").addEventListener("click", () => { customerView = "buchen"; renderBooking(); });
  document.getElementById("ctab-meine").addEventListener("click", () => { customerView = "meine"; renderBooking(); });

  if (customerView === "meine") {
    if (!profile) {
      const content = document.getElementById("customer-tab-content");
      content.innerHTML = `
        <div class="alert alert-info">Bitte melden Sie sich an, um Ihre Termine zu sehen.</div>
        ${authFormHTML(true)}
      `;
      bindAuthForm(() => renderBooking());
    } else {
      await renderCustomerAppointmentsTab(profile);
    }
  } else {
    await renderCustomerBookingTab(profile);
  }
}

// Neue Reihenfolge: 1) Termin wählen (Anwendung + Datum + Uhrzeit), ohne Login
// bereits möglich · 2) Mitarbeiter:in wählen (nur wer zu dieser Uhrzeit frei ist)
// · 3) Anmelden/Registrieren (falls nötig) & Bestätigen. So können Interessent:innen
// erst prüfen, ob überhaupt etwas Passendes frei ist, bevor sie sich registrieren.
async function renderCustomerBookingTab(profile) {
  const content = document.getElementById("customer-tab-content");
  const service = SERVICES.find(s => s.id === bookingState.serviceId);
  const timeChosen = !!(service && bookingState.date && bookingState.start);
  const staffChosen = !!bookingState.staffId;
  const step = !timeChosen ? 1 : (!staffChosen ? 2 : 3);

  let slotsHTML = "";
  if (service && bookingState.date) {
    slotsHTML = await renderTermineSlotsHTML(service);
  }

  let staffHTML = "";
  if (timeChosen) {
    staffHTML = await renderStaffPickHTML(service);
  }

  let finalHTML = "";
  if (timeChosen && staffChosen) {
    finalHTML = profile
      ? confirmSummaryHTML(service)
      : `<div class="alert alert-info">Fast geschafft – zum Abschluss bitte anmelden oder registrieren:</div>${authFormHTML(true)}`;
  }

  content.innerHTML = `
    <div class="steps">
      <span class="step-pill ${step >= 1 ? (step > 1 ? "done" : "active") : ""}">1 · Termin wählen</span>
      <span class="step-pill ${step === 2 ? "active" : step > 2 ? "done" : ""}">2 · Mitarbeiter:in wählen</span>
      <span class="step-pill ${step === 3 ? "active" : ""}">3 · Anmelden &amp; Bestätigen</span>
    </div>

    <div class="form-row">
      <label>Welche Anwendung möchten Sie buchen?</label>
      <div class="service-pick" id="service-pick">
        ${SERVICES.map(s => `
          <label>
            <input type="radio" name="service" value="${s.id}" ${bookingState.serviceId === s.id ? "checked" : ""}>
            <span>${s.name}<br><small>${s.duration} Min · ${s.price}</small></span>
          </label>
        `).join("")}
      </div>
    </div>

    ${service ? `
      <div class="form-row">
        <label for="date-pick">Datum wählen</label>
        <select id="date-pick">
          <option value="">– bitte wählen –</option>
          ${nextAvailableDays(14).map(d => `
            <option value="${fmtDate(d)}" ${bookingState.date === fmtDate(d) ? "selected" : ""}>
              ${weekdayLabel(d)}
            </option>`).join("")}
        </select>
      </div>
    ` : ""}

    ${slotsHTML}
    ${staffHTML}
    ${finalHTML}

    <div id="booking-confirm"></div>
  `;

  document.querySelectorAll('#service-pick input[name="service"]').forEach(input => {
    input.addEventListener("change", (e) => {
      bookingState.serviceId = e.target.value;
      bookingState.date = null; bookingState.start = null; bookingState.staffId = null;
      renderCustomerBookingTab(profile);
    });
  });

  document.getElementById("date-pick")?.addEventListener("change", (e) => {
    bookingState.date = e.target.value || null;
    bookingState.start = null; bookingState.staffId = null;
    renderCustomerBookingTab(profile);
  });

  content.querySelectorAll(".slot-btn").forEach(btn => {
    btn.addEventListener("click", () => {
      bookingState.start = btn.dataset.slot;
      bookingState.staffId = null;
      renderCustomerBookingTab(profile);
    });
  });

  document.querySelectorAll('#staff-pick input[name="staff"]').forEach(input => {
    input.addEventListener("change", (e) => {
      bookingState.staffId = e.target.value;
      renderCustomerBookingTab(profile);
    });
  });

  if (profile) {
    document.getElementById("confirm-btn")?.addEventListener("click", async (e) => {
      e.target.disabled = true;
      const svc = SERVICES.find(s => s.id === bookingState.serviceId);
      const assignedStaffId = await resolveStaffForSlot(bookingState.date, bookingState.start, svc.duration, bookingState.staffId);
      const endMin = toMinutes(bookingState.start) + svc.duration;
      let booking;
      try {
        booking = await dbInsertBooking({
          date: bookingState.date,
          start: bookingState.start,
          end: toHHMM(endMin),
          service: svc.name,
          user: profile.name,
          staffId: assignedStaffId,
          staff: staffNameById(assignedStaffId),
          customerId: profile.id
        });
      } catch (err) {
        document.getElementById("booking-confirm").innerHTML = `<div class="alert alert-error">Buchung fehlgeschlagen: ${err.message || err}</div>`;
        e.target.disabled = false;
        return;
      }

      document.getElementById("booking-confirm").innerHTML = `
        <div class="alert alert-ok">
          ✅ Termin gebucht: <strong>${svc.name}</strong> bei <strong>${booking.staff}</strong> am
          <strong>${new Date(bookingState.date + "T00:00:00").toLocaleDateString("de-DE", { weekday: "long", day: "2-digit", month: "2-digit" })}</strong>
          um <strong>${booking.start} Uhr</strong> (bis ${booking.end} Uhr).
          <br><span class="hint">Eine Bestätigung wurde an Ihre E-Mail-Adresse gesendet. Sie können den Termin außerdem
          direkt in Ihren Kalender übernehmen, oder unter „Meine Termine" jederzeit nachsehen:</span>
          <div class="form-actions" style="margin-top:10px;">
            <button type="button" class="btn btn-light" id="ics-btn-${booking.id}">📅 .ics herunterladen</button>
            <a class="btn btn-light" href="${googleCalendarLink(booking)}" target="_blank" rel="noopener">📅 Zu Google Kalender</a>
          </div>
        </div>`;
      document.getElementById(`ics-btn-${booking.id}`).addEventListener("click", () => downloadICS(booking));

      resetBookingState();
      clearBookingDraft();
    });
  } else if (document.getElementById("auth-form")) {
    bindAuthForm(() => renderCustomerBookingTab(currentProfile));
  }
}

// Schritt 1: freie Zeitfenster über ALLE Mitarbeiter:innen hinweg (Vereinigung) –
// wer konkret Zeit hat, wird erst in Schritt 2 anhand der gewählten Uhrzeit ermittelt.
async function renderTermineSlotsHTML(service) {
  const releaseExists = await hasAnyReleaseForDate(bookingState.date);
  if (!releaseExists) {
    return `<div class="alert alert-error">Für diesen Tag hat noch niemand Termine freigegeben. Bitte anderes Datum wählen.</div>`;
  }
  const slots = await computeFreeSlots(bookingState.date, service.duration, "egal");
  if (slots.length === 0) {
    return `<div class="alert alert-error">An diesem Tag ist für „${service.name}" (${service.duration} Min) leider kein freigegebenes Zeitfenster mehr verfügbar. Bitte anderes Datum wählen.</div>`;
  }
  return `
    <div class="form-row">
      <label>Freie Zeitfenster (${service.duration} Min. Dauer)</label>
      <div class="slots-grid" id="slots-grid">
        ${slots.map(s => `
          <button type="button" class="slot-btn ${bookingState.start === s ? "selected" : ""}" data-slot="${s}">${s}</button>
        `).join("")}
      </div>
    </div>
  `;
}

// Schritt 2: welche Mitarbeiter:innen sind zur gewählten Uhrzeit tatsächlich frei?
async function freeStaffAt(dateStr, start, duration) {
  const checks = await Promise.all(STAFF.map(async (s) => {
    const [busy, released] = await Promise.all([dbFetchBusySlots(dateStr, s.id), dbFetchReleasedSlots(dateStr, s.id)]);
    if (!released.includes(start)) return null;
    const startMin = toMinutes(start), endMin = startMin + duration;
    const overlaps = busy.some(b => startMin < toMinutes(b.end) && endMin > toMinutes(b.start));
    return overlaps ? null : s.id;
  }));
  return checks.filter(Boolean);
}

async function renderStaffPickHTML(service) {
  const freeIds = await freeStaffAt(bookingState.date, bookingState.start, service.duration);
  if (freeIds.length === 0) {
    return `<div class="alert alert-error">Diese Zeit ist inzwischen nicht mehr frei. Bitte eine andere Uhrzeit wählen.</div>`;
  }
  const freeStaff = STAFF.filter(s => freeIds.includes(s.id));
  return `
    <div class="form-row">
      <label>Bei wem möchten Sie den Termin um ${bookingState.start} Uhr?</label>
      <div class="service-pick" id="staff-pick">
        ${freeStaff.map(s => `
          <label>
            <input type="radio" name="staff" value="${s.id}" ${bookingState.staffId === s.id ? "checked" : ""}>
            <span>${s.name}</span>
          </label>
        `).join("")}
        ${freeStaff.length > 1 ? `
          <label>
            <input type="radio" name="staff" value="egal" ${bookingState.staffId === "egal" ? "checked" : ""}>
            <span>Egal<br><small>Hauptsache frei</small></span>
          </label>
        ` : ""}
      </div>
    </div>
  `;
}

function confirmSummaryHTML(service) {
  const staffLabel = bookingState.staffId === "egal" ? "automatische Zuteilung" : `bei ${staffNameById(bookingState.staffId)}`;
  return `
    <div class="alert alert-info">
      <strong>Zusammenfassung:</strong> ${service.name} am
      ${new Date(bookingState.date + "T00:00:00").toLocaleDateString("de-DE", { weekday: "long", day: "2-digit", month: "2-digit" })}
      um ${bookingState.start} Uhr, ${staffLabel}.
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" id="confirm-btn">Termin verbindlich buchen</button>
    </div>
  `;
}

async function renderCustomerAppointmentsTab(profile) {
  const content = document.getElementById("customer-tab-content");
  const mine = await dbFetchBookingsForCustomer(profile.id);

  content.innerHTML = `
    <h3 style="margin-top:0;">${mine.length === 0 ? "Keine gebuchten Termine" : `${mine.length} gebuchte${mine.length === 1 ? "r Termin" : " Termine"}`}</h3>
    <ul class="appt-list" id="my-appts">
      ${mine.length === 0
        ? `<li class="hint" style="background:none;">Sie haben aktuell keine gebuchten Termine.</li>`
        : mine.map(b => `
          <li>
            <span>${new Date(b.date + "T00:00:00").toLocaleDateString("de-DE", { weekday: "short", day: "2-digit", month: "2-digit" })}
              · ${b.start}–${b.end} Uhr · ${b.service} · bei ${b.staff || "?"}</span>
            <span style="display:flex; gap:10px; align-items:center;">
              <button class="link-danger" data-ics="${b.id}" style="color:var(--gold-dark);">📅 Kalender</button>
              <button class="link-danger" data-cancel="${b.id}">stornieren</button>
            </span>
          </li>
        `).join("")}
    </ul>
    <div class="form-actions" style="margin-top:10px;">
      <button type="button" class="btn btn-light" id="goto-booking-btn">+ Neuen Termin buchen</button>
    </div>
  `;

  document.getElementById("goto-booking-btn").addEventListener("click", () => {
    customerView = "buchen";
    renderBooking();
  });

  content.querySelectorAll("[data-cancel]").forEach(btn => {
    btn.addEventListener("click", async () => {
      await dbDeleteBooking(btn.dataset.cancel);
      renderBooking();
    });
  });

  content.querySelectorAll("[data-ics]").forEach(btn => {
    btn.addEventListener("click", () => {
      const booking = mine.find(b => b.id === btn.dataset.ics);
      if (booking) downloadICS(booking);
    });
  });
}

/* ===========================================================
   Nav-Status (eingeloggt / ausgeloggt)
   =========================================================== */
function renderNavUser() {
  const el = document.getElementById("nav-user");
  if (!el) return;
  if (currentProfile) {
    el.innerHTML = `
      <span class="user-chip">👤 ${currentProfile.name} <button id="nav-logout">abmelden</button></span>
      <a href="#termin" class="btn btn-primary">Termin buchen</a>
    `;
    document.getElementById("nav-logout").addEventListener("click", async () => {
      await signOutUser();
      renderNavUser();
      renderBooking();
    });
  } else {
    el.innerHTML = `<a href="#termin" class="btn btn-primary">Termin buchen</a>`;
  }
}

/* ===========================================================
   Salon-Team-Ansicht: Buchungen, Freigabe & Kund:innen (#salon)
   Nutzt dieselbe Anmeldung wie der Kundenbereich – Zugriff wird
   über currentProfile.role gesteuert (customer/staff/owner).
   =========================================================== */
let adminSelectedDate = fmtDate(new Date());
let adminView = "termine"; // "termine" | "freigabe" | "kunden" (Inhaber)
let adminStaffFilter = "alle";
let adminReleaseStaffId = STAFF[0].id;
let staffView = "termine"; // "termine" | "freigabe" (Mitarbeiter:in)
let adminFlash = null;
let editingBookingId = null;

function flashHTML() {
  if (!adminFlash) return "";
  const { type, text } = adminFlash;
  adminFlash = null;
  return `<div class="alert alert-${type}">${text}</div>`;
}

async function renderAdmin() {
  const root = document.getElementById("admin-app");
  if (!root) return;
  root.innerHTML = `<p class="hint">Lädt …</p>`;

  await loadCurrentProfile();

  if (currentProfile?.role === "owner") {
    await renderOwnerPanel(root);
  } else if (currentProfile?.role === "staff") {
    await renderStaffPanel(root);
  } else {
    renderSalonLogin(root);
  }
}

function renderSalonLogin(root) {
  root.innerHTML = `
    <div class="alert alert-info">
      Dieser Bereich ist nur fürs Salon-Team gedacht.
      ${currentProfile ? `Sie sind aktuell als <strong>${currentProfile.name}</strong> (Kundenzugang) angemeldet – für den Team-Bereich bitte mit einem Team-Zugang anmelden.` : "Bitte mit Ihrem Team-Zugang anmelden."}
    </div>
    <form id="salon-login-form">
      <div id="salon-login-error"></div>
      <div class="form-row">
        <label for="salon-email">E-Mail-Adresse oder Benutzername</label>
        <input id="salon-email" type="text" autocomplete="username" placeholder="ihre@email.de oder Benutzername" required>
      </div>
      <div class="form-row">
        <label for="salon-pass">Passwort</label>
        <input id="salon-pass" type="password" autocomplete="current-password" required>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Anmelden</button>
      </div>
    </form>
  `;
  document.getElementById("salon-login-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const email = document.getElementById("salon-email").value.trim();
    const pass = document.getElementById("salon-pass").value;
    const errBox = document.getElementById("salon-login-error");
    try {
      const { error } = await signInEmail(email, pass);
      if (error) throw error;
      await loadCurrentProfile();
      if (currentProfile?.role !== "staff" && currentProfile?.role !== "owner") {
        await signOutUser();
        errBox.innerHTML = `<div class="alert alert-error">Dieser Zugang hat keine Team-Berechtigung.</div>`;
        renderAdmin();
        return;
      }
      staffView = "termine";
      adminView = "termine";
      renderAdmin();
      renderNavUser();
    } catch (err) {
      errBox.innerHTML = `<div class="alert alert-error">${err.message || "Anmeldung fehlgeschlagen."}</div>`;
    }
  });
}

async function renderOwnerPanel(root) {
  root.innerHTML = `
    <p class="hint" style="text-align:right;">
      Angemeldet als Inhaber:in · <button class="link-danger" id="admin-logout-btn">abmelden</button>
    </p>
    <div class="steps">
      <span class="step-pill ${adminView === "termine" ? "active" : ""}" id="tab-termine" style="cursor:pointer;">Tagesübersicht</span>
      <span class="step-pill ${adminView === "freigabe" ? "active" : ""}" id="tab-freigabe" style="cursor:pointer;">Zeiten freigeben</span>
      <span class="step-pill ${adminView === "kunden" ? "active" : ""}" id="tab-kunden" style="cursor:pointer;">Kund:innen</span>
    </div>
    <div id="admin-tab-content"><p class="hint">Lädt …</p></div>
  `;

  document.getElementById("admin-logout-btn").addEventListener("click", async () => {
    await signOutUser();
    renderAdmin();
  });
  document.getElementById("tab-termine").addEventListener("click", () => { adminView = "termine"; renderAdmin(); });
  document.getElementById("tab-freigabe").addEventListener("click", () => { adminView = "freigabe"; renderAdmin(); });
  document.getElementById("tab-kunden").addEventListener("click", () => { adminView = "kunden"; renderAdmin(); });

  if (adminView === "termine") await renderAdminBookings(null);
  else if (adminView === "freigabe") await renderAdminRelease(null);
  else await renderAdminCustomers();
}

async function renderStaffPanel(root) {
  const staffId = currentProfile.staffId;
  root.innerHTML = `
    <p class="hint" style="text-align:right;">
      Angemeldet als <strong>${currentProfile.name}</strong> · <button class="link-danger" id="staff-logout-btn">abmelden</button>
    </p>
    <div class="steps">
      <span class="step-pill ${staffView === "termine" ? "active" : ""}" id="stab-termine" style="cursor:pointer;">Meine Termine</span>
      <span class="step-pill ${staffView === "freigabe" ? "active" : ""}" id="stab-freigabe" style="cursor:pointer;">Meine Zeiten freigeben</span>
    </div>
    <div id="admin-tab-content"><p class="hint">Lädt …</p></div>
  `;

  document.getElementById("staff-logout-btn").addEventListener("click", async () => {
    await signOutUser();
    renderAdmin();
  });
  document.getElementById("stab-termine").addEventListener("click", () => { staffView = "termine"; renderAdmin(); });
  document.getElementById("stab-freigabe").addEventListener("click", () => { staffView = "freigabe"; renderAdmin(); });

  if (staffView === "termine") await renderAdminBookings(staffId);
  else await renderAdminRelease(staffId);
}

async function renderAdminBookings(lockedStaffId) {
  const content = document.getElementById("admin-tab-content");
  const isOwnerView = !lockedStaffId;
  const activeFilter = lockedStaffId || adminStaffFilter;

  const dateLabel = new Date(adminSelectedDate + "T00:00:00")
    .toLocaleDateString("de-DE", { weekday: "long", day: "2-digit", month: "2-digit", year: "numeric" });

  const todayStr = fmtDate(new Date());
  const [dayBookings, upcoming] = await Promise.all([
    dbFetchBookingsAdmin({ date: adminSelectedDate, staffId: activeFilter }),
    dbFetchBookingsAdmin({ fromDate: todayStr, staffId: activeFilter })
  ]);

  let editingBooking = editingBookingId ? [...dayBookings, ...upcoming].find(b => b.id === editingBookingId) : null;
  if (!editingBooking && editingBookingId) {
    try {
      const { booking } = await apiGet("booking_get", { id: editingBookingId });
      if (booking) editingBooking = mapBooking(booking);
    } catch (e) { /* nicht gefunden oder kein Zugriff */ }
  }
  if (editingBooking && lockedStaffId && editingBooking.staffId !== lockedStaffId) editingBooking = null;

  content.innerHTML = `
    <div class="form-row">
      <label for="admin-date">Tag auswählen</label>
      <input type="date" id="admin-date" value="${adminSelectedDate}">
    </div>
    ${isOwnerView ? `
      <div class="form-row">
        <label for="admin-staff-filter">Mitarbeiter:in</label>
        <select id="admin-staff-filter">
          <option value="alle" ${adminStaffFilter === "alle" ? "selected" : ""}>Alle Mitarbeiter:innen</option>
          ${STAFF.map(s => `<option value="${s.id}" ${adminStaffFilter === s.id ? "selected" : ""}>${s.name}</option>`).join("")}
        </select>
      </div>
    ` : ""}

    ${flashHTML()}
    <div style="border:1px dashed var(--line); border-radius:12px; padding:18px 20px; margin:20px 0;" id="manual-booking-box">
      <h3 style="margin-top:0;">${editingBooking ? "✏️ Termin bearbeiten" : "📞 Termin manuell eintragen"}</h3>
      <p class="hint" style="margin-bottom:16px;">
        ${editingBooking
          ? `Sie ändern den bestehenden Termin von <strong>${editingBooking.user}</strong>.`
          : `Für Kund:innen, die anrufen oder direkt vorbeikommen – landet im selben Kalender wie
             die Online-Buchungen, damit Sie nur einen Kalender pflegen müssen.`}
      </p>
      <form id="manual-booking-form">
        <div id="manual-booking-error"></div>
        <div class="form-row">
          <label for="mb-name">Name der Kundin / des Kunden</label>
          <input id="mb-name" type="text" placeholder="z. B. Herr Schmidt (telefonisch)" value="${editingBooking ? editingBooking.user : ""}" required>
        </div>
        <div class="form-row">
          <label for="mb-service">Anwendung</label>
          <select id="mb-service">
            ${SERVICES.map(s => `<option value="${s.id}" ${editingBooking && findServiceIdByName(editingBooking.service) === s.id ? "selected" : ""}>${s.name} (${s.duration} Min)</option>`).join("")}
          </select>
        </div>
        ${isOwnerView ? `
          <div class="form-row">
            <label for="mb-staff">Mitarbeiter:in</label>
            <select id="mb-staff">
              ${STAFF.map(s => `<option value="${s.id}" ${editingBooking && editingBooking.staffId === s.id ? "selected" : ""}>${s.name}</option>`).join("")}
            </select>
          </div>
        ` : ""}
        <div class="form-row">
          <label for="mb-date">Datum</label>
          <input id="mb-date" type="date" value="${editingBooking ? editingBooking.date : adminSelectedDate}" required>
        </div>
        <div class="form-row">
          <label for="mb-time">Uhrzeit (nur volle Viertelstunden)</label>
          <select id="mb-time" required>
            ${manualTimeOptionsHTML(editingBooking ? editingBooking.start : null)}
          </select>
          <p class="hint" style="margin-top:6px;">
            Rund um die Uhr wählbar – auch außerhalb der normalen Öffnungszeiten und an
            Ruhetagen, für Sonderfälle wie Termine nach telefonischer Absprache.
          </p>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">${editingBooking ? "Änderungen speichern" : "Termin eintragen"}</button>
          ${editingBooking ? `<button type="button" class="btn btn-light" id="mb-cancel-edit">Abbrechen</button>` : ""}
        </div>
      </form>
    </div>

    <h3 style="margin-top:24px;">${dayBookings.length} Termin${dayBookings.length === 1 ? "" : "e"} am ${dateLabel}</h3>
    <ul class="appt-list" id="admin-appt-list">
      ${dayBookings.length === 0
        ? `<li class="hint" style="background:none;">An diesem Tag sind keine Termine gebucht.</li>`
        : dayBookings.map(b => `
          <li>
            <span>${b.manual ? "📞 " : ""}<strong>${b.start}–${b.end} Uhr</strong> · ${b.service} · Kund:in: ${b.user}${isOwnerView ? ` · bei ${b.staff || "?"}` : ""}</span>
            <span style="display:flex; gap:10px; align-items:center;">
              <button class="link-danger" style="color:var(--gold-dark);" data-edit-booking="${b.id}">bearbeiten</button>
              <button class="link-danger" data-admin-cancel="${b.id}">stornieren</button>
            </span>
          </li>
        `).join("")}
    </ul>

    <h3 style="margin-top:36px;">Alle zukünftigen Termine (${upcoming.length})</h3>
    <div style="overflow-x:auto;">
      <table class="price-table" id="upcoming-table">
        <thead>
          <tr>
            <th>Datum</th>
            <th>Uhrzeit</th>
            <th>Anwendung</th>
            <th>Kund:in</th>
            ${isOwnerView ? "<th>Mitarbeiter:in</th>" : ""}
            <th></th>
          </tr>
        </thead>
        <tbody>
          ${upcoming.length === 0
            ? `<tr><td colspan="${isOwnerView ? 6 : 5}" class="hint" style="border:none;">Keine zukünftigen Termine.</td></tr>`
            : upcoming.map(b => `
              <tr>
                <td>${new Date(b.date + "T00:00:00").toLocaleDateString("de-DE", { weekday: "short", day: "2-digit", month: "2-digit" })}</td>
                <td>${b.start}–${b.end} Uhr</td>
                <td>${b.service}</td>
                <td>${b.manual ? "📞 " : ""}${b.user}</td>
                ${isOwnerView ? `<td>${b.staff || "?"}</td>` : ""}
                <td style="text-align:right; white-space:nowrap;">
                  <button class="link-danger" style="color:var(--gold-dark);" data-edit-booking="${b.id}">bearbeiten</button>
                  <button class="link-danger" data-upcoming-cancel="${b.id}">stornieren</button>
                </td>
              </tr>
            `).join("")}
        </tbody>
      </table>
    </div>
  `;

  document.getElementById("admin-date").addEventListener("change", (e) => {
    adminSelectedDate = e.target.value || fmtDate(new Date());
    renderAdminBookings(lockedStaffId);
  });

  document.getElementById("admin-staff-filter")?.addEventListener("change", (e) => {
    adminStaffFilter = e.target.value;
    renderAdminBookings(lockedStaffId);
  });

  document.getElementById("manual-booking-form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const name = document.getElementById("mb-name").value.trim();
    const svc = SERVICES.find(s => s.id === document.getElementById("mb-service").value);
    const staffId = isOwnerView ? document.getElementById("mb-staff").value : lockedStaffId;
    const date = document.getElementById("mb-date").value;
    const start = document.getElementById("mb-time").value;
    const errBox = document.getElementById("manual-booking-error");

    if (!name || !date || !start) {
      errBox.innerHTML = `<div class="alert alert-error">Bitte Name, Datum und Uhrzeit ausfüllen.</div>`;
      return;
    }
    if (toMinutes(start) + svc.duration > 24 * 60) {
      errBox.innerHTML = `<div class="alert alert-error">Bei dieser Uhrzeit würde „${svc.name}" (${svc.duration} Min) über Mitternacht hinausgehen. Bitte eine frühere Uhrzeit wählen oder den Termin auf den Folgetag legen.</div>`;
      return;
    }
    if (await hasOverlap(date, staffId, start, svc.duration, editingBooking?.id)) {
      errBox.innerHTML = `<div class="alert alert-error">Diese Zeit ist bei ${staffNameById(staffId)} bereits belegt – bitte andere Uhrzeit wählen.</div>`;
      return;
    }

    const end = toHHMM(toMinutes(start) + svc.duration);
    try {
      if (editingBooking) {
        await dbUpdateBooking(editingBooking.id, { date, start, end, service: svc.name, user: name, staffId, staff: staffNameById(staffId) });
        adminFlash = { type: "ok", text: `✅ Termin für ${name} wurde aktualisiert: ${svc.name} am ${new Date(date + "T00:00:00").toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit" })} um ${start} Uhr bei ${staffNameById(staffId)}.` };
        editingBookingId = null;
      } else {
        await dbInsertBooking({ date, start, end, service: svc.name, user: name, staffId, staff: staffNameById(staffId), manual: true });
        adminFlash = { type: "ok", text: `✅ Termin für ${name} (${svc.name}) am ${new Date(date + "T00:00:00").toLocaleDateString("de-DE", { day: "2-digit", month: "2-digit" })} um ${start} Uhr bei ${staffNameById(staffId)} eingetragen.` };
      }
    } catch (err) {
      errBox.innerHTML = `<div class="alert alert-error">Speichern fehlgeschlagen: ${err.message || err}</div>`;
      return;
    }

    adminSelectedDate = date;
    renderAdminBookings(lockedStaffId);
  });

  document.getElementById("mb-cancel-edit")?.addEventListener("click", () => {
    editingBookingId = null;
    renderAdminBookings(lockedStaffId);
  });

  content.querySelectorAll("[data-edit-booking]").forEach(btn => {
    btn.addEventListener("click", () => {
      editingBookingId = btn.dataset.editBooking;
      renderAdminBookings(lockedStaffId);
      document.getElementById("manual-booking-box")?.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });

  content.querySelectorAll("[data-admin-cancel]").forEach(btn => {
    btn.addEventListener("click", async () => {
      if (editingBookingId === btn.dataset.adminCancel) editingBookingId = null;
      await dbDeleteBooking(btn.dataset.adminCancel);
      renderAdminBookings(lockedStaffId);
    });
  });

  content.querySelectorAll("[data-upcoming-cancel]").forEach(btn => {
    btn.addEventListener("click", async () => {
      if (editingBookingId === btn.dataset.upcomingCancel) editingBookingId = null;
      await dbDeleteBooking(btn.dataset.upcomingCancel);
      renderAdminBookings(lockedStaffId);
    });
  });
}

async function renderAdminRelease(lockedStaffId) {
  const content = document.getElementById("admin-tab-content");
  const isOwnerView = !lockedStaffId;
  const staffId = lockedStaffId || adminReleaseStaffId;

  const d = new Date(adminSelectedDate + "T00:00:00");
  const hours = OPENING_HOURS[d.getDay()];
  const isClosedDay = !hours || CLOSED_DAYS.includes(d.getDay());
  const dateLabel = d.toLocaleDateString("de-DE", { weekday: "long", day: "2-digit", month: "2-digit", year: "numeric" });

  const [released, dayBookings] = await Promise.all([
    dbFetchReleasedSlots(adminSelectedDate, staffId),
    dbFetchBookingsAdmin({ date: adminSelectedDate, staffId })
  ]);
  const releasedSet = new Set(released);

  let gridHTML;
  if (isClosedDay) {
    gridHTML = `<p class="hint">Der Salon hat an diesem Tag (${dateLabel}) geschlossen – keine Freigabe nötig.</p>`;
  } else {
    const allSlots = allSlotsForDate(adminSelectedDate);
    gridHTML = `
      <div class="slots-grid">
        ${allSlots.map(t => {
          const mins = toMinutes(t);
          const isBooked = dayBookings.some(b => mins >= toMinutes(b.start) && mins < toMinutes(b.end));
          const isReleased = releasedSet.has(t);
          const cls = "slot-btn" + (isReleased && !isBooked ? " selected" : "");
          const extra = isBooked ? `disabled title="bereits gebucht" style="opacity:.4; cursor:not-allowed;"` : "";
          return `<button type="button" class="${cls}" data-release-slot="${t}" ${extra}>${t}</button>`;
        }).join("")}
      </div>`;
  }

  content.innerHTML = `
    <div class="form-row">
      <label for="release-date">Tag auswählen</label>
      <input type="date" id="release-date" value="${adminSelectedDate}">
    </div>
    ${isOwnerView ? `
      <div class="form-row">
        <label for="release-staff">Für wen freigeben?</label>
        <select id="release-staff">
          ${STAFF.map(s => `<option value="${s.id}" ${staffId === s.id ? "selected" : ""}>${s.name}</option>`).join("")}
        </select>
      </div>
    ` : ""}
    <p class="hint">
      Klicken Sie die Zeiten an, die ${isOwnerView ? `<strong>${staffNameById(staffId)}</strong>` : "Sie"} an diesem Tag
      <strong>tatsächlich anbieten ${isOwnerView ? "kann" : "können"}</strong>.
      Gold markierte Zeiten sind freigegeben und für Kund:innen buchbar – alle anderen bleiben unsichtbar.
      Bereits gebuchte Zeiten sind ausgegraut und lassen sich nicht mehr ändern.
    </p>
    ${!isClosedDay ? `
      <div class="form-actions" style="margin-bottom:18px;">
        <button type="button" class="btn btn-light" id="release-all-btn">Ganzen Tag freigeben</button>
        <button type="button" class="btn btn-light" id="release-none-btn">Ganzen Tag sperren</button>
      </div>
    ` : ""}
    <h3 style="margin-top:8px;">${dateLabel}${isOwnerView ? ` · ${staffNameById(staffId)}` : ""}</h3>
    ${gridHTML}
  `;

  document.getElementById("release-date").addEventListener("change", (e) => {
    adminSelectedDate = e.target.value || fmtDate(new Date());
    renderAdminRelease(lockedStaffId);
  });

  document.getElementById("release-staff")?.addEventListener("change", (e) => {
    adminReleaseStaffId = e.target.value;
    renderAdminRelease(lockedStaffId);
  });

  document.getElementById("release-all-btn")?.addEventListener("click", async () => {
    await setAllReleased(adminSelectedDate, staffId, true);
    renderAdminRelease(lockedStaffId);
  });
  document.getElementById("release-none-btn")?.addEventListener("click", async () => {
    await setAllReleased(adminSelectedDate, staffId, false);
    renderAdminRelease(lockedStaffId);
  });

  content.querySelectorAll("[data-release-slot]:not([disabled])").forEach(btn => {
    btn.addEventListener("click", async () => {
      await toggleReleasedSlot(adminSelectedDate, staffId, btn.dataset.releaseSlot);
      renderAdminRelease(lockedStaffId);
    });
  });
}

let adminExpandedCustomer = null;

async function renderAdminCustomers() {
  const content = document.getElementById("admin-tab-content");
  const profiles = await dbFetchCustomerProfiles();

  const bookingsByCustomer = {};
  await Promise.all(profiles.map(async p => {
    bookingsByCustomer[p.id] = await dbFetchBookingsForCustomer(p.id);
  }));

  content.innerHTML = `
    ${flashHTML()}
    <div class="alert alert-info" style="margin-bottom:20px;">
      Kund:innen registrieren sich künftig selbst über die Webseite (E-Mail + Passwort).
      Hier sehen Sie alle registrierten Zugänge mit ihren Buchungen.
    </div>
    <h3 style="margin-top:0;">Registrierte Kund:innen (${profiles.length})</h3>
    <div id="customer-list">
      ${profiles.map(p => {
        const bookings = bookingsByCustomer[p.id] || [];
        const expanded = adminExpandedCustomer === p.id;
        return `
        <div class="customer-card">
          <div class="customer-card-head">
            <span>
              <strong>${p.display_name}</strong> · ${p.id in bookingsByCustomer ? "" : ""}
              <strong>${bookings.length}</strong> Termin${bookings.length === 1 ? "" : "e"}
            </span>
            <span style="display:flex; gap:14px; align-items:center;">
              <button type="button" class="link-danger" style="color:var(--gold-dark);" data-toggle-customer="${p.id}">
                ${expanded ? "Termine ausblenden" : "Termine anzeigen"}
              </button>
            </span>
          </div>
          ${expanded ? `
            <ul class="appt-list" style="margin-top:14px;">
              ${bookings.length === 0
                ? `<li class="hint" style="background:none;">Diese Kundin / dieser Kunde hat aktuell keine Termine gebucht.</li>`
                : bookings.map(b => `
                  <li>
                    <span>${new Date(b.date + "T00:00:00").toLocaleDateString("de-DE", { weekday: "short", day: "2-digit", month: "2-digit" })}
                      · ${b.start}–${b.end} Uhr · ${b.service} · bei ${b.staff || "?"}</span>
                    <button class="link-danger" data-del-booking="${b.id}">stornieren</button>
                  </li>
                `).join("")}
            </ul>
          ` : ""}
        </div>
      `;
      }).join("")}
      ${profiles.length === 0 ? `<p class="hint">Noch keine Kund:innen registriert.</p>` : ""}
    </div>
  `;

  content.querySelectorAll("[data-toggle-customer]").forEach(btn => {
    btn.addEventListener("click", () => {
      const id = btn.dataset.toggleCustomer;
      adminExpandedCustomer = adminExpandedCustomer === id ? null : id;
      renderAdminCustomers();
    });
  });

  content.querySelectorAll("[data-del-booking]").forEach(btn => {
    btn.addEventListener("click", async () => {
      await dbDeleteBooking(btn.dataset.delBooking);
      renderAdminCustomers();
    });
  });
}

/* ===========================================================
   E-Mail-Bestätigung (Link aus der Registrierungs-Mail)
   =========================================================== */
async function handleEmailVerification() {
  const params = new URLSearchParams(window.location.search);
  const token = params.get("verify");
  if (!token) return;

  const banner = document.createElement("div");
  banner.style.cssText = "position:fixed; top:0; left:0; right:0; z-index:9999; padding:14px 20px; text-align:center; font-weight:600; font-family:sans-serif;";
  try {
    const { name } = await api("verify_email", { method: "POST", body: { token } });
    banner.style.background = "#e6f4ea";
    banner.style.color = "#1e6b33";
    banner.textContent = `✅ E-Mail bestätigt${name ? ", " + name : ""}! Sie können sich jetzt anmelden.`;
  } catch (e) {
    banner.style.background = "#fdecea";
    banner.style.color = "#a33";
    banner.textContent = `❌ ${e.message || "Bestätigung fehlgeschlagen."}`;
  }
  document.body.prepend(banner);
  setTimeout(() => banner.remove(), 8000);

  // URL bereinigen, damit ein Neuladen der Seite nicht erneut verifiziert.
  params.delete("verify");
  const query = params.toString();
  window.history.replaceState({}, "", window.location.pathname + (query ? "?" + query : "") + window.location.hash);
}

/* ===========================================================
   Init
   =========================================================== */
document.addEventListener("DOMContentLoaded", async () => {
  window.addEventListener("hashchange", handleRoute);

  await handleEmailVerification();
  restoreBookingDraftIfAny();
  await loadCurrentProfile();
  handleRoute();
  renderNavUser();
  renderBooking();

  document.getElementById("nav-toggle")?.addEventListener("click", () => {
    document.getElementById("nav-links").classList.toggle("open");
  });

  document.getElementById("year")?.append(new Date().getFullYear());
});
