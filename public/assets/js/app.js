(() => {
  const APP_VERSION = window.__APP_VERSION__ || "0.3.25";
  const APP_STAGE = window.__APP_VERSION_STAGE__ || "Release";
  // Namespaced web keys — never reuse Capacitor/old MiniKBP keys on mini-kbp.site
  const SETTINGS_KEY = "mkbp_web_settings_v1";
  const RECENT_KEY = "mkbp_web_recent_v1";
  const TAB_KEY = "mkbp_web_tab";
  const CACHE_SNAP_KEY = "mkbp_web_tt_snap_v1";
  const QUERY_KEY = "mkbp_web_tt_query_v1";
  const CACHE_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
  const LEGACY_LS_KEYS = [
    "cached_timetable_data",
    "cached_selected_timetable_result",
    "cached_timetable_url",
    "timetable_query_v1",
    "recent_timetable_searches_v1",
    "app_settings_v1",
    "app_journal_tab",
  ];
  const DAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Пн"];
  const DAYS_FULL = ["Понедельник", "Вторник", "Среда", "Четверг", "Пятница", "Суббота", "Понедельник (след.)"];
  const TYPE_LABELS = { group: "группа", teacher: "преподаватель", place: "аудитория", subject: "предмет" };
  const ACCENTS = [
    { id: "blue", label: "Синий", hex: "#3390ec" },
    { id: "green", label: "Зелёный", hex: "#31b545" },
    { id: "purple", label: "Фиолетовый", hex: "#8b5cf6" },
    { id: "orange", label: "Оранжевый", hex: "#f59e0b" },
    { id: "pink", label: "Розовый", hex: "#ec4899" },
    { id: "red", label: "Красный", hex: "#ef4444" },
    { id: "cyan", label: "Бирюзовый", hex: "#06b6d4" },
  ];
  const SLOGANS = ["Мини КБиП", "Расписание", "Замены", "Офлайн", "Удобство"];

  const STD = {
    1: { start: "8.00", end: "8.45" }, 2: { start: "8.55", end: "9.40" }, 3: { start: "9.50", end: "10.35" },
    4: { start: "10.45", end: "11.30" }, 5: { start: "12.00", end: "12.45" }, 6: { start: "12.55", end: "13.40" },
    7: { start: "14.00", end: "14.45" }, 8: { start: "14.55", end: "15.40" }, 9: { start: "16.00", end: "16.45" },
    10: { start: "16.55", end: "17.40" }, 11: { start: "17.50", end: "18.35" }, 12: { start: "18.45", end: "19.30" },
    13: { start: "19.40", end: "20.25" },
  };
  const THU_FROM7 = {
    7: { start: "14.40", end: "15.25" }, 8: { start: "15.35", end: "16.20" }, 9: { start: "16.30", end: "17.15" },
    10: { start: "17.25", end: "18.10" }, 11: { start: "18.20", end: "19.05" }, 12: { start: "19.15", end: "20.00" },
    13: { start: "20.10", end: "20.55" },
  };
  const SAT_FROM5 = {
    5: { start: "11.40", end: "12.25" }, 6: { start: "12.35", end: "13.20" }, 7: { start: "13.40", end: "14.25" },
    8: { start: "14.35", end: "15.20" }, 9: { start: "15.30", end: "16.15" }, 10: { start: "16.25", end: "17.10" },
    11: { start: "17.20", end: "18.05" }, 12: { start: "18.15", end: "19.00" }, 13: { start: "19.10", end: "19.55" },
  };

  const defaultSettings = () => ({
    theme: "light",
    accentColor: "blue",
    notificationsEnabled: false,
    notifyTimetable: true,
    countdownToLesson: false,
    showReplacementsByDefault: true,
    timetableDensity: "normal",
    timetableShowGroup: true,
    timetableShowTeacher: true,
    timetableShowRoom: true,
    timetableHidePairNumbers: false,
    timetableDayStrip: true,
    timetablePcSidePad: true,
    timetableShowGrades: false,
    /** Show full subject titles from rasp.kbp.by (short from kbp.by always kept in data). */
    timetableFullSubjectNames: false,
    /** Show full teacher FIO from rasp.kbp.by. */
    timetableFullTeacherNames: false,
  });

  const state = {
    tab: 1,
    settingsScreen: "hub",
    settings: defaultSettings(),
    entity: null,
    timetable: null,
    day: 0,
    weekPage: 0,
    showReplacementsDays: Array(7).fill(true),
    searchTimer: null,
    contentReady: false,
    freeWhen: "now",
    freeLesson: "auto",
    nowTickMs: Date.now(),
    touchStart: null,
    bootstrapped: false,
    pcResize: null,
  };

  const $ = (id) => document.getElementById(id);
  const els = {
    boot: $("bootSplash"),
    slogan: $("bootSlogan"),
    tabSettings: $("tabSettings"),
    tabTimetable: $("tabTimetable"),
    settingsRoot: $("settingsRoot"),
    ttInner: $("ttInner"),
    searchInput: $("searchInput"),
    searchField: $("searchField"),
    searchDropdown: $("searchDropdown"),
    searchSpinner: $("searchSpinner"),
    recentChips: $("recentChips"),
    recentWrap: $("recentWrap"),
    freeRoomsBtn: $("freeRoomsBtn"),
    freeRoomsPanel: $("freeRoomsPanel"),
    frList: $("frList"),
    frSub: $("frSub"),
    frDate: $("frDate"),
    frLesson: $("frLesson"),
    weekSwitcher: $("weekSwitcher"),
    weekPrimary: $("weekPrimary"),
    weekSecondary: $("weekSecondary"),
    weekPrev: $("weekPrev"),
    weekNext: $("weekNext"),
    pcTitle: $("pcTitle"),
    pcTitleName: $("pcTitleName"),
    pcTitleType: $("pcTitleType"),
    ttContent: $("ttContent"),
    kbpNotice: $("kbpNotice"),
    navSettings: $("navSettings"),
    navTimetable: $("navTimetable"),
  };

  function esc(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");
  }

  function typeLabel(type) {
    return TYPE_LABELS[type] || type || "";
  }

  function normalizeEntity(entity) {
    if (!entity || !entity.type || !entity.id) return null;
    return {
      type: entity.type,
      id: String(entity.id),
      name: entity.name || "",
      typeLabel: entity.typeLabel || typeLabel(entity.type),
    };
  }

  function migrateLegacySettingsOnce() {
    try {
      if (localStorage.getItem(SETTINGS_KEY)) return;
      const legacy = localStorage.getItem("app_settings_v1");
      if (legacy) localStorage.setItem(SETTINGS_KEY, legacy);
    } catch {}
  }

  function normalizeSettings(raw) {
    const merged = { ...defaultSettings(), ...(raw && typeof raw === "object" ? raw : {}) };
    if (merged.theme !== "light" && merged.theme !== "dark" && merged.theme !== "oled") merged.theme = "light";
    if (!ACCENTS.some((a) => a.id === merged.accentColor)) merged.accentColor = "blue";
    if (!["normal", "compact", "small"].includes(merged.timetableDensity)) merged.timetableDensity = "normal";
    for (const key of [
      "notificationsEnabled", "notifyTimetable", "countdownToLesson", "showReplacementsByDefault",
      "timetableShowGroup", "timetableShowTeacher", "timetableShowRoom", "timetableHidePairNumbers",
      "timetableDayStrip", "timetablePcSidePad", "timetableShowGrades",
      "timetableFullSubjectNames", "timetableFullTeacherNames",
    ]) {
      merged[key] = !!merged[key];
    }
    return merged;
  }

  function loadSettings() {
    migrateLegacySettingsOnce();
    let parsed = {};
    try {
      parsed = JSON.parse(localStorage.getItem(SETTINGS_KEY) || "{}");
    } catch {
      parsed = {};
    }
    if (!parsed || typeof parsed !== "object" || !Object.keys(parsed).length) {
      try {
        parsed = JSON.parse(sessionStorage.getItem(SETTINGS_KEY) || "{}");
      } catch {
        parsed = {};
      }
    }
    state.settings = normalizeSettings(parsed);
    resetReplacementsDays();
  }

  function resetReplacementsDays() {
    const def = !!state.settings.showReplacementsByDefault;
    state.showReplacementsDays = Array(7).fill(def);
  }

  function saveSettings() {
    state.settings = normalizeSettings(state.settings);
    const payload = JSON.stringify(state.settings);
    try {
      localStorage.setItem(SETTINGS_KEY, payload);
    } catch (e) {
      console.warn("settings localStorage write failed", e);
    }
    try {
      sessionStorage.setItem(SETTINGS_KEY, payload);
    } catch {}
    applyTheme();
  }

  function accentHex(id) {
    return (ACCENTS.find((a) => a.id === id) || ACCENTS[0]).hex;
  }

  function accentHover(hex) {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex);
    if (!m) return hex;
    const n = parseInt(m[1], 16);
    const r = Math.max(0, Math.round(((n >> 16) & 255) * 0.88));
    const g = Math.max(0, Math.round(((n >> 8) & 255) * 0.88));
    const b = Math.max(0, Math.round((n & 255) * 0.88));
    return `#${((1 << 24) | (r << 16) | (g << 8) | b).toString(16).slice(1)}`;
  }

  function applyTheme() {
    const t = state.settings.theme;
    const root = document.documentElement;
    root.classList.toggle("dark", t === "dark" || t === "oled");
    root.classList.toggle("theme-oled", t === "oled");
    root.style.colorScheme = t === "light" ? "light" : "dark";
    const hex = accentHex(state.settings.accentColor);
    root.style.setProperty("--app-accent", hex);
    root.style.setProperty("--app-accent-hover", accentHover(hex));
    root.style.setProperty("--app-accent-soft", `color-mix(in srgb, ${hex} 14%, transparent)`);
    root.style.setProperty("--app-accent-muted", `color-mix(in srgb, ${hex} 28%, transparent)`);
    root.style.setProperty("--app-accent-ring", `color-mix(in srgb, ${hex} 35%, transparent)`);
    document.querySelector('meta[name="theme-color"]')?.setAttribute("content", t === "light" ? "#ffffff" : t === "oled" ? "#000000" : "#141414");
  }

  function isPc() {
    return window.matchMedia("(min-width: 1024px) and (pointer: fine) and (hover: hover)").matches;
  }

  function liveDayIndex(d = new Date()) {
    const day = d.getDay();
    return day === 0 ? -1 : day - 1;
  }

  function maxDayIndex() {
    return state.timetable?.hasNextWeekMonday ? 6 : 5;
  }

  function getKbpPairTime(pairNumber, dayIndex) {
    if (pairNumber < 1 || pairNumber > 13) return { start: "", end: "" };
    if (dayIndex === 3) {
      if (pairNumber >= 7) return THU_FROM7[pairNumber] || { start: "", end: "" };
      return STD[pairNumber];
    }
    if (dayIndex === 5) {
      if (pairNumber >= 5) return SAT_FROM5[pairNumber] || { start: "", end: "" };
      return STD[pairNumber];
    }
    return STD[pairNumber] || { start: "", end: "" };
  }

  function bellDayIndex(dayIndex) {
    return dayIndex === 6 ? 0 : dayIndex;
  }

  function formatBellClock(t) {
    const raw = (t || "").trim();
    if (!raw) return "";
    const [hRaw, mRaw = "0"] = raw.split(/[.:]/).map((x) => x.trim());
    const h = Number(hRaw);
    const m = Number(mRaw);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return raw;
    return `${h}:${String(m).padStart(2, "0")}`;
  }

  function timeToMinutes(t) {
    const s = (t || "").trim();
    if (!s) return null;
    const parts = s.split(/[.:]/).map((x) => x.trim());
    const h = Number(parts[0]);
    const m = Number(parts[1] || "0");
    if (!Number.isFinite(h) || !Number.isFinite(m)) return null;
    return h * 60 + m;
  }

  function formatCountdown(ms) {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const mm = String(m).padStart(2, "0");
    const ss = String(s).padStart(2, "0");
    if (h > 0) return `${h}:${mm}:${ss}`;
    return `${m}:${ss}`;
  }

  function dayHasReplacements(timetable, dayIndex) {
    const info = timetable?.dayReplacementStatus?.[dayIndex];
    if (info?.hasChanges) return true;
    if (info?.noChanges) return false;
    const change = new Set(["added", "replaced", "removed", "cancelled"]);
    for (const p of timetable?.pairs || []) {
      const weekOffset = p.weekOffset || 0;
      const match = dayIndex <= 5 ? weekOffset === 0 && p.day === dayIndex : weekOffset === 1 && p.day === 0;
      if (!match) continue;
      if (change.has(String(p.status || ""))) return true;
      if (p.overlayEvent) return true;
    }
    return false;
  }

  function syncReplacementsFromTimetable() {
    const def = !!state.settings.showReplacementsByDefault;
    const tt = state.timetable;
    state.showReplacementsDays = Array.from({ length: 7 }, (_, idx) => {
      if (dayHasReplacements(tt, idx)) return def;
      const info = tt?.dayReplacementStatus?.[idx];
      if (info?.noChanges) return false;
      return def;
    });
  }

  function setTab(tab, opts = {}) {
    state.tab = tab === 0 ? 0 : 1;
    try { sessionStorage.setItem(TAB_KEY, String(state.tab)); } catch {}
    els.tabSettings.classList.toggle("is-active", state.tab === 0);
    els.tabTimetable.classList.toggle("is-active", state.tab === 1);
    els.tabSettings.setAttribute("aria-hidden", state.tab === 0 ? "false" : "true");
    els.tabTimetable.setAttribute("aria-hidden", state.tab === 1 ? "false" : "true");
    els.navSettings.classList.toggle("is-active", state.tab === 0);
    els.navTimetable.classList.toggle("is-active", state.tab === 1);
    if (state.tab === 0) els.navSettings.setAttribute("aria-current", "page"); else els.navSettings.removeAttribute("aria-current");
    if (state.tab === 1) els.navTimetable.setAttribute("aria-current", "page"); else els.navTimetable.removeAttribute("aria-current");
    const stroke = (btn, active) => {
      const svg = btn.querySelector("svg");
      if (svg) svg.setAttribute("stroke-width", active ? "2.25" : "2");
    };
    stroke(els.navSettings, state.tab === 0);
    stroke(els.navTimetable, state.tab === 1);
    if (state.tab === 0) renderSettings();
    if (!opts.skipUrl) syncUrlRoute();
  }

  function normalizeRecentItem(x) {
    if (!x || typeof x !== "object") return null;
    const type = String(x.type || "").trim();
    const id = String(x.id || "").trim();
    const name = String(x.name || "").trim();
    if (!type || !id || !name) return null;
    return { type, id, name };
  }

  function loadRecent() {
    try {
      const list = JSON.parse(localStorage.getItem(RECENT_KEY) || "[]");
      if (Array.isArray(list) && list.length) {
        return list.map(normalizeRecentItem).filter(Boolean).slice(0, 5);
      }
    } catch {}
    return [];
  }

  function migrateLegacyRecent() {
    try {
      if (localStorage.getItem(RECENT_KEY)) return;
      const legacy = JSON.parse(localStorage.getItem("recent_timetable_searches_v1") || "[]");
      if (!Array.isArray(legacy) || !legacy.length) return;
      const list = legacy.map(normalizeRecentItem).filter(Boolean).slice(0, 5);
      if (list.length) localStorage.setItem(RECENT_KEY, JSON.stringify(list));
    } catch {}
  }

  function saveRecent(item) {
    const next = normalizeRecentItem(item);
    if (!next) return;
    const list = loadRecent().filter((x) => !(x.type === next.type && x.id === next.id));
    list.unshift(next);
    localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 5)));
    renderRecent();
  }
  function removeRecent(type, id) {
    localStorage.setItem(RECENT_KEY, JSON.stringify(loadRecent().filter((x) => !(x.type === type && x.id === id))));
    renderRecent();
  }
  function renderRecent() {
    const list = loadRecent();
    // Keep chips visible after selecting a timetable (same as Cap app).
    els.recentWrap.classList.toggle("hidden", !list.length);
    els.recentChips.innerHTML = list.map((x) =>
      `<button type="button" class="recent-chip" data-type="${esc(x.type)}" data-id="${esc(x.id)}" data-name="${esc(x.name)}"><span>${esc(x.name)}</span><span class="recent-chip__x" data-x="1" aria-label="Удалить">×</span></button>`
    ).join("");
  }

  function purgeLegacyBrowserCache() {
    try {
      for (const key of LEGACY_LS_KEYS) localStorage.removeItem(key);
    } catch {}
  }

  function persistQuery(entity) {
    if (!entity?.type || !entity?.id) return;
    localStorage.setItem(QUERY_KEY, JSON.stringify({ type: entity.type, id: entity.id, name: entity.name }));
  }

  function persistSnapshot(entity, data) {
    if (!entity || !data) return;
    localStorage.setItem(CACHE_SNAP_KEY, JSON.stringify({
      savedAt: Date.now(),
      result: entity,
      data,
    }));
    persistQuery(entity);
  }

  function loadFreshSnapshot() {
    try {
      const snap = JSON.parse(localStorage.getItem(CACHE_SNAP_KEY) || "null");
      if (!snap || typeof snap !== "object" || !snap.data || !snap.result) return null;
      const savedAt = Number(snap.savedAt) || 0;
      if (!savedAt || Date.now() - savedAt > CACHE_MAX_AGE_MS) {
        localStorage.removeItem(CACHE_SNAP_KEY);
        return null;
      }
      const entity = normalizeEntity(snap.result);
      if (!entity) return null;
      return { entity, data: snap.data, savedAt };
    } catch {
      try { localStorage.removeItem(CACHE_SNAP_KEY); } catch {}
      return null;
    }
  }

  function parseUrlQuery() {
    const sp = new URLSearchParams(window.location.search);
    const type = (sp.get("tt_type") || "").trim();
    const id = (sp.get("tt_id") || "").trim();
    const name = (sp.get("tt_name") || "").trim();
    if (!type || !id) return null;
    return normalizeEntity({ type, id, name, typeLabel: typeLabel(type) });
  }

  const SETTINGS_SCREENS = new Set(["hub", "appearance", "notifications", "calendar", "clear"]);

  /** `?settings=` / `?settings` / `?settings=appearance` → screen name; null if param absent. */
  function parseSettingsFromUrl() {
    const sp = new URLSearchParams(window.location.search);
    if (!sp.has("settings")) return null;
    const raw = String(sp.get("settings") || "").trim().toLowerCase();
    if (!raw || raw === "1" || raw === "true" || raw === "hub") return "hub";
    if (SETTINGS_SCREENS.has(raw)) return raw;
    return "hub";
  }

  function syncUrlRoute() {
    const url = new URL(window.location.href);
    if (state.tab === 0) {
      const sc = state.settingsScreen && state.settingsScreen !== "hub" ? state.settingsScreen : "";
      url.searchParams.set("settings", sc);
    } else {
      url.searchParams.delete("settings");
    }
    const q = url.searchParams.toString();
    history.replaceState(null, "", url.pathname + (q ? `?${q}` : "") + url.hash);
  }

  function applyRouteFromUrl() {
    const settingsScreen = parseSettingsFromUrl();
    if (settingsScreen !== null) {
      state.settingsScreen = settingsScreen;
      setTab(0, { skipUrl: true });
      return;
    }
    state.settingsScreen = "hub";
    setTab(1, { skipUrl: true });
  }

  function loadStoredQuery() {
    try {
      const q = JSON.parse(localStorage.getItem(QUERY_KEY) || "null");
      return normalizeEntity(q);
    } catch { return null; }
  }

  function pushUrlQuery(entity) {
    if (!entity?.type || !entity?.id) return;
    const url = new URL(window.location.href);
    url.searchParams.set("tt_type", entity.type);
    url.searchParams.set("tt_id", entity.id);
    if (entity.name) url.searchParams.set("tt_name", entity.name);
    else url.searchParams.delete("tt_name");
    history.replaceState(null, "", url.pathname + url.search);
  }

  function clearUrlQuery() {
    const url = new URL(window.location.href);
    url.searchParams.delete("tt_type");
    url.searchParams.delete("tt_id");
    url.searchParams.delete("tt_name");
    history.replaceState(null, "", url.pathname + (url.search ? url.search : ""));
  }

  async function apiSearch(q) {
    const data = await fetchJson(`/api/search.php?q=${encodeURIComponent(q)}`);
    if (!data.success) throw apiErrorFromPayload(data, "Ошибка поиска");
    return data.items || [];
  }
  async function apiTimetable(entity) {
    const params = new URLSearchParams({ cat: entity.type, id: entity.id, name: entity.name || "" });
    const data = await fetchJson(`/api/timetable.php?${params}`);
    if (!data.success) throw apiErrorFromPayload(data, "Не удалось загрузить расписание");
    return data.data;
  }

  const KBP_BETA_URL = "https://kbp.by/rasp/timetable/view_beta_kbp/";

  function apiErrorFromPayload(data, fallback) {
    const err = new Error(String((data && data.error) || fallback));
    if (data && data.code) err.code = data.code;
    if (data && data.hint) err.hint = data.hint;
    if (data && data.url) err.url = data.url;
    return err;
  }

  function isKbpDownError(err) {
    if (!err) return false;
    if (err.code === "kbp_unavailable") return true;
    const raw = String(err.message || err || "");
    return /kbp\.by недоступен|kbp_unavailable|kbp unavailable/i.test(raw);
  }

  function kbpDownHtml({ compact = false, retryId = "" } = {}) {
    const retry = retryId
      ? `<button type="button" class="tt-loading__retry" id="${esc(retryId)}">Повторить</button>`
      : "";
    return `<div class="kbp-down ${compact ? "kbp-down--compact" : ""}" role="alert">
      <p class="kbp-down__title">kbp.by недоступен :(</p>
      <p class="kbp-down__hint">Проблема не может быть решена, временно используйте <a class="kbp-down__link" href="${esc(KBP_BETA_URL)}" target="_blank" rel="noopener noreferrer">kbp.by</a></p>
      ${retry}
    </div>`;
  }

  function showKbpDownNotice(extra = "") {
    els.kbpNotice.innerHTML = `${kbpDownHtml({ compact: true })}${extra ? `<p class="kbp-notice__extra">${esc(extra)}</p>` : ""}`;
    els.kbpNotice.classList.remove("hidden");
  }

  function friendlyNetworkError(err) {
    if (isKbpDownError(err)) return "kbp.by недоступен :(";
    const raw = String(err && err.message ? err.message : err || "");
    if (/NetworkError|Failed to fetch|Load failed|network|ERR_|abort/i.test(raw)) {
      return "Нет связи с сервером. Обновляем…";
    }
    if (/JSON|Unexpected token|incorrect/i.test(raw)) {
      return "Сервер временно недоступен. Обновляем…";
    }
    return raw || "Ошибка загрузки";
  }

  async function fetchJson(url, opts = {}) {
    let res;
    try {
      res = await fetch(url, { credentials: "same-origin", ...opts });
    } catch (e) {
      throw new Error(friendlyNetworkError(e));
    }
    let data = null;
    try {
      data = await res.json();
    } catch {
      if (res.status === 502) {
        const err = new Error("kbp.by недоступен :(");
        err.code = "kbp_unavailable";
        throw err;
      }
      throw new Error(
        res.status >= 500
          ? "Сервер временно недоступен. Обновляем…"
          : `Ошибка ответа (${res.status || "сеть"})`
      );
    }
    if (!res.ok && data && typeof data === "object" && data.error) {
      throw apiErrorFromPayload(data, String(data.error));
    }
    if (!res.ok) {
      // Cloudflare origin 502 body is plain "error code: 502" — not our JSON
      if (res.status === 502) {
        const err = new Error("kbp.by недоступен :(");
        err.code = "kbp_unavailable";
        throw err;
      }
      throw new Error(res.status === 503 ? "Сервер занят. Обновляем…" : `Ошибка ${res.status}`);
    }
    return data;
  }

  function sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  async function withRetry(fn, { attempts = 4, baseDelay = 600 } = {}) {
    let lastErr;
    for (let i = 0; i < attempts; i++) {
      try {
        return await fn();
      } catch (e) {
        lastErr = e;
        // kbp down / hard client errors — retrying only makes it worse
        if (isKbpDownError(e) || (e && e.status === 400)) throw e;
        if (i < attempts - 1) await sleep(baseDelay * (i + 1));
      }
    }
    throw lastErr;
  }

  function loadingTimetableHtml(name) {
    return `<div class="tt-loading" role="status" aria-live="polite">
      <div class="tt-loading__spin" aria-hidden="true"></div>
      <p class="tt-loading__title">Загрузка расписания</p>
      <p class="tt-loading__sub">${esc(name || "Подождите…")}</p>
    </div>`;
  }

  function resolveDayPairs(pairs, dayIndex, showReplacements) {
    const filtered = (pairs || []).filter((p) => {
      const weekOffset = p.weekOffset || 0;
      const match = dayIndex <= 5 ? weekOffset === 0 && p.day === dayIndex : weekOffset === 1 && p.day === 0;
      if (!match) return false;
      if (showReplacements) return p.status !== "removed" && p.status !== "cancelled";
      return p.status !== "added";
    });
    const map = new Map();
    for (const p of filtered) {
      const key = `${p.pairNumber}::${(p.subject || "").toLowerCase()}`;
      if (!map.has(key)) map.set(key, { ...p, lines: [] });
      const bucket = map.get(key);
      const cancelled = (p.subject || "").trim() === "Урок снят";
      const teacherFull = cancelled ? "" : (p.teacherFull || p.refs?.teachers?.[0]?.nameFull || "");
      const subjectFull = p.subjectFull || p.refs?.subject?.nameFull || "";
      if (subjectFull && !bucket.subjectFull) bucket.subjectFull = subjectFull;
      if (teacherFull && !bucket.teacherFull) bucket.teacherFull = teacherFull;
      const line = {
        group: cancelled ? "" : (p.group || ""),
        teacher: cancelled ? "" : (p.teacher || ""),
        teacherFull,
        room: cancelled ? "" : (p.room || ""),
        groupId: p.refs?.group?.id,
        teacherId: p.refs?.teachers?.[0]?.id,
        placeId: p.refs?.place?.id,
        subjectId: p.refs?.subject?.id,
      };
      if (line.group || line.teacher || line.room) bucket.lines.push(line);
      if (p.refs?.subject) bucket.refs = { ...(bucket.refs || {}), subject: p.refs.subject };
    }
    return [...map.values()].sort((a, b) => a.pairNumber - b.pairNumber);
  }

  function displaySubject(pair) {
    const short = (pair?.subject || "").trim();
    const full = (pair?.subjectFull || pair?.refs?.subject?.nameFull || "").trim();
    if (state.settings.timetableFullSubjectNames && full) return full;
    return short;
  }

  function displayTeacher(shortName, fullName) {
    const short = (shortName || "").trim();
    const full = (fullName || "").trim();
    if (state.settings.timetableFullTeacherNames && full) return full;
    return short;
  }

  function hoverTitle(shortName, fullName) {
    const short = (shortName || "").trim();
    const full = (fullName || "").trim();
    if (full && full !== short) return full;
    return short || full;
  }

  function entityBtn(label, type, id, variant, navName, titleText) {
    if (!label) return "";
    const cls = variant === "pill" ? "tt-entity-link tt-entity-link--pill" : "tt-entity-link";
    const nameForNav = navName || label;
    const titleAttr = titleText ? ` title="${esc(titleText)}"` : "";
    if (type && id) {
      return `<button type="button" class="${cls}" data-nav-type="${esc(type)}" data-nav-id="${esc(id)}" data-nav-name="${esc(nameForNav)}"${titleAttr}>${esc(label)}</button>`;
    }
    return variant === "pill"
      ? `<span class="tt-entity-link tt-entity-link--pill"${titleAttr}>${esc(label)}</span>`
      : `<span class="tt-entity-link"${titleAttr}>${esc(label)}</span>`;
  }

  const ICO_TEACHER = `<svg class="pair-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>`;
  const ICO_ROOM = `<svg class="pair-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>`;

  function renderSearchResults(items) {
    if (!items.length) {
      els.searchDropdown.classList.remove("hidden");
      els.searchDropdown.innerHTML = `<div class="search-empty">Ничего не найдено</div>`;
      return;
    }
    els.searchDropdown.classList.remove("hidden");
    els.searchDropdown.innerHTML = items.map((item) => {
      const short = item.name || "";
      const full = item.nameFull || "";
      const shown = full && (item.type === "teacher" || item.type === "subject") ? full : short;
      const title = full && full !== short ? full : short;
      const sub = full && full !== short && shown === full
        ? `<span class="search-result__short">${esc(short)}</span>`
        : "";
      return `<button type="button" class="search-result" data-type="${esc(item.type)}" data-id="${esc(item.id)}" data-name="${esc(short)}" title="${esc(title)}"><span class="search-result__name">${esc(shown)}${sub}</span><span class="search-result__type">${esc(item.typeLabel || typeLabel(item.type))}</span></button>`;
    }).join("");
  }

  function updateChrome() {
    const filled = !!(state.entity && state.timetable);
    els.ttInner.classList.toggle("tt-inner--centered", !filled);
    els.ttInner.classList.toggle("tt-inner--filled", filled);
    els.ttInner.classList.toggle("pc-pad", isPc() && state.settings.timetablePcSidePad);
    els.searchField.classList.toggle("has-trailing", state.entity?.type === "place");
    els.freeRoomsBtn.classList.toggle("hidden", state.entity?.type !== "place");
    els.weekSwitcher.classList.toggle("hidden", !(filled && isPc()));
    els.pcTitle.classList.toggle("hidden", !(filled && isPc()));
    if (state.entity) {
      els.pcTitleName.textContent = state.entity.name;
      els.pcTitleType.textContent = state.entity.typeLabel || typeLabel(state.entity.type);
    }
    if (filled) {
      const weekRange = getWeekDateRangeLabel(state.weekPage);
      els.weekPrimary.textContent = weekRange || (state.weekPage === 0 ? "Текущая неделя" : "Следующая неделя");
      els.weekSecondary.textContent = state.weekPage === 0 ? "Текущая неделя" : "Следующая неделя";
      els.weekPrev.disabled = state.weekPage === 0;
      els.weekNext.disabled = !(state.timetable.hasNextWeekMonday || state.timetable.hasNextWeek) || state.weekPage === 1;
    }
    renderRecent();
  }

  function nowMinutes() {
    const d = new Date(state.nowTickMs);
    return d.getHours() * 60 + d.getMinutes();
  }

  function getNowHighlightedPairNumber(dayIndex, pairs) {
    if (dayIndex !== liveDayIndex()) return null;
    for (const p of pairs) {
      const { start, end } = getKbpPairTime(p.pairNumber, bellDayIndex(dayIndex));
      const s = timeToMinutes(start);
      const e = timeToMinutes(end);
      if (s === null || e === null) continue;
      if (nowMinutes() >= s && nowMinutes() < e) return p.pairNumber;
    }
    return null;
  }

  function getNextHighlightedPairNumber(dayIndex, pairs) {
    if (dayIndex !== liveDayIndex()) return null;
    const now = getNowHighlightedPairNumber(dayIndex, pairs);
    if (now != null) return null;
    const nm = nowMinutes();
    let best = null;
    let bestStart = Infinity;
    for (const p of pairs) {
      const { start } = getKbpPairTime(p.pairNumber, bellDayIndex(dayIndex));
      const s = timeToMinutes(start);
      if (s === null || s <= nm) continue;
      if (s < bestStart) { bestStart = s; best = p.pairNumber; }
    }
    return best;
  }

  function getCountdownParen(pairNumber, dayIndex) {
    if (!state.settings.countdownToLesson || dayIndex !== liveDayIndex()) return null;
    const { start, end } = getKbpPairTime(pairNumber, bellDayIndex(dayIndex));
    const sMin = timeToMinutes(start);
    const eMin = timeToMinutes(end);
    if (sMin === null || eMin === null) return null;
    const now = state.nowTickMs;
    const base = new Date();
    const sDate = new Date(base);
    sDate.setHours(Math.floor(sMin / 60), sMin % 60, 0, 0);
    const eDate = new Date(base);
    eDate.setHours(Math.floor(eMin / 60), eMin % 60, 0, 0);
    if (now < sDate.getTime()) return formatCountdown(sDate.getTime() - now);
    if (now < eDate.getTime()) return formatCountdown(eDate.getTime() - now);
    return null;
  }

  function dayRangeText(dayIndex, pairs) {
    const dayRange = state.timetable?.dayStartTimes?.[dayIndex];
    if (dayRange?.start && dayRange?.end) return `${dayRange.start} - ${dayRange.end}`;
    const active = pairs.filter((p) => {
      const subj = (p.subject || "").trim();
      return subj && subj !== "Урок снят" && p.status !== "removed" && p.status !== "cancelled";
    });
    if (!active.length) return "—";
    const first = getKbpPairTime(active[0].pairNumber, bellDayIndex(dayIndex));
    const last = getKbpPairTime(active[active.length - 1].pairNumber, bellDayIndex(dayIndex));
    if (first.start && last.end) return `${first.start} - ${last.end}`;
    return "—";
  }

  function goPrevDay() {
    if (state.day <= 0) return;
    state.day -= 1;
    renderTimetable("l");
  }

  function goNextDay() {
    if (state.day >= maxDayIndex()) return;
    state.day += 1;
    renderTimetable("r");
  }

  function renderDayView(anim) {
    const s = state.settings;
    const day = state.day < 0 ? 0 : Math.min(state.day, maxDayIndex());
    state.day = day;
    const showRepl = !!state.showReplacementsDays[day];
    const pairs = resolveDayPairs(state.timetable.pairs || [], day, showRepl);
    const today = liveDayIndex();
    const dens = s.timetableDensity === "compact" ? "density-compact" : s.timetableDensity === "small" ? "density-small" : "";
    const replStatus = state.timetable.dayReplacementStatus?.[day];
    const showNoReplLabel = !!replStatus?.noChanges;
    const showReplToggle = dayHasReplacements(state.timetable, day);
    const highlighted = getNowHighlightedPairNumber(day, pairs);
    const nextHighlighted = getNextHighlightedPairNumber(day, pairs);
    const rangeText = dayRangeText(day, pairs);

    let strip = "";
    if (s.timetableDayStrip) {
      const maxDay = maxDayIndex();
      strip = `<div class="day-strip mobile-only">
        <button type="button" class="day-nav" id="dayPrev" ${day <= 0 ? "disabled" : ""} aria-label="Предыдущий день">‹</button>
        <div class="day-chips" id="dayChips">${Array.from({ length: maxDay + 1 }, (_, i) =>
          `<button type="button" class="day-chip ${day === i ? "is-active" : ""}" data-day="${i}">${DAYS[i]}</button>`
        ).join("")}</div>
        <button type="button" class="day-nav" id="dayNext" ${day >= maxDay ? "disabled" : ""} aria-label="Следующий день">›</button>
      </div>`;
    }

    const pairsHtml = pairs.length
      ? pairs.map((p) => {
          const cancelled = (p.subject || "").trim() === "Урок снят";
          const isNow = highlighted === p.pairNumber;
          const isNext = nextHighlighted === p.pairNumber;
          const pairTime = getKbpPairTime(p.pairNumber, bellDayIndex(day));
          const range = pairTime.start && pairTime.end
            ? `${formatBellClock(pairTime.start)} - ${formatBellClock(pairTime.end)}`
            : "—";
          const cd = getCountdownParen(p.pairNumber, day);
          const timeLine = cd ? `${range} (${cd})` : range;
          const linesSrc = (p.lines && p.lines.length)
            ? p.lines
            : [{ group: p.group, teacher: p.teacher, teacherFull: p.teacherFull || p.refs?.teachers?.[0]?.nameFull, room: p.room, groupId: p.refs?.group?.id, teacherId: p.refs?.teachers?.[0]?.id, placeId: p.refs?.place?.id }];
          const showGroupDash = s.timetableShowGroup && linesSrc.some((l) => l.group);
          const lines = linesSrc.map((l) => {
            const bits = [];
            if (s.timetableShowGroup && l.group) {
              bits.push(entityBtn(l.group, "group", l.groupId, "pill"));
            } else if (showGroupDash) {
              bits.push(`<span class="pair-dash" aria-hidden="true">—</span>`);
            }
            if (s.timetableShowTeacher && l.teacher && !cancelled) {
              const tShort = l.teacher;
              const tFull = l.teacherFull || "";
              bits.push(`<span class="pair-meta-item">${ICO_TEACHER}${entityBtn(displayTeacher(tShort, tFull), "teacher", l.teacherId, null, tShort, hoverTitle(tShort, tFull))}</span>`);
            }
            if (s.timetableShowRoom && l.room && !cancelled) {
              const roomNav = String(l.room).replace(/^ауд\.\s*/i, "").trim() || l.room;
              bits.push(`<span class="pair-meta-item">${ICO_ROOM}${entityBtn(`ауд. ${l.room}`, "place", l.placeId, null, roomNav)}</span>`);
            }
            return bits.length ? `<div class="pair-line">${bits.join("")}</div>` : "";
          }).join("");
          const subjId = p.refs?.subject?.id;
          const subjShort = p.subject || "";
          const subjFull = p.subjectFull || p.refs?.subject?.nameFull || "";
          return `<div class="pair status-${esc(p.status || "normal")} ${dens} ${isNow || isNext ? "pair--rail" : ""}">
            ${isNow ? `<div class="pair-rail pair-rail--now"><span>Сейчас</span></div>` : ""}
            ${isNext ? `<div class="pair-rail pair-rail--next"><span>Ближ.</span></div>` : ""}
            <div class="pair-body">
              ${s.timetableHidePairNumbers ? "" : `<div class="pair-num">${esc(p.pairNumber)}</div>`}
              <div class="pair-main">
                <div class="pair-time" data-pair-time="1" data-pair="${esc(p.pairNumber)}" data-day="${esc(day)}">${esc(timeLine)}</div>
                <div class="pair-subject">${entityBtn(displaySubject(p), "subject", subjId, null, subjShort, hoverTitle(subjShort, subjFull))}</div>
                ${lines}
              </div>
            </div>
          </div>`;
        }).join("")
      : `<div class="pair-empty">Пар нет</div>`;

    const animClass = anim === "r" ? "timetable-day-in-r" : anim === "l" ? "timetable-day-in-l" : "";
    const sticky = `<div class="tt-sticky-title mobile-only">
      <div class="tt-sticky-title__name">${esc(state.entity.name)}</div>
      ${state.entity.typeLabel ? `<div class="tt-sticky-title__type">${esc(state.entity.typeLabel)}</div>` : ""}
    </div>`;

    return `${sticky}${strip}
      <div class="timetable-mobile-wrap mobile-only ${today === day ? "is-today" : ""}">
        <div class="timetable-mobile-card" id="ttMobileCard" style="touch-action:pan-y pinch-zoom;overscroll-behavior-x:none">
          <div class="${animClass} timetable-day-anim">
            <div class="timetable-mobile-day-header ${today === day ? "is-today" : ""} ${dens}">
              <div class="tm-head-left">
                ${s.timetableDayStrip ? "" : `<div class="tm-day-name">${esc(DAYS_FULL[day])}</div>`}
                ${showNoReplLabel ? `<div class="tm-repl-label">Замен нет</div>` : ""}
                ${showReplToggle ? `<label class="tm-repl" title="Показать замены"><input type="checkbox" id="dayReplToggle" ${showRepl ? "checked" : ""}/><span>Замены</span></label>` : ""}
              </div>
              <div class="tm-head-right">
                <div class="tm-time">${esc(rangeText)}</div>
                ${today === day ? `<span class="tm-badge">Сегодня</span>` : ""}
              </div>
            </div>
            <div class="pair-list">${pairsHtml}</div>
            <div class="tt-bottom-spacer"></div>
          </div>
        </div>
      </div>`;
  }

  function getWeekDateRangeLabel(weekPage) {
    const tt = state.timetable;
    if (!tt) return "";
    const raw = weekPage === 0 ? (tt.currentWeek?.dateRange || "") : (tt.nextWeekMonday?.dateRange || "");
    return String(raw).replace(/&mdash;/gi, "—").replace(/\u2013|\u2014/g, "—").replace(/\s+/g, " ").trim();
  }

  function parseTimetableWeekDates(dateRange) {
    const s = String(dateRange || "")
      .replace(/&mdash;/gi, "—")
      .replace(/\u2013|\u2014/g, "—")
      .replace(/\s+/g, " ")
      .trim();
    if (!s) return [];
    const months = {
      января: 0, февраля: 1, марта: 2, апреля: 3, мая: 4, июня: 5,
      июля: 6, августа: 7, сентября: 8, октября: 9, ноября: 10, декабря: 11,
    };
    const inferYear = (month) => {
      const now = new Date();
      const y = now.getFullYear();
      const m = now.getMonth();
      if (month === 0 && m === 11) return y + 1;
      if (month === 11 && m === 0) return y - 1;
      return y;
    };
    const cross = s.match(/^(\d{1,2})\s+([а-яё]+)\s*—\s*(\d{1,2})\s+([а-яё]+)(?:\s+(\d{4}))?$/iu);
    if (cross) {
      const d1 = Number.parseInt(cross[1], 10);
      const mon1 = months[cross[2].toLowerCase()];
      if (mon1 === undefined) return [];
      const year = cross[5] ? Number.parseInt(cross[5], 10) : inferYear(mon1);
      const start = new Date(year, mon1, d1);
      const dates = [start];
      while (dates.length < 6) {
        const next = new Date(dates[dates.length - 1]);
        next.setDate(next.getDate() + 1);
        dates.push(next);
      }
      return dates;
    }
    const same = s.match(/^(\d{1,2})\s*—\s*(\d{1,2})\s+([а-яё]+)(?:\s+(\d{4}))?$/iu);
    if (same) {
      const d1 = Number.parseInt(same[1], 10);
      const mon = months[same[3].toLowerCase()];
      if (mon === undefined) return [];
      const year = same[4] ? Number.parseInt(same[4], 10) : inferYear(mon);
      const dates = [];
      for (let d = d1; dates.length < 6; d++) dates.push(new Date(year, mon, d));
      return dates;
    }
    return [];
  }

  function formatTimetableDayDate(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return "";
    return `${String(date.getDate()).padStart(2, "0")}.${String(date.getMonth() + 1).padStart(2, "0")}`;
  }

  function getWeekGridDisplayDay(weekPage, columnIndex) {
    if (weekPage === 0) {
      if (columnIndex >= 0 && columnIndex <= 5) return columnIndex;
      return null;
    }
    if (weekPage === 1 && columnIndex === 0) return 6;
    return null;
  }

  const PC_ROW_H = 104;
  const PC_HDR_H = 78;
  const PC_COLS = 6;
  const PC_COL_KEY = "timetable_pc_col_widths_v2";
  const PC_FR_MIN = 0.35;
  const PC_FR_MAX = 3;

  function clampColFr(v) {
    return Math.max(PC_FR_MIN, Math.min(PC_FR_MAX, v));
  }

  function defaultColFr() {
    return Array.from({ length: PC_COLS }, () => 1);
  }

  function readColFr() {
    try {
      const raw = JSON.parse(localStorage.getItem(PC_COL_KEY) || "null");
      if (Array.isArray(raw) && raw.length === PC_COLS) {
        const nums = raw.map(Number);
        if (!nums.some((n) => !Number.isFinite(n))) {
          const avg = nums.reduce((a, b) => a + b, 0) / nums.length;
          return nums.map((w) => clampColFr(w / (avg || 1)));
        }
      }
    } catch {}
    return defaultColFr();
  }

  function writeColFr(widths) {
    try {
      localStorage.setItem(PC_COL_KEY, JSON.stringify(widths.map(clampColFr)));
    } catch {}
  }

  function renderWeekGridCell(pair, pairNumber, displayDay, isNow) {
    const s = state.settings;
    const pairTime = getKbpPairTime(pairNumber, bellDayIndex(displayDay));
    const baseRange = pairTime.start && pairTime.end
      ? `${formatBellClock(pairTime.start)}–${formatBellClock(pairTime.end)}`
      : "";
    const cd = getCountdownParen(pairNumber, displayDay);
    const timeRange = cd && baseRange ? `${baseRange} (${cd})` : baseRange;
    const cancelled = (pair.subject || "").trim() === "Урок снят";
    const lines = (pair.lines && pair.lines.length)
      ? pair.lines
      : [{
          group: pair.group || "",
          teacher: pair.teacher || "",
          teacherFull: pair.teacherFull || pair.refs?.teachers?.[0]?.nameFull || "",
          room: pair.room || "",
          groupId: pair.refs?.group?.id,
          teacherId: pair.refs?.teachers?.[0]?.id,
          placeId: pair.refs?.place?.id,
        }];
    const showGroup = !!s.timetableShowGroup;
    const showTeacher = !!s.timetableShowTeacher;
    const showRoom = !!s.timetableShowRoom;
    const showGroupDash = showGroup && lines.some((l) => l.group);

    const groupsHtml = showGroup
      ? lines.map((l) => {
          if (l.group) return entityBtn(l.group, "group", l.groupId, "pill");
          if (showGroupDash) return `<span class="pc-cell-dash" aria-hidden="true">—</span>`;
          return "";
        }).join("")
      : "";

    const teachersHtml = showTeacher && !cancelled
      ? lines.map((l) => {
          if (!l.teacher) return `<div class="pc-cell-spacer" aria-hidden="true"></div>`;
          const tShort = l.teacher;
          const tFull = l.teacherFull || "";
          return `<div class="pc-cell-teacher">${entityBtn(displayTeacher(tShort, tFull), "teacher", l.teacherId, null, tShort, hoverTitle(tShort, tFull))}</div>`;
        }).join("")
      : "";

    const roomsHtml = showRoom && !cancelled
      ? lines.map((l) => {
          if (!l.room) return `<div class="pc-cell-spacer" aria-hidden="true"></div>`;
          const roomNav = String(l.room).replace(/^ауд\.\s*/i, "").trim() || l.room;
          return `<div class="pc-cell-room">${entityBtn(`ауд. ${l.room}`, "place", l.placeId, null, roomNav)}</div>`;
        }).join("")
      : "";

    const status = pair.status || "normal";
    const statusClass = status === "added" || status === "replaced"
      ? "is-added"
      : status === "removed" || status === "cancelled"
        ? "is-removed"
        : "";

    const subjShort = pair.subject || "";
    const subjFull = pair.subjectFull || pair.refs?.subject?.nameFull || "";

    return `<div class="pc-cell pc-cell--filled ${statusClass} ${isNow ? "is-now" : ""}" style="min-height:${PC_ROW_H}px" data-tt-cell="filled">
      <div class="pc-cell-inner">
        <div class="pc-cell-tl">${timeRange
          ? `<span class="pc-cell-time" data-pair-time="1" data-pair="${esc(pairNumber)}" data-day="${esc(displayDay)}">${esc(timeRange)}</span>`
          : ""}</div>
        <div class="pc-cell-tr">${groupsHtml}</div>
        <div class="pc-cell-bl">
          <div class="pc-cell-subj">${entityBtn(displaySubject(pair), "subject", pair.refs?.subject?.id, null, subjShort, hoverTitle(subjShort, subjFull))}</div>
          ${teachersHtml}
        </div>
        <div class="pc-cell-br">${roomsHtml}</div>
      </div>
    </div>`;
  }

  function renderWeekGrid() {
    const tt = state.timetable;
    const weekPage = state.weekPage;
    const today = liveDayIndex();
    const weekRange = getWeekDateRangeLabel(weekPage);
    const weekDates = parseTimetableWeekDates(weekRange);
    const colFr = readColFr();
    const gridCols = `2rem ${colFr.map((w) => `${w}fr`).join(" ")}`;

    const columnDays = Array.from({ length: PC_COLS }, (_, col) => getWeekGridDisplayDay(weekPage, col));
    const pairsByColumn = columnDays.map((displayDay) => {
      if (displayDay === null) return [];
      const showRepl = !!state.showReplacementsDays[displayDay];
      return resolveDayPairs(tt.pairs || [], displayDay, showRepl);
    });

    let maxPair = 7;
    for (const colPairs of pairsByColumn) {
      for (const p of colPairs) {
        if ((p.pairNumber || 0) > maxPair) maxPair = p.pairNumber;
      }
    }

    const nowPairByCol = weekPage === 0
      ? pairsByColumn.map((pairs, col) => {
          const d = columnDays[col];
          if (d === null || d !== today) return null;
          return getNowHighlightedPairNumber(d, pairs);
        })
      : Array(PC_COLS).fill(null);

    let html = `<div class="timetable-pc-wrap pc-only"><div class="timetable-pc-grid" id="pcGrid" style="grid-template-columns:${gridCols}">`;
    html += `<div class="pc-corner" style="min-height:${PC_HDR_H}px" aria-hidden="true"></div>`;

    for (let col = 0; col < PC_COLS; col++) {
      const displayDay = columnDays[col];
      const isToday = weekPage === 0 && today >= 0 && col === today && displayDay !== null;
      const dateLabel = weekDates[col] ? formatTimetableDayDate(weekDates[col]) : "";
      const hasRepl = displayDay !== null && dayHasReplacements(tt, displayDay);
      const showRepl = displayDay !== null ? !!state.showReplacementsDays[displayDay] : true;
      html += `<div class="pc-day-h ${isToday ? "is-today" : ""}" style="min-height:${PC_HDR_H}px">
        <div class="pc-day-date">${dateLabel ? esc(dateLabel) : "—"}</div>
        <div class="pc-day-name">${esc(DAYS[col])}</div>
        ${hasRepl && displayDay !== null
          ? `<label class="pc-day-repl" title="Показать замены"><input type="checkbox" data-pc-repl="${displayDay}" ${showRepl ? "checked" : ""}/><span>Замены</span></label>`
          : ""}
        <div class="pc-col-resizer" data-pc-resize="${col}" role="separator" aria-orientation="vertical" aria-label="Изменить ширину столбца"></div>
      </div>`;
    }

    for (let n = 1; n <= maxPair; n++) {
      html += `<div class="pc-pair-n" style="min-height:${PC_ROW_H}px">${n}</div>`;
      for (let col = 0; col < PC_COLS; col++) {
        const displayDay = columnDays[col];
        if (displayDay === null) {
          html += `<div class="pc-cell pc-cell--void" style="min-height:${PC_ROW_H}px"></div>`;
          continue;
        }
        const pair = pairsByColumn[col].find((p) => p.pairNumber === n) || null;
        const empty = !pair || !(pair.subject || "").trim();
        if (empty) {
          html += `<div class="pc-cell pc-cell--empty" style="min-height:${PC_ROW_H}px"></div>`;
          continue;
        }
        html += renderWeekGridCell(pair, n, displayDay, nowPairByCol[col] === n);
      }
    }

    html += `</div></div>`;
    return html;
  }

  function bindPcColResize() {
    const wrap = document.querySelector(".timetable-pc-wrap");
    const grid = $("pcGrid");
    if (!wrap || !grid) return;
    grid.querySelectorAll("[data-pc-resize]").forEach((el) => {
      el.onmousedown = (e) => {
        e.preventDefault();
        const col = Number(el.getAttribute("data-pc-resize"));
        const fr = readColFr();
        state.pcResize = { col, startX: e.clientX, startFr: fr[col], fr: [...fr] };
      };
    });
  }

  if (!window.__mkbpPcResizeBound) {
    window.__mkbpPcResizeBound = true;
    window.addEventListener("mousemove", (e) => {
      const drag = state.pcResize;
      if (!drag) return;
      const wrap = document.querySelector(".timetable-pc-wrap");
      const grid = $("pcGrid");
      if (!wrap || !grid) return;
      const trackW = Math.max(1, wrap.clientWidth - 32);
      const totalFr = drag.fr.reduce((a, b) => a + b, 0);
      const pxPerFr = trackW / totalFr;
      const next = [...drag.fr];
      next[drag.col] = clampColFr(drag.startFr + (e.clientX - drag.startX) / pxPerFr);
      writeColFr(next);
      grid.style.gridTemplateColumns = `2rem ${next.map((w) => `${w}fr`).join(" ")}`;
    });
    window.addEventListener("mouseup", () => { state.pcResize = null; });
  }

  function bindSwipe() {
    const card = $("ttMobileCard");
    if (!card) return;
    card.addEventListener("touchstart", (e) => {
      e.stopPropagation();
      const t = e.touches[0];
      if (!t) return;
      state.touchStart = { x: t.clientX, y: t.clientY };
    }, { passive: true });
    card.addEventListener("touchmove", (e) => {
      const start = state.touchStart;
      if (!start) return;
      const t = e.touches[0];
      if (!t) return;
      const dx = Math.abs(t.clientX - start.x);
      const dy = Math.abs(t.clientY - start.y);
      if (dx > dy && dx > 8) e.preventDefault();
    }, { passive: false });
    card.addEventListener("touchcancel", () => { state.touchStart = null; });
    card.addEventListener("touchend", (e) => {
      e.stopPropagation();
      const start = state.touchStart;
      state.touchStart = null;
      if (!start) return;
      const t = e.changedTouches[0];
      if (!t) return;
      const dx = t.clientX - start.x;
      const dy = t.clientY - start.y;
      const threshold = Math.max(40, Math.round(window.innerWidth * 0.1));
      if (Math.abs(dx) < threshold) return;
      if (Math.abs(dy) > Math.abs(dx) * 0.75) return;
      if (dx < 0) goNextDay();
      else goPrevDay();
    }, { passive: true });
  }

  function scrollActiveDayChip() {
    const chips = $("dayChips");
    if (!chips) return;
    const active = chips.querySelector(".day-chip.is-active");
    active?.scrollIntoView({ behavior: "smooth", inline: "center", block: "nearest" });
  }

  function renderTimetable(anim) {
    if (!state.timetable || !state.entity) {
      els.ttContent.classList.add("hidden");
      updateChrome();
      return;
    }
    const hasPairs = Array.isArray(state.timetable.pairs) && state.timetable.pairs.length > 0;
    els.ttContent.classList.remove("hidden");
    if (!hasPairs) {
      els.ttContent.innerHTML = `<div class="tt-empty-state mobile-only"><p class="tt-empty-state__title">Расписание не видно</p><p class="tt-empty-state__sub">На этой неделе нет пар или данные не загрузились. Выберите другую группу в поиске.</p></div>${renderWeekGrid()}`;
      bindPcColResize();
      updateChrome();
      return;
    }
    els.ttContent.innerHTML = renderDayView(anim) + renderWeekGrid();
    bindSwipe();
    bindPcColResize();
    scrollActiveDayChip();
    updateChrome();
  }

  async function openEntity(entity, opts = {}) {
    const ent = normalizeEntity(entity);
    if (!ent) return;
    state.entity = ent;
    if (!opts.skipRecent) saveRecent(ent);
    persistQuery(ent);
    pushUrlQuery(ent);
    els.searchDropdown.classList.add("hidden");
    els.searchInput.value = ent.name;
    renderRecent();
    if (!opts.silent) els.searchSpinner.classList.remove("hidden");
    els.kbpNotice.classList.add("hidden");

    // Prefer showing cache immediately, then refresh in background.
    const snap = loadFreshSnapshot();
    const snapEnt = snap?.entity ? normalizeEntity(snap.entity) : null;
    const hasCache =
      snapEnt && snapEnt.type === ent.type && snapEnt.id === ent.id && snap?.data;
    if (hasCache) {
      state.timetable = snap.data;
      if (!opts.keepDay) {
        const today = liveDayIndex();
        state.day = today < 0 ? 0 : today;
        state.weekPage = 0;
      }
      syncReplacementsFromTimetable();
      renderTimetable();
      if (!opts.keepTab) setTab(1);
    } else if (!state.timetable || state.entity?.id !== ent.id) {
      els.ttContent.classList.remove("hidden");
      els.ttContent.innerHTML = loadingTimetableHtml(ent.name);
      updateChrome();
      if (!opts.keepTab) setTab(1);
    }

    try {
      const data = await withRetry(() => apiTimetable(ent), { attempts: 5, baseDelay: 500 });
      state.timetable = data;
      persistSnapshot(ent, data);
      if (state.settings.notificationsEnabled) {
        void syncWatchNotifications(false);
      }
      if (!opts.keepDay && !hasCache) {
        const today = liveDayIndex();
        state.day = today < 0 ? 0 : today;
        state.weekPage = 0;
      }
      syncReplacementsFromTimetable();
      renderTimetable();
      els.kbpNotice.classList.add("hidden");
      if (!opts.keepTab) setTab(1);
    } catch (e) {
      const hasPairs = !!(state.timetable && Array.isArray(state.timetable.pairs) && state.timetable.pairs.length);
      if (isKbpDownError(e)) {
        if (hasPairs || hasCache) {
          // Soft notice only — schedule already on screen from cache
          els.kbpNotice.textContent = "Показано сохранённое расписание (kbp.by временно недоступен).";
          els.kbpNotice.classList.remove("hidden");
        } else {
          showKbpDownNotice();
          els.ttContent.classList.remove("hidden");
          els.ttContent.innerHTML = `<div class="tt-loading tt-loading--error">${kbpDownHtml({ retryId: "ttRetryBtn" })}</div>`;
          updateChrome();
          $("ttRetryBtn")?.addEventListener("click", () => openEntity(ent, { ...opts, silent: false }));
        }
      } else {
        const msg = friendlyNetworkError(e);
        if (hasPairs || hasCache) {
          els.kbpNotice.textContent = `${msg} Показано сохранённое расписание.`;
          els.kbpNotice.classList.remove("hidden");
        } else {
          els.kbpNotice.textContent = msg;
          els.kbpNotice.classList.remove("hidden");
          els.ttContent.classList.remove("hidden");
          els.ttContent.innerHTML = `<div class="tt-loading tt-loading--error">
            <p class="tt-loading__title">Не удалось загрузить</p>
            <p class="tt-loading__sub">${esc(msg)}</p>
            <button type="button" class="tt-loading__retry" id="ttRetryBtn">Повторить</button>
          </div>`;
          updateChrome();
          $("ttRetryBtn")?.addEventListener("click", () => openEntity(ent, { ...opts, silent: false }));
        }
      }
    } finally {
      els.searchSpinner.classList.add("hidden");
    }
  }

  function applyCachedTimetable(entity, data) {
    state.entity = normalizeEntity(entity);
    state.timetable = data;
    els.searchInput.value = state.entity?.name || "";
    const today = liveDayIndex();
    state.day = today < 0 ? 0 : today;
    state.weekPage = 0;
    syncReplacementsFromTimetable();
    persistQuery(state.entity);
    pushUrlQuery(state.entity);
    renderTimetable();
  }

  async function bootstrapTimetable() {
    migrateLegacyRecent();
    purgeLegacyBrowserCache();
    renderRecent();

    const fromUrl = parseUrlQuery();
    const stored = loadStoredQuery();
    const preferred = fromUrl || stored;
    const snap = loadFreshSnapshot();

    if (preferred && snap && snap.entity.type === preferred.type && snap.entity.id === preferred.id) {
      applyCachedTimetable({ ...snap.entity, name: preferred.name || snap.entity.name }, snap.data);
      setTab(state.tab === 0 ? 0 : 1);
      await openEntity(preferred, { silent: true, skipRecent: false, keepDay: true, keepTab: true });
      return;
    }

    if (preferred) {
      await openEntity(preferred, { silent: false, keepTab: state.tab === 0 });
      return;
    }

    // No saved query: do not resurrect orphan snapshots (often stale leftovers).
  }

  function toggle(id, on) {
    return `<span class="toggle ${on ? "is-on" : ""}" data-toggle-ui="${id}" aria-hidden="true"></span>`;
  }

  function settingsToggleRow(key, title, sub, on) {
    return `<button type="button" class="settings-row" data-toggle="${esc(key)}" role="switch" aria-checked="${on ? "true" : "false"}">
      <div>${sub
        ? `<div class="settings-row__title">${title}</div><div class="settings-row__sub">${sub}</div>`
        : `<div class="settings-row__title">${title}</div>`}
      </div>${toggle(key, on)}
    </button>`;
  }

  function renderSettings() {
    const s = state.settings;
    const root = els.settingsRoot;
    if (!root) return;
    if (state.settingsScreen === "hub") {
      root.innerHTML = `
        <h1 class="settings-hub-title">Настройки</h1>
        <div class="section-card">
          <button type="button" class="settings-row" data-go="appearance"><div><div class="settings-row__title">Оформление</div><div class="settings-row__sub">Тема, акцент, расписание</div></div><svg class="settings-row__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg></button>
          <button type="button" class="settings-row" data-go="notifications"><div><div class="settings-row__title">Уведомления</div><div class="settings-row__sub">Замены в расписании</div></div><svg class="settings-row__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg></button>
          <button type="button" class="settings-row" data-go="calendar"><div><div class="settings-row__title">Календарь</div><div class="settings-row__sub">Google, Apple и Android</div></div><svg class="settings-row__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg></button>
          <button type="button" class="settings-row" data-go="clear"><div><div class="settings-row__title">Очистка</div><div class="settings-row__sub">Кэш приложения и расписаний</div></div><svg class="settings-row__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg></button>
        </div>
        <div class="section-card" style="margin-top:1rem">
          <div class="about-row"><div class="settings-row__title" style="font-size:15px">О приложении</div><div class="version-badge">${esc(APP_VERSION)} · ${esc(APP_STAGE)}</div></div>
          <a class="about-link" href="https://t.me/meowhiks" target="_blank" rel="noopener"><span class="about-link__icon" style="background:#229ED910;color:#229ED9"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M9.78 14.25l-.43 4.05c.62 0 .89-.27 1.21-.58l2.91-2.77 6.04 4.42c1.11.61 1.9.29 2.18-1.03L22.9 4.3c.33-1.53-.55-2.13-1.63-1.76L2.18 9.17C.7 9.74.72 10.57 1.93 10.94l5.05 1.58 11.73-7.4c.55-.33 1.05-.15.64.21"/></svg></span><span><div class="about-link__title">Есть идеи? @meowhiks</div><div class="about-link__sub">t.me/meowhiks</div></span></a>
          <a class="about-link" href="https://www.donationalerts.com/r/meowhiks_off" target="_blank" rel="noopener"><span class="about-link__icon" style="background:#ff5dc510;color:#ff5dc5"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 9.5 6H5.2l2.6 3.1L6.6 14l5.4-2.6L17.4 14l-1.2-4.9L18.8 6H14.5L12 2Zm-7 14h14v2H5v-2Zm1 4h12v2H6v-2Z"/></svg></span><span><div class="about-link__title">Пожертвуйте на разработку</div><div class="about-link__sub">.../r/meowhiks</div></span></a>
          <a class="about-link" href="https://github.com/meowhiks" target="_blank" rel="noopener"><span class="about-link__icon" style="background:#e5e7eb;color:#111"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 .5C5.73.5.5 5.74.5 12.02c0 5.1 3.29 9.42 7.86 10.95.58.11.79-.25.79-.56 0-.28-.01-1.02-.02-2-3.2.7-3.88-1.54-3.88-1.54-.53-1.36-1.3-1.72-1.3-1.72-1.06-.73.08-.72.08-.72 1.17.08 1.79 1.21 1.79 1.21 1.04 1.78 2.73 1.27 3.4.97.1-.75.41-1.27.74-1.56-2.55-.29-5.23-1.28-5.23-5.7 0-1.26.45-2.29 1.19-3.1-.12-.29-.52-1.47.11-3.06 0 0 .97-.31 3.18 1.18a11.1 11.1 0 0 1 5.79 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.77.11 3.06.74.81 1.18 1.84 1.18 3.1 0 4.43-2.69 5.4-5.25 5.69.42.36.79 1.08.79 2.18 0 1.57-.01 2.84-.01 3.23 0 .31.21.68.8.56A10.52 10.52 0 0 0 23.5 12C23.5 5.74 18.27.5 12 .5Z"/></svg></span><span><div class="about-link__title">Открытый исходный код</div><div class="about-link__sub">github.com/meowhiks</div></span></a>
        </div>`;
      return;
    }

    const back = `<button type="button" class="settings-back" data-go="hub"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg>Настройки</button>`;
    if (state.settingsScreen === "notifications") {
      root.innerHTML = `${back}<h2 class="settings-page-title">Уведомления</h2>
        <div class="section-card">
          ${settingsToggleRow("notificationsEnabled", "Уведомления", "", s.notificationsEnabled)}
          ${s.notificationsEnabled ? settingsToggleRow("notifyTimetable", "Замены", "", s.notifyTimetable) : ""}
        </div>`;
      return;
    }
    
    if (state.settingsScreen === "calendar") {
      const ent = state.entity || (() => {
        try { return normalizeEntity(JSON.parse(localStorage.getItem(QUERY_KEY) || "null")); } catch { return null; }
      })();
      const has = !!(ent && ent.type && ent.id);
      const sub = has
        ? `${esc(ent.name || ent.id)}${ent.typeLabel ? ` · ${esc(ent.typeLabel)}` : ""}`
        : "Сначала выберите расписание в поиске";
      root.innerHTML = `${back}<h2 class="settings-page-title">Календарь</h2>
        <div class="section-card">
          <div class="settings-row" style="cursor:default">
            <div><div class="settings-row__title">Расписание</div><div class="settings-row__sub">${sub}</div></div>
          </div>
        </div>
        <div class="section-card" style="margin-top:0.75rem">
          <button type="button" class="settings-row" data-cal="google" ${has ? "" : "disabled"} ${has ? "" : 'style="opacity:.45;cursor:not-allowed"'}>
            <div><div class="settings-row__title">Google Календарь</div><div class="settings-row__sub">${/Android/i.test(navigator.userAgent) ? "Подписка webcal" : "Подписка по ссылке"}</div></div>
            <svg class="settings-row__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg>
          </button>
          <button type="button" class="settings-row" data-cal="webcal" ${has ? "" : "disabled"} ${has ? "" : 'style="opacity:.45;cursor:not-allowed"'}>
            <div><div class="settings-row__title">Apple / Android</div><div class="settings-row__sub">Подписка webcal</div></div>
            <svg class="settings-row__chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg>
          </button>
          <button type="button" class="settings-row" data-cal="copy" ${has ? "" : "disabled"} ${has ? "" : 'style="opacity:.45;cursor:not-allowed"'}>
            <div><div class="settings-row__title">Скопировать ссылку</div><div class="settings-row__sub" data-cal-copy-sub>ICS для подписки</div></div>
          </button>
        </div>
        <p class="settings-hint" style="margin:0.75rem 1rem 0;font-size:12px;color:var(--app-muted);line-height:1.45">
          На телефоне откроется приложение календаря — подтвердите подписку.<br/>
          На компьютере в Google: «Добавить по URL» и вставьте ссылку из буфера.<br/>
          Расписание обновляется само (замены подтянутся с сайта).
        </p>`;
      return;
    }

    if (state.settingsScreen === "appearance") {
      root.innerHTML = `${back}<h2 class="settings-page-title">Оформление</h2>
        <div class="section-header">Тема</div>
        <div class="theme-grid">
          <button type="button" class="theme-card ${s.theme==="light"?"is-active":""}" data-theme="light"><div class="theme-preview" style="background:#fff;border-color:#e5e7eb"></div><div class="theme-card__label">Светлая</div></button>
          <button type="button" class="theme-card ${s.theme==="dark"?"is-active":""}" data-theme="dark"><div class="theme-preview" style="background:#141414;border-color:rgba(255,255,255,.15)"></div><div class="theme-card__label">Тёмная</div></button>
          <button type="button" class="theme-card ${s.theme==="oled"?"is-active":""}" data-theme="oled"><div class="theme-preview" style="background:#000;border-color:#27272a"></div><div class="theme-card__label">OLED</div></button>
        </div>
        <div class="section-header">Акцент</div>
        <div class="accent-row">${ACCENTS.map((a) => `<button type="button" class="accent-dot ${s.accentColor===a.id?"is-active":""}" data-accent="${a.id}" title="${esc(a.label)}" style="background:${a.hex}"></button>`).join("")}</div>
        <div class="section-header">Расписание</div>
        <div class="section-card">
          ${settingsToggleRow("showReplacementsByDefault", "Показывать замены по умолчанию", "", s.showReplacementsByDefault)}
          ${settingsToggleRow("timetableShowGroup", "Показывать группу", "", s.timetableShowGroup)}
          ${settingsToggleRow("timetableShowTeacher", "Показывать преподавателя", "", s.timetableShowTeacher)}
          ${settingsToggleRow("timetableShowRoom", "Показывать аудиторию", "", s.timetableShowRoom)}
          ${settingsToggleRow("timetableFullSubjectNames", "Полные названия предметов", "", s.timetableFullSubjectNames)}
          ${settingsToggleRow("timetableFullTeacherNames", "Полные ФИО преподавателей", "", s.timetableFullTeacherNames)}
          ${settingsToggleRow("timetableHidePairNumbers", "Скрыть номер пары слева", "", s.timetableHidePairNumbers)}
          ${settingsToggleRow("timetableDayStrip", "Панель дней (Пн–Сб)", "", s.timetableDayStrip)}
          ${settingsToggleRow("timetablePcSidePad", "Шире поля по бокам (только ПК)", "Расписание не растягивается на весь экран", s.timetablePcSidePad)}
          ${settingsToggleRow("countdownToLesson", "Отсчёт до урока", "Показывать таймер в расписании", s.countdownToLesson)}
        </div>
        <div class="section-header">Плотность расписания</div>
        <div class="seg">
          <button type="button" data-density="normal" class="${s.timetableDensity==="normal"?"is-active":""}">Обычная</button>
          <button type="button" data-density="compact" class="${s.timetableDensity==="compact"?"is-active":""}">Компакт.</button>
          <button type="button" data-density="small" class="${s.timetableDensity==="small"?"is-active":""}">Мини</button>
        </div>`;
      return;
    }
    if (state.settingsScreen === "clear") {
      root.innerHTML = `${back}<h2 class="settings-page-title">Очистка</h2>
        <div class="section-card">
          <label class="settings-row" style="cursor:pointer"><div><div class="settings-row__title">Кэш приложения</div><div class="settings-row__sub">Журнал, профиль, сессии</div></div><input type="checkbox" id="clrCache"/></label>
          <label class="settings-row" style="cursor:pointer"><div><div class="settings-row__title">Кэш расписаний</div><div class="settings-row__sub">Последнее загруженное + недавние</div></div><input type="checkbox" id="clrTt" checked/></label>
        </div>
        <button type="button" class="clear-btn" id="clearBtn">Очистить выбранное</button>`;
    }
  }

  // Events
  els.navSettings.addEventListener("click", () => {
    state.settingsScreen = "hub";
    setTab(0);
  });
  els.navTimetable.addEventListener("click", () => setTab(1));

  window.addEventListener("popstate", () => applyRouteFromUrl());

  els.settingsRoot.addEventListener("click", (e) => {
    const go = e.target.closest("[data-go]");
    if (go) {
      e.preventDefault();
      state.settingsScreen = go.dataset.go || "hub";
      renderSettings();
      syncUrlRoute();
      return;
    }
    const cal = e.target.closest("[data-cal]");
    if (cal && !cal.disabled) {
      e.preventDefault();
      void connectCalendar(cal.dataset.cal || "");
      return;
    }
    const theme = e.target.closest("[data-theme]");
    if (theme) {
      e.preventDefault();
      state.settings.theme = theme.dataset.theme;
      saveSettings();
      renderSettings();
      return;
    }
    const accent = e.target.closest("[data-accent]");
    if (accent) {
      e.preventDefault();
      state.settings.accentColor = accent.dataset.accent;
      saveSettings();
      renderSettings();
      return;
    }
    const dens = e.target.closest("[data-density]");
    if (dens) {
      e.preventDefault();
      state.settings.timetableDensity = dens.dataset.density;
      saveSettings();
      renderSettings();
      if (state.timetable) renderTimetable();
      return;
    }
    const tog = e.target.closest("[data-toggle]");
    if (tog) {
      e.preventDefault();
      const key = tog.dataset.toggle;
      if (!key) return;
      state.settings[key] = !state.settings[key];
      if (key === "showReplacementsByDefault") syncReplacementsFromTimetable();
      if (key === "countdownToLesson") state.nowTickMs = Date.now();
      if (key === "notificationsEnabled" || key === "notifyTimetable") {
        void syncWatchNotifications(key === "notificationsEnabled" && state.settings.notificationsEnabled);
      }
      saveSettings();
      renderSettings();
      // Full-name toggles need server fields — refresh timetable if turning ON
      if ((key === "timetableFullSubjectNames" || key === "timetableFullTeacherNames")
        && state.settings[key] && state.entity) {
        openEntity(state.entity, { silent: true, keepDay: true, keepTab: true, skipRecent: true });
      } else if (state.timetable) {
        renderTimetable();
      }
      updateChrome();
      return;
    }
    if (e.target.id === "clearBtn" || e.target.closest("#clearBtn")) {
      e.preventDefault();
      const cache = $("clrCache")?.checked;
      const tt = $("clrTt")?.checked;
      if (tt) {
        try {
          localStorage.removeItem(RECENT_KEY);
          localStorage.removeItem(CACHE_SNAP_KEY);
          localStorage.removeItem(QUERY_KEY);
          purgeLegacyBrowserCache();
        } catch {}
        state.entity = null;
        state.timetable = null;
        els.ttContent.classList.add("hidden");
        els.searchInput.value = "";
        clearUrlQuery();
        updateChrome();
      }
      if (cache) {
        try {
          sessionStorage.removeItem(TAB_KEY);
          purgeLegacyBrowserCache();
        } catch {}
      }
      alert("Очищено");
      renderRecent();
    }
  });

  els.searchInput.addEventListener("input", () => {
    const q = els.searchInput.value.trim();
    clearTimeout(state.searchTimer);
    if (!q) { els.searchDropdown.classList.add("hidden"); return; }
    state.searchTimer = setTimeout(async () => {
      els.searchSpinner.classList.remove("hidden");
      els.searchDropdown.classList.remove("hidden");
      els.searchDropdown.innerHTML = `<div class="search-empty">Загрузка…</div>`;
      try {
        renderSearchResults(await withRetry(() => apiSearch(q), { attempts: 3, baseDelay: 400 }));
      } catch (err) {
        els.searchDropdown.innerHTML = isKbpDownError(err)
          ? kbpDownHtml({ compact: true })
          : `<div class="search-empty">${esc(friendlyNetworkError(err))}</div>`;
      } finally {
        els.searchSpinner.classList.add("hidden");
      }
    }, 300);
  });

  els.searchDropdown.addEventListener("click", (e) => {
    const btn = e.target.closest(".search-result");
    if (!btn) return;
    openEntity({ type: btn.dataset.type, id: btn.dataset.id, name: btn.dataset.name, typeLabel: btn.querySelector(".search-result__type")?.textContent || "" });
  });

  els.recentChips.addEventListener("click", (e) => {
    if (e.target.closest("[data-x]")) {
      const chip = e.target.closest(".recent-chip");
      removeRecent(chip.dataset.type, chip.dataset.id);
      e.stopPropagation();
      return;
    }
    const chip = e.target.closest(".recent-chip");
    if (chip) openEntity({ type: chip.dataset.type, id: chip.dataset.id, name: chip.dataset.name });
  });

  els.ttContent.addEventListener("click", (e) => {
    const dayChip = e.target.closest("[data-day]");
    if (dayChip) {
      const next = Number(dayChip.dataset.day);
      const anim = next > state.day ? "r" : next < state.day ? "l" : "";
      state.day = next;
      renderTimetable(anim);
      return;
    }
    if (e.target.closest("#dayPrev") || e.target.id === "dayPrev") { goPrevDay(); return; }
    if (e.target.closest("#dayNext") || e.target.id === "dayNext") { goNextDay(); return; }
    const nav = e.target.closest("[data-nav-type]");
    if (nav) {
      openEntity({ type: nav.dataset.navType, id: nav.dataset.navId, name: nav.dataset.navName });
    }
  });

  els.ttContent.addEventListener("change", (e) => {
    const mobileRepl = e.target.id === "dayReplToggle" ? e.target : null;
    if (mobileRepl) {
      state.showReplacementsDays = state.showReplacementsDays.map((v, i) => (i === state.day ? mobileRepl.checked : v));
      renderTimetable();
      return;
    }
    const input = e.target.closest("input[data-pc-repl]");
    if (!input) return;
    const day = Number(input.getAttribute("data-pc-repl"));
    if (!Number.isFinite(day)) return;
    state.showReplacementsDays = state.showReplacementsDays.map((v, i) => (i === day ? input.checked : v));
    renderTimetable();
  });

  els.weekPrev.addEventListener("click", () => {
    state.weekPage = 0;
    const today = liveDayIndex();
    state.day = today < 0 ? 0 : Math.min(today, 5);
    renderTimetable();
  });
  els.weekNext.addEventListener("click", () => {
    state.weekPage = 1;
    if (maxDayIndex() >= 6) state.day = 6;
    renderTimetable();
  });

  els.freeRoomsBtn.addEventListener("click", () => openFreeRooms());
  $("freeRoomsBack").addEventListener("click", () => els.freeRoomsPanel.classList.add("hidden"));
  document.querySelectorAll(".fr-chip").forEach((chip) => {
    chip.addEventListener("click", () => {
      document.querySelectorAll(".fr-chip").forEach((c) => c.classList.remove("is-active"));
      chip.classList.add("is-active");
      state.freeWhen = chip.dataset.when;
      els.frDate.classList.toggle("hidden", state.freeWhen !== "date");
      scanFreeRooms();
    });
  });
  els.frLesson.addEventListener("change", () => { state.freeLesson = els.frLesson.value; scanFreeRooms(); });
  els.frDate.addEventListener("change", () => scanFreeRooms());

  function fillLessonSelect() {
    let html = `<option value="auto">${state.freeWhen === "now" ? "Текущий/ближ. урок" : "Любая занятость"}</option>`;
    for (let i = 0; i <= 13; i++) html += `<option value="${i}">${i} урок</option>`;
    els.frLesson.innerHTML = html;
    els.frLesson.value = state.freeLesson;
  }

  async function openFreeRooms() {
    fillLessonSelect();
    els.freeRoomsPanel.classList.remove("hidden");
    scanFreeRooms();
  }

  async function scanFreeRooms() {
    els.frSub.textContent = "Сканируем…";
    els.frList.innerHTML = `<div class="fr-loading">Ищем дальше…</div>`;
    const params = new URLSearchParams({ when: state.freeWhen, lesson: state.freeLesson });
    if (state.freeWhen === "date" && els.frDate.value) params.set("date", els.frDate.value);
    try {
      const data = await withRetry(
        () => fetchJson(`/api/free-rooms.php?${params}`),
        { attempts: 3, baseDelay: 500 }
      );
      if (!data.success) throw apiErrorFromPayload(data, "Ошибка");
      const hits = data.hits || [];
      els.frSub.textContent = `Найдено: ${hits.length}`;
      if (!hits.length) {
        els.frList.innerHTML = `<div class="fr-empty">Свободных аудиторий не найдено</div>`;
        return;
      }
      els.frList.innerHTML = hits.map((h) =>
        `<button type="button" class="fr-row" data-type="place" data-id="${esc(h.id)}" data-name="${esc(h.name)}"><span class="fr-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg></span><span style="flex:1;font-weight:500">ауд. ${esc(h.name)}</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18l6-6-6-6"/></svg></button>`
      ).join("");
    } catch (e) {
      if (isKbpDownError(e)) {
        els.frSub.textContent = "kbp.by недоступен";
        els.frList.innerHTML = kbpDownHtml();
      } else {
        els.frSub.textContent = "Ошибка";
        els.frList.innerHTML = `<div class="fr-empty">${esc(friendlyNetworkError(e))}</div>`;
      }
    }
  }

  els.frList.addEventListener("click", (e) => {
    const row = e.target.closest(".fr-row");
    if (!row) return;
    els.freeRoomsPanel.classList.add("hidden");
    openEntity({ type: "place", id: row.dataset.id, name: row.dataset.name, typeLabel: "аудитория" });
    setTab(1);
  });

  // Boot splash — Capacitor AppBootSplash 1:1
  const CYCLE_MS = 900;
  const FADE_MS = 480;
  let splashPhase = "run";
  let splashIndex = 0;
  let cyclesDone = false;

  function renderSplashSlogan() {
    const waiting = cyclesDone && !state.contentReady;
    const slogan = SLOGANS[Math.min(splashIndex, SLOGANS.length - 1)];
    els.boot.innerHTML = `<div class="app-boot-splash__track"><p class="app-boot-splash__slogan ${waiting ? "app-boot-splash__slogan--pulse" : ""}">${slogan}</p></div>`;
    els.slogan = els.boot.querySelector(".app-boot-splash__slogan");
  }

  function beginFade() {
    if (splashPhase !== "run") return;
    splashPhase = "fade";
    els.boot.classList.remove("app-boot-splash--skippable");
    els.boot.classList.add("app-boot-splash--out");
    els.boot.removeAttribute("role");
    els.boot.removeAttribute("tabindex");
    setTimeout(() => {
      splashPhase = "gone";
      els.boot.classList.add("hidden");
      els.boot.style.display = "none";
    }, FADE_MS);
  }

  function updateSkipState() {
    const skippable = state.contentReady && splashPhase === "run";
    els.boot.classList.toggle("app-boot-splash--skippable", skippable);
    if (skippable) {
      els.boot.setAttribute("role", "button");
      els.boot.setAttribute("tabindex", "0");
      els.boot.setAttribute("aria-label", "Пропустить");
    } else {
      els.boot.removeAttribute("role");
      els.boot.removeAttribute("tabindex");
      els.boot.removeAttribute("aria-label");
    }
  }

  function runSplash() {
    renderSplashSlogan();
    const id = setInterval(() => {
      if (splashPhase !== "run") { clearInterval(id); return; }
      splashIndex += 1;
      if (splashIndex >= SLOGANS.length) {
        cyclesDone = true;
        splashIndex = SLOGANS.length - 1;
        clearInterval(id);
        renderSplashSlogan();
        if (state.contentReady) beginFade();
        return;
      }
      renderSplashSlogan();
    }, CYCLE_MS);

    els.boot.addEventListener("click", () => {
      if (state.contentReady && splashPhase === "run") beginFade();
    });
    els.boot.addEventListener("keydown", (e) => {
      if (!state.contentReady || splashPhase !== "run") return;
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); beginFade(); }
    });
  }

  function markContentReady() {
    state.contentReady = true;
    updateSkipState();
    if (splashPhase === "run") beginFade();
  }

  let watchTimer = null;
  let broadcastTimer = null;
  let swReg = null;

  function postToSw(msg) {
    return new Promise((resolve) => {
      if (!swReg?.active) {
        resolve({ ok: false, error: "no-sw" });
        return;
      }
      const ch = new MessageChannel();
      ch.port1.onmessage = (ev) => resolve(ev.data || {});
      swReg.active.postMessage(msg, [ch.port2]);
      setTimeout(() => resolve({ ok: false, error: "timeout" }), 20000);
    });
  }

  async function ensureServiceWorker() {
    if (!("serviceWorker" in navigator)) return null;
    try {
      swReg = await navigator.serviceWorker.register("/sw.js?v=3", { scope: "/" });
      await navigator.serviceWorker.ready;
      swReg = await navigator.serviceWorker.getRegistration();
      // Wait until an active worker exists (fresh install can be "installing" briefly)
      if (swReg && !swReg.active) {
        await new Promise((resolve) => {
          const w = swReg.installing || swReg.waiting;
          if (!w) { resolve(); return; }
          w.addEventListener("statechange", () => {
            if (w.state === "activated" || swReg.active) resolve();
          });
          setTimeout(resolve, 5000);
        });
        swReg = await navigator.serviceWorker.getRegistration();
      }
      return swReg;
    } catch (e) {
      console.warn("SW register failed", e);
      return null;
    }
  }

  async function pollTestBroadcast() {
    if (!state.settings.notificationsEnabled) return;
    if (typeof Notification === "undefined" || Notification.permission !== "granted") return;
    try {
      const res = await fetch("/api/notify-broadcast.php", {
        credentials: "same-origin",
        cache: "no-store",
      });
      if (!res.ok) return;
      const json = await res.json();
      const b = json && json.broadcast;
      if (!b || !b.id) return;
      const seenKey = "mkbp_broadcast_seen_id";
      if (localStorage.getItem(seenKey) === String(b.id)) return;
      localStorage.setItem(seenKey, String(b.id));
      const title = String(b.title || "MiniKBP");
      const body = String(b.body || "Тестовое уведомление с сайта");
      const opts = {
        body,
        icon: "/assets/icons/minikbp.png",
        badge: "/assets/icons/favicon-32.png",
        tag: `mkbp-broadcast-${b.id}`,
        renotify: true,
        requireInteraction: true,
        data: { url: "/" },
      };
      const reg = await ensureServiceWorker();
      if (reg && reg.showNotification) {
        await reg.showNotification(title, opts);
        return;
      }
      // Fallback (some browsers): page Notification API
      new Notification(title, opts);
    } catch (e) {
      console.warn("broadcast poll failed", e);
    }
  }


  function calendarFeedUrl(entity) {
    if (!entity?.type || !entity?.id) return "";
    // API refreshes feed then redirects to static /cal/{cat}-{id}.ics
    const u = new URL("/api/calendar.ics.php", window.location.origin);
    u.searchParams.set("cat", entity.type);
    u.searchParams.set("id", entity.id);
    if (entity.name) u.searchParams.set("name", entity.name);
    return u.toString();
  }

  function calendarStaticUrl(entity) {
    if (!entity?.type || !entity?.id) return "";
    return new URL(`/cal/${encodeURIComponent(entity.type)}-${encodeURIComponent(entity.id)}.txt`, window.location.origin).toString();
  }

  function calendarWebcalUrl(entity) {
    const https = calendarStaticUrl(entity) || calendarFeedUrl(entity);
    if (!https) return "";
    return https.replace(/^https:/i, "webcal:").replace(/^http:/i, "webcal:");
  }

  function openWebcalSubscribe(webcalUrl) {
    if (!webcalUrl) return;
    // Android: webcal opens Calendar / Google Calendar subscribe sheet
    if (/Android/i.test(navigator.userAgent)) {
      const https = webcalUrl.replace(/^webcal:/i, "https:");
      const intent = `intent://${https.replace(/^https?:\/\//i, "")}#Intent;scheme=webcal;action=android.intent.action.VIEW;category=android.intent.category.BROWSABLE;end`;
      try {
        window.location.href = intent;
        return;
      } catch {}
    }
    window.location.href = webcalUrl;
  }

  async function connectCalendar(kind) {
    const ent = state.entity || (() => {
      try { return normalizeEntity(JSON.parse(localStorage.getItem(QUERY_KEY) || "null")); } catch { return null; }
    })();
    if (!ent?.type || !ent?.id) {
      alert("Сначала выберите расписание в поиске.");
      return;
    }
    const httpsUrl = calendarFeedUrl(ent);
    // Warm/refresh static file, then prefer direct /cal/… for subscribers
    try { await fetch(httpsUrl, { method: "GET", redirect: "follow", cache: "no-store" }); } catch {}
    const staticUrl = calendarStaticUrl(ent);
    const subscribeUrl = staticUrl || httpsUrl;
    const webcalUrl = subscribeUrl.replace(/^https:/i, "webcal:").replace(/^http:/i, "webcal:");
    const onAndroid = /Android/i.test(navigator.userAgent);

    if (kind === "copy") {
      try {
        await navigator.clipboard.writeText(subscribeUrl);
      } catch {
        prompt("Ссылка на календарь:", subscribeUrl);
      }
      const sub = document.querySelector("[data-cal-copy-sub]");
      if (sub) sub.textContent = "Скопировано";
      setTimeout(() => { if (sub) sub.textContent = "ICS для подписки"; }, 2000);
      return;
    }

    // Apple / Android / Google-on-Android: native subscribe via webcal
    if (kind === "webcal" || kind === "apple" || (kind === "google" && onAndroid)) {
      openWebcalSubscribe(webcalUrl);
      return;
    }

    if (kind === "google") {
      try {
        await navigator.clipboard.writeText(subscribeUrl);
      } catch {
        prompt("Ссылка на календарь:", subscribeUrl);
      }
      window.open("https://calendar.google.com/calendar/u/0/r/settings/addbyurl", "_blank", "noopener,noreferrer");
    }
  }

  async function syncWatchNotifications(requestPermission = false) {
    const enabled = !!state.settings.notificationsEnabled;
    const notifyTt = state.settings.notifyTimetable !== false;
    if (!enabled) {
      if (watchTimer) {
        clearInterval(watchTimer);
        watchTimer = null;
      }
      if (broadcastTimer) {
        clearInterval(broadcastTimer);
        broadcastTimer = null;
      }
      await ensureServiceWorker();
      await postToSw({ type: "mkbp-watch-config", enabled: false, notifyTimetable: false, entity: null });
      return;
    }
    if (requestPermission && typeof Notification !== "undefined" && Notification.permission === "default") {
      try {
        await Notification.requestPermission();
      } catch {}
    }
    if (typeof Notification !== "undefined" && Notification.permission !== "granted") {
      state.settings.notificationsEnabled = false;
      saveSettings();
      alert("Разрешите уведомления в браузере, чтобы следить за заменами.");
      renderSettings();
      return;
    }
    const reg = await ensureServiceWorker();
    const entity = state.entity || (() => {
      try {
        return normalizeEntity(JSON.parse(localStorage.getItem(QUERY_KEY) || "null"));
      } catch {
        return null;
      }
    })();
    await postToSw({
      type: "mkbp-watch-config",
      enabled: true,
      notifyTimetable: notifyTt,
      entity: entity ? { type: entity.type, id: entity.id, name: entity.name || "" } : null,
    });
    if (reg && "periodicSync" in reg) {
      try {
        await reg.periodicSync.register("mkbp-tt-watch", { minInterval: 60 * 60 * 1000 });
      } catch (e) {
        console.warn("periodicSync unavailable", e);
      }
    }
    if (watchTimer) clearInterval(watchTimer);
    watchTimer = setInterval(() => {
      void postToSw({ type: "mkbp-watch-check" });
    }, 60 * 60 * 1000);
    if (broadcastTimer) clearInterval(broadcastTimer);
    // Тестовый broadcast: опрос со страницы (надёжнее, чем только SW message)
    broadcastTimer = setInterval(() => {
      void pollTestBroadcast();
      void postToSw({ type: "mkbp-broadcast-check" });
    }, 10 * 1000);
    setTimeout(() => { void postToSw({ type: "mkbp-watch-check" }); }, 15000);
    setTimeout(() => { void pollTestBroadcast(); }, 500);
    setTimeout(() => { void pollTestBroadcast(); }, 3000);
  }

  // init — timetable is the default page; `?settings=` opens settings
  loadSettings();
  applyTheme();
  document.title = "Расписание Колледжа Бизнеса и Права — Мини КБиП";
  const today = liveDayIndex();
  state.day = today < 0 ? 0 : today;
  const settingsFromUrl = parseSettingsFromUrl();
  if (settingsFromUrl !== null) {
    state.settingsScreen = settingsFromUrl;
    state.tab = 0;
  } else {
    state.tab = 1;
    state.settingsScreen = "hub";
  }
  setTab(state.tab, { skipUrl: true });
  if (settingsFromUrl !== null) syncUrlRoute();
  renderRecent();
  updateChrome();
  fillLessonSelect();
  runSplash();

  void bootstrapTimetable().finally(() => {
    state.bootstrapped = true;
    markContentReady();
    if (state.settings.notificationsEnabled) {
      void syncWatchNotifications(false);
    }
  });

  function pairTimeLabel(pairNumber, dayIndex) {
    const pairTime = getKbpPairTime(pairNumber, bellDayIndex(dayIndex));
    const sep = isPc() ? "–" : " - ";
    const range = pairTime.start && pairTime.end
      ? `${formatBellClock(pairTime.start)}${sep}${formatBellClock(pairTime.end)}`
      : "—";
    const cd = getCountdownParen(pairNumber, dayIndex);
    return cd ? `${range} (${cd})` : range;
  }

  function tickCountdownLabels() {
    if (!state.settings.countdownToLesson || !state.timetable) return;
    els.ttContent.querySelectorAll("[data-pair-time]").forEach((el) => {
      const pairNumber = Number(el.getAttribute("data-pair"));
      const dayIndex = Number(el.getAttribute("data-day"));
      if (!Number.isFinite(pairNumber) || !Number.isFinite(dayIndex)) return;
      el.textContent = pairTimeLabel(pairNumber, dayIndex);
    });
  }

  let lastHighlightKey = "";
  setInterval(() => {
    if (!state.timetable || !state.entity) return;
    state.nowTickMs = Date.now();
    const day = state.day;
    const showRepl = !!state.showReplacementsDays[day];
    const pairs = resolveDayPairs(state.timetable.pairs || [], day, showRepl);
    const highlightKey = `${getNowHighlightedPairNumber(day, pairs)}:${getNextHighlightedPairNumber(day, pairs)}:${state.weekPage}`;
    if (highlightKey !== lastHighlightKey) {
      lastHighlightKey = highlightKey;
      renderTimetable();
      return;
    }
    tickCountdownLabels();
  }, 1000);

  window.addEventListener("resize", () => { if (state.timetable) renderTimetable(); updateChrome(); });
})();
