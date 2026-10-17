(function () {
  var STORAGE_KEY = "vm_utm";
  var ENTRY_KEY = "vm_entry";

  function readUrlUtms() {
    var out = {};
    try {
      new URLSearchParams(location.search).forEach(function (value, key) {
        if (/^utm_/i.test(key) && value) out[key] = value;
      });
    } catch (e) {}
    return out;
  }

  function loadStored() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      var data = raw ? JSON.parse(raw) : null;
      if (!data || typeof data !== "object") return {};
      var out = {};
      Object.keys(data).forEach(function (key) {
        if (/^utm_/i.test(key) && data[key]) out[key] = String(data[key]);
      });
      return out;
    } catch (e) {
      return {};
    }
  }

  function save(utms) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(utms));
    } catch (e) {}
  }

  function currentUrl() {
    return location.origin + location.pathname + location.search;
  }

  function loadEntry() {
    try {
      return sessionStorage.getItem(ENTRY_KEY) || "";
    } catch (e) {
      return "";
    }
  }

  function saveEntry(url) {
    try {
      sessionStorage.setItem(ENTRY_KEY, url);
    } catch (e) {}
  }

  var fromUrl = readUrlUtms();
  var stored = loadStored();
  var utms = Object.keys(fromUrl).length ? fromUrl : stored;
  var entry = loadEntry();
  var sameCampaign =
    Object.keys(fromUrl).length && JSON.stringify(fromUrl) === JSON.stringify(stored);
  if (Object.keys(fromUrl).length && !sameCampaign) save(fromUrl);
  if (!entry || (Object.keys(fromUrl).length && !sameCampaign)) saveEntry(currentUrl());

  function applyUtms(href) {
    if (!href || !Object.keys(utms).length) return href;
    var raw = String(href).trim();
    if (!raw || raw.charAt(0) === "#") return href;
    if (/^(mailto:|tel:|sms:|javascript:)/i.test(raw)) return href;

    var url;
    try {
      url = new URL(raw, location.href);
    } catch (e) {
      return href;
    }

    if (url.origin !== location.origin) return href;
    if (url.pathname === location.pathname && url.search === location.search) return href;

    var changed = false;
    Object.keys(utms).forEach(function (key) {
      if (!url.searchParams.has(key)) {
        url.searchParams.set(key, utms[key]);
        changed = true;
      }
    });
    if (!changed) return href;
    return url.pathname + url.search + url.hash;
  }

  function decorateAnchor(anchor) {
    var current = anchor.getAttribute("href");
    if (!current) return;
    var next = applyUtms(current);
    if (next && next !== current) anchor.setAttribute("href", next);
  }

  function decorateAll() {
    var links = document.querySelectorAll("a[href]");
    for (var i = 0; i < links.length; i++) decorateAnchor(links[i]);
  }

  document.addEventListener(
    "click",
    function (event) {
      var anchor = event.target && event.target.closest && event.target.closest("a[href]");
      if (anchor) decorateAnchor(anchor);
    },
    true
  );

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", decorateAll);
  } else {
    decorateAll();
  }

  window.vmWithUtm = applyUtms;
  window.vmUtms = function () {
    var out = {};
    Object.keys(utms).forEach(function (key) {
      out[key] = utms[key];
    });
    return out;
  };
  window.vmEntryUrl = function () {
    return loadEntry() || currentUrl();
  };
})();
