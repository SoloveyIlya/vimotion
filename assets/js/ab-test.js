/* ViMotion landing A/B. PHP assigns the variant on /landing/; this only keeps it sticky. */
(function () {
  var KEY = "vm_ab_landing_v1";
  var params;
  try { params = new URLSearchParams(window.location.search); }
  catch (e) { params = null; }

  function valid(value) {
    value = String(value || "").toUpperCase();
    return value === "A" || value === "B" ? value : "";
  }

  function cookieValue() {
    try {
      var match = document.cookie.match(new RegExp("(?:^|; )" + KEY + "=([^;]*)"));
      return match ? decodeURIComponent(match[1]) : "";
    } catch (e) { return ""; }
  }

  function readStored() {
    var value = "";
    try { value = valid(localStorage.getItem(KEY)); } catch (e) {}
    if (value) return value;
    try { value = valid(sessionStorage.getItem(KEY)); } catch (e) {}
    return value || valid(cookieValue());
  }

  function save(value) {
    try { localStorage.setItem(KEY, value); } catch (e) {}
    try { sessionStorage.setItem(KEY, value); } catch (e) {}
    try {
      var secure = window.location.protocol === "https:" ? "; Secure" : "";
      document.cookie = KEY + "=" + encodeURIComponent(value) + "; Path=/; Max-Age=15552000; SameSite=Lax" + secure;
    } catch (e) {}
  }

  function pixelData(variant) {
    variant = valid(variant) || "A";
    return { ab_variant: variant, content_name: "landing_" + variant };
  }

  var forced = params ? valid(params.get("ab_variant")) : "";
  var variant = forced || valid(window.vmAbVariant) || readStored() || "A";

  save(variant);
  window.vmAbVariant = variant;
  window.vmAbPixelData = function () { return pixelData(window.vmAbVariant); };
})();
