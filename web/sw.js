/* SignalScope service worker — offline shell (cache-first for app files, network for everything else) */
const CACHE = "signalscope-v6";
const SHELL = ["./", "index.html", "css/app.css", "js/engine.js", "js/app.js", "lab.html", "ripple.html", "brand/amu.css", "brand/amu.js", "brand/amu-bar.js", "brand/colleges.json", "brand/crest.svg", "brand/icon.svg", "brand/icon-192.png", "vendor/three.min.js", "vendor/OrbitControls.js", "icon.svg", "manifest.webmanifest"];
self.addEventListener("install", e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener("activate", e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener("fetch", e => {
  const u = new URL(e.request.url);
  if (e.request.method !== "GET" || u.origin !== location.origin) return;   // never cache speed-test traffic
  e.respondWith(caches.match(e.request).then(r => r || fetch(e.request).then(res => { const cp = res.clone(); caches.open(CACHE).then(c => c.put(e.request, cp)); return res; })));
});
