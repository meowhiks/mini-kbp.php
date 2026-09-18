/* MiniKBP web — background timetable watch (замены) + test broadcast v3 */
const WATCH_CACHE = "mkbp-watch-v1";
const WATCH_URL = "/__mkbp_watch_state__";
const SNAP_URL = "/__mkbp_watch_snap__";

async function readJson(cacheName, url) {
  try {
    const cache = await caches.open(cacheName);
    const res = await cache.match(url);
    if (!res) return null;
    return await res.json();
  } catch {
    return null;
  }
}

async function writeJson(cacheName, url, data) {
  const cache = await caches.open(cacheName);
  await cache.put(url, new Response(JSON.stringify(data), {
    headers: { "Content-Type": "application/json" },
  }));
}

function pairKey(p) {
  return `${p.weekOffset || 0}:${p.day}:${p.pairNumber}`;
}

function fingerprint(data) {
  const pairs = Array.isArray(data?.pairs) ? data.pairs : [];
  return pairs
    .map((p) => `${pairKey(p)}|${p.status || ""}|${p.subject || ""}|${p.teacher || ""}|${p.room || ""}`)
    .sort()
    .join("\n");
}

function detectChanges(oldData, newData) {
  const out = [];
  if (!oldData?.pairs || !newData?.pairs) return out;
  const oldMap = new Map();
  for (const p of oldData.pairs) oldMap.set(pairKey(p), p);
  for (const neu of newData.pairs) {
    const key = pairKey(neu);
    const old = oldMap.get(key);
    const n = Number(neu.pairNumber) || 0;
    const subj = String(neu.subject || "Замена").trim() || "Замена";
    if (!old) {
      out.push({ title: `Замена ${n} урока!`, body: `${subj} вместо занятия` });
    } else if (String(old.subject || "") !== String(neu.subject || "")) {
      out.push({
        title: `Замена ${n} урока!`,
        body: `${subj} вместо ${String(old.subject || "занятие").trim() || "занятие"}`,
      });
    } else if (String(old.status || "") !== String(neu.status || "")
      && ["added", "replaced", "removed", "cancelled"].includes(String(neu.status || ""))) {
      out.push({
        title: `Замена ${n} урока!`,
        body: `${subj} вместо ${String(old.subject || "занятие").trim() || "занятие"}`,
      });
    } else if (String(old.teacher || "") !== String(neu.teacher || "")
      || String(old.room || "") !== String(neu.room || "")) {
      out.push({
        title: `Изменение ${n} урока`,
        body: `${subj}${neu.room ? ` · ауд. ${neu.room}` : ""}`,
      });
    }
  }
  return out.slice(0, 3);
}

async function fetchTimetable(entity) {
  const params = new URLSearchParams({
    cat: entity.type,
    id: entity.id,
    name: entity.name || "",
  });
  const res = await fetch(`/api/timetable.php?${params}`, {
    credentials: "same-origin",
    cache: "no-store",
  });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const json = await res.json();
  if (!json?.success || !json.data) throw new Error(json?.error || "fail");
  return json.data;
}

async function showChanges(entity, changes) {
  if (!changes.length) return;
  const name = entity?.name || "Расписание";
  for (const c of changes) {
    await self.registration.showNotification(c.title || "Замены", {
      body: `${name}: ${c.body || ""}`,
      icon: "/assets/icons/minikbp.png",
      badge: "/assets/icons/favicon-32.png",
      tag: `mkbp-tt-${entity.type}-${entity.id}-${c.title}`,
      renotify: true,
      data: { url: `/?tt_type=${encodeURIComponent(entity.type)}&tt_id=${encodeURIComponent(entity.id)}&tt_name=${encodeURIComponent(entity.name || "")}` },
    });
  }
}

async function checkBroadcast() {
  // Test broadcast: достаточно permission (не требуем watch enabled)
  if (typeof Notification === "undefined" || Notification.permission !== "granted") {
    return { ok: false, error: "permission" };
  }
  try {
    const res = await fetch("/api/notify-broadcast.php", {
      credentials: "same-origin",
      cache: "no-store",
    });
    if (!res.ok) return { ok: false, error: `HTTP ${res.status}` };
    const json = await res.json();
    const b = json?.broadcast;
    if (!b?.id) return { ok: true, empty: true };
    const seen = await readJson(WATCH_CACHE, "/__mkbp_broadcast_seen__");
    if (seen?.id === b.id) return { ok: true, seen: true };
    await self.registration.showNotification(String(b.title || "MiniKBP"), {
      body: String(b.body || "Тестовое уведомление с сайта"),
      icon: "/assets/icons/minikbp.png",
      badge: "/assets/icons/favicon-32.png",
      tag: `mkbp-broadcast-${b.id}`,
      renotify: true,
      requireInteraction: true,
      data: { url: "/" },
    });
    await writeJson(WATCH_CACHE, "/__mkbp_broadcast_seen__", { id: b.id, at: Date.now() });
    return { ok: true, shown: true, id: b.id };
  } catch (e) {
    return { ok: false, error: String(e?.message || e) };
  }
}

async function runWatchCheck() {
  const broadcast = await checkBroadcast();
  const state = await readJson(WATCH_CACHE, WATCH_URL);
  if (!state?.enabled || !state?.notifyTimetable || !state?.entity?.type || !state?.entity?.id) {
    return { ok: true, skipped: true, broadcast };
  }
  if (Notification.permission !== "granted") {
    return { ok: false, error: "permission", broadcast };
  }
  try {
    const data = await fetchTimetable(state.entity);
    const fp = fingerprint(data);
    const prev = await readJson(WATCH_CACHE, SNAP_URL);
    if (prev?.fp && prev.fp !== fp) {
      const changes = detectChanges(prev.data, data);
      if (changes.length) await showChanges(state.entity, changes);
      else {
        await self.registration.showNotification("Расписание обновлено", {
          body: `${state.entity.name || "Расписание"}: есть изменения`,
          icon: "/assets/icons/minikbp.png",
          tag: `mkbp-tt-${state.entity.type}-${state.entity.id}`,
          data: { url: "/" },
        });
      }
    }
    await writeJson(WATCH_CACHE, SNAP_URL, { fp, data, savedAt: Date.now() });
    return { ok: true, fp, broadcast };
  } catch (e) {
    return { ok: false, error: String(e?.message || e), broadcast };
  }
}

self.addEventListener("install", (e) => {
  self.skipWaiting();
  e.waitUntil(Promise.resolve());
});

self.addEventListener("activate", (e) => {
  e.waitUntil(self.clients.claim());
});

self.addEventListener("message", (e) => {
  const msg = e.data || {};
  if (msg.type === "mkbp-watch-config") {
    e.waitUntil((async () => {
      await writeJson(WATCH_CACHE, WATCH_URL, {
        enabled: !!msg.enabled,
        notifyTimetable: msg.notifyTimetable !== false,
        entity: msg.entity || null,
        updatedAt: Date.now(),
      });
      if (msg.enabled && msg.entity) {
        try {
          const data = await fetchTimetable(msg.entity);
          await writeJson(WATCH_CACHE, SNAP_URL, {
            fp: fingerprint(data),
            data,
            savedAt: Date.now(),
          });
        } catch {}
      }
      e.ports?.[0]?.postMessage({ ok: true });
    })());
  }
  if (msg.type === "mkbp-watch-check") {
    e.waitUntil(runWatchCheck().then((r) => e.ports?.[0]?.postMessage(r)));
  }
  if (msg.type === "mkbp-broadcast-check") {
    e.waitUntil(checkBroadcast().then((r) => e.ports?.[0]?.postMessage(r)));
  }
});

self.addEventListener("periodicsync", (e) => {
  if (e.tag === "mkbp-tt-watch") {
    e.waitUntil(runWatchCheck());
  }
});

self.addEventListener("notificationclick", (e) => {
  e.notification.close();
  const url = e.notification?.data?.url || "/";
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    for (const c of all) {
      if ("focus" in c) {
        await c.focus();
        if ("navigate" in c) try { await c.navigate(url); } catch {}
        return;
      }
    }
    await self.clients.openWindow(url);
  })());
});
