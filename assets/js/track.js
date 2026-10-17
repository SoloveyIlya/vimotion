(function () {
  var KEY = "vm_vid";
  var VARIANT_KEY = "vm_ab_landing_v1";
  var fallbackVid = null;
  var TRACK_URL = (function () {
    var script = document.currentScript && document.currentScript.src;
    if (script) {
      try {
        // track.js lives in /assets/js/, so go up two levels to /api/track.php.
        return new URL("../../api/track.php", script).href;
      } catch (e) {}
    }
    return "/api/track.php";
  })();

  function makeId() {
    return (window.crypto && crypto.randomUUID && crypto.randomUUID()) ||
      "v-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10);
  }

  function vid() {
    try {
      var id = localStorage.getItem(KEY);
      if (!id) {
        id = makeId();
        localStorage.setItem(KEY, id);
      }
      return id;
    } catch (e) {
      try {
        var sessionId = sessionStorage.getItem(KEY);
        if (!sessionId) {
          sessionId = makeId();
          sessionStorage.setItem(KEY, sessionId);
        }
        return sessionId;
      } catch (e2) {
        if (!fallbackVid) fallbackVid = makeId();
        return fallbackVid;
      }
    }
  }

  function readVariant() {
    var value = String(window.vmAbVariant || "").toUpperCase();
    if (value === "A" || value === "B") return value;
    try {
      value = String(localStorage.getItem(VARIANT_KEY) || "").toUpperCase();
    } catch (e) {}
    if (value === "A" || value === "B") return value;
    try {
      value = String(sessionStorage.getItem(VARIANT_KEY) || "").toUpperCase();
    } catch (e) {}
    if (value === "A" || value === "B") return value;
    try {
      var match = document.cookie.match(new RegExp("(?:^|; )" + VARIANT_KEY + "=([^;]*)"));
      value = match ? decodeURIComponent(match[1]).toUpperCase() : "";
    } catch (e) { value = ""; }
    return value === "A" || value === "B" ? value : null;
  }

  window.vmAbVariant = window.vmAbVariant || readVariant();

  window.vmTrack = function (event) {
    if (!event) return;
    var payload = JSON.stringify({
      event: event,
      vid: vid(),
      variant: readVariant(),
      path: location.pathname + location.search,
    });

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(TRACK_URL, new Blob([payload], { type: "application/json" }));
        return;
      }
    } catch (e) {}

    try {
      fetch(TRACK_URL, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: payload,
        keepalive: true,
      }).catch(function () {});
    } catch (e) {}
  };

  window.vmVid = vid;
  window.vmGetAbVariant = readVariant;

  document.addEventListener("click", function (event) {
    var target = event.target && event.target.closest && event.target.closest("[data-lead-open], [data-ab-cta], [data-play-lesson]");
    if (target && typeof window.vmTrack === "function") window.vmTrack("cta_click");
  }, true);
})();
