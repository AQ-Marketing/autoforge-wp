<?php
/**
 * AQ_Places_Autocomplete — fleet-wide Google address type-ahead for lead forms.
 *
 * When a Google Maps key is set (AutoForge → Integrations), this prints ONE
 * shared front-end script + style. Any form can opt in by giving a text input
 * the `data-aq-address-autocomplete` marker and placing a
 * `<ul class="aq-addr-ac__list">` next to it inside a `.aq-addr-ac` wrapper;
 * the script fills the form's address / city / state / zip fields on select.
 *
 * We deliberately do NOT use Google's <gmp-place-autocomplete> web component:
 * its prediction dropdown renders in a closed shadow DOM / top layer that gets
 * clipped or fails to paint inside form cards (predictions return but never
 * show). Instead we call the Places Autocomplete Data API and render our own
 * <ul>, which we fully control. Maps JS is lazy-loaded on first focus, so a
 * performance optimizer's "delay JavaScript" has nothing to strip.
 */
if (!defined('ABSPATH')) {
	exit;
}

class AQ_Places_Autocomplete {

	public static function register(): void {
		add_action('wp_footer', [__CLASS__, 'print_assets'], 7);
	}

	/** Public browser Maps key (referrer-restricted), or '' when unconfigured. */
	public static function key(): string {
		return class_exists('AQ_Integrations') ? (string) AQ_Integrations::maps_key() : '';
	}

	public static function print_assets(): void {
		if (is_admin()) {
			return;
		}
		$key = self::key();
		if ($key === '') {
			return;
		}
		?>
<style id="aq-addr-ac-css">
.aq-addr-ac{position:relative}
.aq-addr-ac__list{position:absolute;z-index:60;top:calc(100% + 4px);left:0;right:0;margin:0;padding:.3rem;list-style:none;background:#fff;border:1px solid #d7dbe0;border-radius:11px;box-shadow:0 14px 34px rgba(0,0,0,.16);max-height:264px;overflow-y:auto}
.aq-addr-ac__item{padding:.55rem .7rem;border-radius:8px;font-size:.92rem;line-height:1.3;color:#111;cursor:pointer}
.aq-addr-ac__item:hover,.aq-addr-ac__item.is-active{background:rgba(0,0,0,.06)}
.aq-addr-ac__item[aria-selected=true]{background:rgba(0,0,0,.06)}
</style>
<script id="aq-addr-ac-js">
(function(){
  if (window.__aqAddrAC) { return; } window.__aqAddrAC = true;
  var KEY = <?php echo wp_json_encode($key); ?>;
  var mapsPromise = null;
  function loadMaps(){
    if (mapsPromise) { return mapsPromise; }
    mapsPromise = new Promise(function(resolve, reject){
      if (window.google && google.maps && google.maps.places && google.maps.places.AutocompleteSuggestion) { resolve(); return; }
      var cb = '__aqMapsCb_' + Date.now();
      window[cb] = function(){ try { delete window[cb]; } catch(e){} resolve(); };
      var s = document.createElement('script');
      s.async = true;
      s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(KEY) + '&libraries=places&loading=async&callback=' + cb;
      s.onerror = function(){ reject(new Error('maps-load-failed')); };
      document.head.appendChild(s);
    });
    return mapsPromise;
  }
  function setVal(form, name, val){
    if (!name) { return; }
    var i = form.querySelector('[name="' + name + '"]');
    if (i && val) { i.value = val; i.dispatchEvent(new Event('input', {bubbles:true})); }
  }
  function wire(input){
    if (input.dataset.aqAcReady) { return; } input.dataset.aqAcReady = '1';
    var wrap = input.closest('.aq-addr-ac');
    var list = wrap ? wrap.querySelector('.aq-addr-ac__list') : null;
    var form = input.closest('form');
    if (!list || !form) { return; }
    // Target field names (overridable per-input via data attributes).
    var F = {
      address: input.getAttribute('data-aq-ac-address') || 'address',
      city:    input.getAttribute('data-aq-ac-city')    || 'city',
      state:   input.getAttribute('data-aq-ac-state')   || 'state',
      zip:     input.getAttribute('data-aq-ac-zip')     || 'zip'
    };
    var token = null, items = [], active = -1, seq = 0, tmr = null;
    function close(){ list.hidden = true; list.innerHTML = ''; items = []; active = -1; input.setAttribute('aria-expanded','false'); }
    function pick(t, longName){ return function(g){ var x = g[t]; return x ? (longName === false ? (x.shortText || '') : (x.longText || '')) : ''; }; }
    function render(){
      list.innerHTML = '';
      if (!items.length) { close(); return; }
      items.forEach(function(sg, i){
        var pred = sg.placePrediction; if (!pred) { return; }
        var li = document.createElement('li');
        li.className = 'aq-addr-ac__item' + (i === active ? ' is-active' : '');
        li.setAttribute('role','option');
        li.setAttribute('aria-selected', i === active ? 'true' : 'false');
        li.textContent = pred.text ? pred.text.text : '';
        li.addEventListener('mousedown', function(e){ e.preventDefault(); select(i); });
        list.appendChild(li);
      });
      list.hidden = false; input.setAttribute('aria-expanded','true');
    }
    function query(v){
      seq++; var mine = seq;
      loadMaps().then(function(){
        if (mine !== seq) { return; }
        if (!token) { token = new google.maps.places.AutocompleteSessionToken(); }
        return google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions({
          input: v, sessionToken: token, includedRegionCodes: ['us']
        });
      }).then(function(res){
        if (!res || mine !== seq) { return; }
        items = (res.suggestions || []).filter(function(s){ return s.placePrediction; }).slice(0, 5);
        active = -1; render();
      }).catch(function(){ /* fail quiet — manual fields still work */ });
    }
    function select(i){
      var sg = items[i]; if (!sg || !sg.placePrediction) { return; }
      var place = sg.placePrediction.toPlace();
      place.fetchFields({ fields: ['addressComponents','formattedAddress'] }).then(function(){
        var g = {}; (place.addressComponents || []).forEach(function(c){ (c.types || []).forEach(function(t){ g[t] = c; }); });
        setVal(form, F.address, ((pick('street_number')(g) || '') + ' ' + (pick('route')(g) || '')).trim());
        setVal(form, F.city,  pick('locality')(g) || pick('postal_town')(g) || pick('sublocality')(g) || pick('administrative_area_level_2')(g));
        setVal(form, F.state, pick('administrative_area_level_1', false)(g));
        setVal(form, F.zip,   pick('postal_code')(g));
        input.value = place.formattedAddress || input.value;
        token = null; // one session ends at a selection
        close();
      }).catch(function(){ close(); });
    }
    input.addEventListener('focus', function(){ loadMaps().catch(function(){}); });
    input.addEventListener('input', function(){
      var v = input.value.trim();
      clearTimeout(tmr);
      if (v.length < 3) { close(); return; }
      tmr = setTimeout(function(){ query(v); }, 220);
    });
    input.addEventListener('keydown', function(e){
      if (list.hidden || !items.length) { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); active = (active + 1) % items.length; render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = (active - 1 + items.length) % items.length; render(); }
      else if (e.key === 'Enter') { if (active > -1) { e.preventDefault(); select(active); } }
      else if (e.key === 'Escape') { close(); }
    });
    input.addEventListener('blur', function(){ setTimeout(close, 150); });
  }
  function init(){
    var nodes = document.querySelectorAll('input[data-aq-address-autocomplete]');
    for (var i = 0; i < nodes.length; i++) { wire(nodes[i]); }
  }
  if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', init); }
})();
</script>
		<?php
	}
}
