/* =============================================================================
 * AQM compiled site script  (content/assets/site.js)
 * -----------------------------------------------------------------------------
 * This is the single per-client script the AQM theme defers in the footer
 * (theme aqm-base/functions.php enqueues assets/js/site.js with
 * {in_footer:true, strategy:'defer'}). It is three concatenated parts:
 *
 *   1. BASE site behavior  — nav / mega-menu, FAQ accordion, sticky call bar,
 *                            scroll-reveal. Runs on every page. (no deps)
 *   2. home.js IIFE        — GSAP scroll choreography. Self-gates on body.home.
 *   3. about.js IIFE       — three.js #abHeroCanvas network hero.
 *                            Self-gates on body.about.
 *
 * Each appended IIFE bails immediately on pages that lack its body class, so
 * the file is safe to load site-wide.
 *
 * -----------------------------------------------------------------------------
 * REQUIRED VENDOR LIBRARIES — enqueue these SEPARATELY (plugin/theme), they are
 * intentionally NOT inlined here. Match the versions the static site shipped.
 * Load order must be: gsap core -> ScrollTrigger -> three -> (this site.js).
 *
 *   - GSAP core ...... gsap 3.12.7      -> window.gsap
 *       https://cdn.jsdelivr.net/npm/gsap@3.12.7/dist/gsap.min.js
 *   - ScrollTrigger .. gsap 3.12.7      -> window.ScrollTrigger
 *       https://cdn.jsdelivr.net/npm/gsap@3.12.7/dist/ScrollTrigger.min.js
 *   - three .......... three 0.150.1 (UMD build) -> window.THREE
 *       https://cdn.jsdelivr.net/npm/three@0.150.1/build/three.min.js
 *   - Font Awesome ... kit f0842d4ca2 (icons are fa-* classes, e.g.
 *       fa-solid fa-location-dot / fa-circle-check / fa-chevron-down)
 *       https://kit.fontawesome.com/f0842d4ca2.js
 *
 * GRACEFUL DEGRADATION: every part below already bails without its dependency
 * (no GSAP, no THREE, no WebGL) and honors prefers-reduced-motion. All "hidden"
 * initial states are set from JS only — never from CSS — so when a library is
 * absent every element renders in its final, visible state.
 * ========================================================================== */


/* =============================================================================
 * PART 1 — BASE SITE BEHAVIOR  (ported verbatim from the AQM static scripts.js)
 * Header chrome only: nav.top scroll-progress + .scrolled state + back-to-top;
 * active-nav highlight (keys off .nav-main [data-nav]); the desktop mega menu
 * (.has-mega -> .mega-open); and the mobile drawer (#menuToggle / #mobileMenu /
 * #menuClose). No dependencies, deferred; each IIFE bails when nav.top is
 * absent. This block is AQM-specific by design — every site ships its OWN base
 * behavior in its own compiled site.js (chrome differs per design). FAQ uses
 * native <details>; this design has NO sticky call bar; scroll-reveal is done
 * in PART 2 via [data-rv] — so none of those are wired here.
 * ========================================================================== */
(function(){
  var nav=document.querySelector('nav.top');
  if(!nav) return;
  var ticking=false, scrolled=false;
  var progress=document.getElementById('scrollProgress');
  var toTop=document.getElementById('toTop');
  function update(){
    var y=window.scrollY;
    if(!scrolled && y>80){scrolled=true;nav.classList.add('scrolled');}
    else if(scrolled && y<20){scrolled=false;nav.classList.remove('scrolled');}
    if(progress){
      var h=document.documentElement.scrollHeight-window.innerHeight;
      progress.style.width=(h>0?(y/h)*100:0)+'%';
    }
    if(toTop) toTop.classList.toggle('visible', y>400);
    ticking=false;
  }
  window.addEventListener('scroll',function(){
    if(!ticking){requestAnimationFrame(update);ticking=true;}
  },{passive:true});
  update();
  if(toTop) toTop.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});
})();

(function(){
  try{
    var path=location.pathname.replace(/\/+$/,'')||'/';
    var seg=path.split('/').filter(Boolean)[0]||'';
    var map={services:'services',solutions:'solutions',locations:'locations',industries:'industries',about:'about',contact:'contact',blog:'blog'};
    var key=map[seg];
    if(!key) return;
    document.querySelectorAll('.nav-main [data-nav="'+key+'"] > a').forEach(function(a){a.classList.add('active');});
  }catch(e){}
})();

(function(){
  /* Mega menu — JS-controlled so mouseleave gap doesn't close the menu prematurely */
  var items=document.querySelectorAll('nav.top .has-mega');
  items.forEach(function(item){
    var timer=null;
    function open(){
      clearTimeout(timer);
      // close all others
      items.forEach(function(other){if(other!==item)other.classList.remove('mega-open');});
      item.classList.add('mega-open');
    }
    function scheduleClose(){
      timer=setTimeout(function(){item.classList.remove('mega-open');},120);
    }
    item.addEventListener('mouseenter',open);
    item.addEventListener('mouseleave',scheduleClose);
    // clicking the parent link on desktop also toggles
    var link=item.querySelector(':scope > a');
    if(link) link.addEventListener('click',function(e){
      if(window.innerWidth>1024){e.preventDefault();item.classList.contains('mega-open')?item.classList.remove('mega-open'):open();}
    });
  });
  // close on outside click
  document.addEventListener('click',function(e){
    if(!e.target.closest('.has-mega'))
      items.forEach(function(item){item.classList.remove('mega-open');});
  });
})();

(function(){
  var btn=document.getElementById('menuToggle');
  var menu=document.getElementById('mobileMenu');
  if(!btn||!menu) return;
  function setIcon(name){try{btn.innerHTML='<i class="fa-solid fa-'+name+'"></i>';}catch(e){}}
  function close(){menu.classList.remove('open');document.documentElement.classList.remove('menu-open');document.body.classList.remove('menu-open');btn.setAttribute('aria-expanded','false');setIcon('bars');}
  function open(){menu.classList.add('open');document.documentElement.classList.add('menu-open');document.body.classList.add('menu-open');btn.setAttribute('aria-expanded','true');setIcon('xmark');}
  btn.addEventListener('click',function(){
    if(menu.classList.contains('open')) close(); else open();
  });
  var closeBtn=document.getElementById('menuClose');
  if(closeBtn) closeBtn.addEventListener('click',close);
  menu.querySelectorAll('a').forEach(function(a){a.addEventListener('click',close);});
  window.addEventListener('resize',function(){if(window.innerWidth>720) close();});
})();


/* =============================================================================
 * PART 2 — HOME PAGE CHOREOGRAPHY  (ported verbatim from home.js)
 * GSAP scroll choreography. Self-gates on body.home; no-ops elsewhere.
 * Hooks: [data-split], [data-rv], [data-count], [data-viz], [data-mock],
 * [data-words], .hm-* classes, #hmGrid, .hm-compare, .cta-band.
 * ========================================================================== */
/* AQM home — GSAP scroll choreography (2026 "Local Growth Engine" redesign)
   Loads after gsap + ScrollTrigger CDN scripts (deferred, in order).
   Degrades gracefully: without GSAP, or with reduced motion, every element
   renders in its final state because all "hidden" initial states are set
   from JS only — never in CSS. */
(function(){
  'use strict';
  if(!document.body.classList.contains('home')) return;

  var hasGsap=typeof window.gsap!=='undefined'&&typeof window.ScrollTrigger!=='undefined';
  var reduce=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer=window.matchMedia('(hover: hover) and (pointer: fine)').matches;

  /* ---------- Industries marquee (plain rAF — runs even without GSAP) ----------
     Each track drifts at its own speed; scrolling adds a velocity boost.
     Hover or keyboard focus pauses it so links stay usable. */
  (function(){
    var tracks=[].slice.call(document.querySelectorAll('.hm-row-track'));
    if(!tracks.length||reduce) return;
    var items=tracks.map(function(track){
      var row=track.parentElement;
      /* clone the chip set until the track is comfortably wider than 2 rows */
      var setW=track.scrollWidth;
      if(!setW) return null;
      var copies=Math.min(4,Math.max(1,Math.ceil(row.offsetWidth*1.25/setW)));
      var originals=[].slice.call(track.children);
      for(var c=0;c<copies;c++){
        originals.forEach(function(ch){
          var clone=ch.cloneNode(true);
          clone.setAttribute('aria-hidden','true');
          clone.setAttribute('tabindex','-1');
          track.appendChild(clone);
        });
      }
      var paused=false;
      row.addEventListener('mouseenter',function(){paused=true;});
      row.addEventListener('mouseleave',function(){paused=false;});
      row.addEventListener('focusin',function(){paused=true;});
      row.addEventListener('focusout',function(){paused=false;});
      return {el:track,x:0,speed:parseFloat(track.getAttribute('data-speed'))||0.5,
        setW:setW,copies:copies,isPaused:function(){return paused;}};
    }).filter(Boolean);
    if(!items.length) return;
    window.addEventListener('resize',function(){
      items.forEach(function(it){it.setW=it.el.scrollWidth/(it.copies+1);});
    });
    /* Only burn frames while the rows are actually on screen */
    var running=false,rafId=null;
    var vel=0,lastY=window.scrollY,lastT=null;
    function tick(t){
      if(!running){rafId=null;return;}
      if(lastT===null)lastT=t;
      var dt=Math.min((t-lastT)/16.7,3);lastT=t;
      var y=window.scrollY;
      vel+=((Math.abs(y-lastY)*0.05)-vel)*0.12;lastY=y;
      items.forEach(function(it){
        var base=it.isPaused()?0:it.speed;
        it.x-=base*(1+Math.min(vel,5))*dt;
        if(it.x<=-it.setW)it.x+=it.setW;
        if(it.x>0)it.x-=it.setW;
        it.el.style.transform='translate3d('+it.x.toFixed(2)+'px,0,0)';
      });
      rafId=requestAnimationFrame(tick);
    }
    var rowsWrap=document.querySelector('.hm-rows');
    if('IntersectionObserver' in window&&rowsWrap){
      new IntersectionObserver(function(entries){
        var vis=entries[0].isIntersecting;
        if(vis&&!running){running=true;lastT=null;lastY=window.scrollY;rafId=requestAnimationFrame(tick);}
        else if(!vis&&running){running=false;if(rafId)cancelAnimationFrame(rafId);}
      },{rootMargin:'80px 0px'}).observe(rowsWrap);
    }else{
      running=true;requestAnimationFrame(tick);
    }
  })();

  if(!hasGsap||reduce){
    document.documentElement.classList.add(hasGsap?'hm-reduced':'hm-no-gsap');
    return; /* everything is already in its final, visible state */
  }

  gsap.registerPlugin(ScrollTrigger);
  gsap.defaults({ease:'power3.out',duration:0.9});

  /* ---------- Masked word reveals for section titles ---------- */
  function splitWords(el){
    [].slice.call(el.childNodes).forEach(function(node){
      if(node.nodeType===3){
        var frag=document.createDocumentFragment();
        node.textContent.split(/(\s+)/).forEach(function(part){
          if(!part)return;
          if(/^\s+$/.test(part)){frag.appendChild(document.createTextNode(' '));return;}
          var m=document.createElement('span');m.className='hm-wm';
          var w=document.createElement('span');w.className='hm-w';w.textContent=part;
          m.appendChild(w);frag.appendChild(m);
        });
        el.replaceChild(frag,node);
      }else if(node.nodeType===1){splitWords(node);}
    });
  }
  gsap.utils.toArray('[data-split]').forEach(function(title){
    splitWords(title);
    var words=title.querySelectorAll('.hm-w');
    gsap.set(words,{yPercent:115});
    ScrollTrigger.create({trigger:title,start:'top 86%',once:true,onEnter:function(){
      gsap.to(words,{yPercent:0,duration:0.9,stagger:0.04,ease:'power4.out'});
    }});
  });

  /* ---------- Generic reveals ---------- */
  var rvEls=gsap.utils.toArray('[data-rv]');
  gsap.set(rvEls,{autoAlpha:0,y:28});
  ScrollTrigger.batch(rvEls,{start:'top 88%',once:true,onEnter:function(batch){
    gsap.to(batch,{autoAlpha:1,y:0,duration:0.8,stagger:0.08});
  }});

  /* ---------- Counters (supports counting down via data-count-from) ---------- */
  gsap.utils.toArray('[data-count]').forEach(function(el){
    var to=parseFloat(el.getAttribute('data-count'));
    var from=el.hasAttribute('data-count-from')?parseFloat(el.getAttribute('data-count-from')):0;
    if(isNaN(to))return;
    var obj={v:from};
    el.textContent=Math.round(from);
    ScrollTrigger.create({trigger:el,start:'top 88%',once:true,onEnter:function(){
      gsap.to(obj,{v:to,duration:1.4,ease:'power2.out',onUpdate:function(){
        el.textContent=Math.round(obj.v);
      }});
    }});
  });

  /* ---------- 02 · Buried-listings panel builds itself ---------- */
  (function(){
    var v=document.querySelector('[data-viz="buried"]');
    if(!v)return;
    var rows=v.querySelectorAll('.hm-brow:not(.hm-byou)');
    var you=v.querySelector('.hm-byou');
    var badge=v.querySelector('.hm-bbadge');
    gsap.set(rows,{autoAlpha:0,y:-18});
    gsap.set(you,{autoAlpha:0,y:34});
    gsap.set(badge,{autoAlpha:0,scale:0.8});
    ScrollTrigger.create({trigger:v,start:'top 80%',once:true,onEnter:function(){
      gsap.timeline()
        .to(rows,{autoAlpha:1,y:0,duration:0.5,stagger:0.12,ease:'power3.out'})
        .to(badge,{autoAlpha:1,scale:1,duration:0.45,ease:'back.out(2)'},'+=0.05')
        .to(you,{autoAlpha:0.55,y:0,duration:0.6,ease:'power3.out'},'-=0.15');
    }});
  })();

  /* ---------- 05 · Review rating bars sweep out ---------- */
  (function(){
    var v=document.querySelector('[data-viz="reviews"]');
    if(!v)return;
    var bars=v.querySelectorAll('.hm-rv-bar > i > b');
    gsap.set(bars,{scaleX:0});
    ScrollTrigger.create({trigger:v,start:'top 84%',once:true,onEnter:function(){
      gsap.to(bars,{scaleX:1,duration:0.9,stagger:0.08,ease:'power3.out'});
    }});
  })();

  /* ---------- 06 · Dashboard: line draw + donut sweep ---------- */
  (function(){
    var v=document.querySelector('[data-viz="dash"]');
    if(!v)return;
    var line=v.querySelector('.hm-dash-line');
    var area=v.querySelector('.hm-dash-area');
    var ring=v.querySelector('.hm-dash-ring-val');
    var llen=line?line.getTotalLength():0;
    var rlen=ring?ring.getTotalLength():0;
    if(line)gsap.set(line,{strokeDasharray:llen,strokeDashoffset:llen});
    if(area)gsap.set(area,{autoAlpha:0});
    if(ring)gsap.set(ring,{strokeDasharray:rlen,strokeDashoffset:rlen});
    ScrollTrigger.create({trigger:v,start:'top 82%',once:true,onEnter:function(){
      var tl=gsap.timeline();
      if(line)tl.to(line,{strokeDashoffset:0,duration:1.3,ease:'power2.inOut'},0);
      if(area)tl.to(area,{autoAlpha:1,duration:0.8},0.3);
      if(ring)tl.to(ring,{strokeDashoffset:rlen*(1-0.38),duration:1.2,ease:'power2.out'},0.2);
    }});
  })();

  /* ---------- Ghost numerals drift ---------- */
  gsap.utils.toArray('.hm-ghost').forEach(function(g){
    gsap.fromTo(g,{yPercent:-16},{yPercent:16,ease:'none',
      scrollTrigger:{trigger:g.parentElement,start:'top bottom',end:'bottom top',scrub:true}});
  });

  /* ---------- 02 · Problem rows light up as they pass ---------- */
  (function(){
    var list=document.querySelector('.hm-painlist');
    if(!list)return;
    list.classList.add('is-anim');
    gsap.utils.toArray('.hm-pain').forEach(function(row){
      ScrollTrigger.create({trigger:row,start:'top 72%',end:'bottom 28%',
        toggleClass:{targets:row,className:'on'}});
    });
  })();

  /* ---------- 03 · Stacking cards settle back as the next arrives ---------- */
  var mm=gsap.matchMedia();
  mm.add('(min-width: 721px)',function(){
    var steps=gsap.utils.toArray('.hm-step');
    steps.forEach(function(card,i){
      if(i===steps.length-1)return;
      gsap.to(card,{scale:0.955,ease:'none',
        scrollTrigger:{trigger:steps[i+1],start:'top bottom',end:'top top+=160',scrub:true}});
    });
  });

  /* ---------- 04 · Map-pack climb ---------- */
  (function(){
    var mock=document.querySelector('[data-mock="serp"]');
    if(!mock)return;
    var rows=gsap.utils.toArray(mock.querySelectorAll('.hm-serp-item'));
    if(rows.length<4)return;
    var you=rows[0],a=rows[1],b=rows[2],last=rows[3];
    var badge=mock.querySelector('.hm-serp-badge');
    var youTag=mock.querySelector('.hm-you-tag');
    var pin=mock.querySelector('.hm-map-pin');
    /* DOM order is the final state (You at #1). Start scrambled:
       competitors fill the pack, You sits buried below the divider.
       Measured before any transforms are applied, so plain rect math works. */
    var h=a.getBoundingClientRect().top-you.getBoundingClientRect().top;
    var hd=last.getBoundingClientRect().top-b.getBoundingClientRect().top;
    gsap.set(you,{y:2*h+hd,zIndex:3});
    gsap.set([a,b],{y:-h});
    gsap.set(last,{y:-hd});
    gsap.set(badge,{scale:0});
    gsap.set(youTag,{scale:0});
    gsap.set(pin,{autoAlpha:0,y:-26});
    ScrollTrigger.create({trigger:mock,start:'top 72%',once:true,onEnter:function(){
      var tl=gsap.timeline({delay:0.2});
      tl.to(youTag,{scale:1,duration:0.4,ease:'back.out(2.5)'})
        .to([a,b,last],{y:0,duration:1,ease:'power3.inOut',stagger:0.05},0.7)
        .to(you,{y:0,duration:1,ease:'power3.inOut'},0.7)
        .to(badge,{scale:1,duration:0.5,ease:'back.out(2.5)'},1.8)
        .to(pin,{autoAlpha:1,y:0,duration:0.55,ease:'bounce.out'},1.95);
    }});
  })();

  /* ---------- 04 · AI receptionist conversation ---------- */
  (function(){
    var mock=document.querySelector('[data-mock="chat"]');
    if(!mock)return;
    var seq=gsap.utils.toArray(mock.querySelectorAll('.hm-bub,.hm-chat-card'));
    var typing=mock.querySelector('.hm-typing');
    seq.forEach(function(el){
      gsap.set(el,{autoAlpha:0,y:16,scale:0.95,
        transformOrigin:el.classList.contains('hm-bub-u')?'right bottom':'left bottom'});
    });
    ScrollTrigger.create({trigger:mock,start:'top 72%',once:true,onEnter:function(){
      var tl=gsap.timeline({defaults:{duration:0.45,ease:'back.out(1.6)'}});
      tl.to(seq[0],{autoAlpha:1,y:0,scale:1},0.3)
        .add(function(){if(typing)typing.classList.add('show');},'+=0.4')
        .add(function(){if(typing)typing.classList.remove('show');},'+=1.0')
        .to(seq[1],{autoAlpha:1,y:0,scale:1})
        .to(seq[2],{autoAlpha:1,y:0,scale:1},'+=0.7')
        .to(seq[3],{autoAlpha:1,y:0,scale:1},'+=0.6')
        .to(seq[4],{autoAlpha:1,y:0,scale:1,ease:'back.out(2)'},'+=0.5');
    }});
  })();

  /* ---------- 05 · Quote brightens word by word on scrub ---------- */
  (function(){
    var quote=document.querySelector('[data-words]');
    if(!quote)return;
    splitWords(quote);
    gsap.fromTo(quote.querySelectorAll('.hm-w'),{opacity:0.15},{opacity:1,stagger:0.06,ease:'none',
      scrollTrigger:{trigger:quote,start:'top 80%',end:'bottom 60%',scrub:true}});
  })();

  /* ---------- 06 · Platform spotlight follows the cursor ---------- */
  (function(){
    var grid=document.getElementById('hmGrid');
    if(!grid||!finePointer)return;
    var cells=[].slice.call(grid.children);
    grid.addEventListener('mousemove',function(e){
      for(var i=0;i<cells.length;i++){
        var r=cells[i].getBoundingClientRect();
        cells[i].style.setProperty('--mx',(e.clientX-r.left)+'px');
        cells[i].style.setProperty('--my',(e.clientY-r.top)+'px');
      }
    });
  })();

  /* ---------- 08 · Compare table rows cascade in ---------- */
  (function(){
    var cmp=document.querySelector('.hm-compare');
    if(!cmp)return;
    var head=cmp.querySelector('thead');
    var rows=gsap.utils.toArray(cmp.querySelectorAll('tbody tr'));
    gsap.set(head,{autoAlpha:0});
    gsap.set(rows,{autoAlpha:0});
    ScrollTrigger.create({trigger:cmp,start:'top 82%',once:true,onEnter:function(){
      gsap.to(head,{autoAlpha:1,duration:0.5});
      gsap.to(rows,{autoAlpha:1,duration:0.55,stagger:0.09,delay:0.15});
    }});
  })();

  /* ---------- CTA band: magnetic buttons ---------- */
  (function(){
    if(!finePointer)return;
    gsap.utils.toArray('.cta-band .actions .btn').forEach(function(btn){
      var qx=gsap.quickTo(btn,'x',{duration:0.35,ease:'power3'});
      var qy=gsap.quickTo(btn,'y',{duration:0.35,ease:'power3'});
      btn.addEventListener('mousemove',function(e){
        var r=btn.getBoundingClientRect();
        qx((e.clientX-(r.left+r.width/2))*0.18);
        qy((e.clientY-(r.top+r.height/2))*0.25);
      });
      btn.addEventListener('mouseleave',function(){qx(0);qy(0);});
    });
  })();

  /* re-measure once everything (fonts, images) has settled */
  window.addEventListener('load',function(){ScrollTrigger.refresh();});
  if(document.fonts&&document.fonts.ready){document.fonts.ready.then(function(){ScrollTrigger.refresh();});}
})();


/* =============================================================================
 * PART 3 — ABOUT PAGE WEBGL HERO  (ported verbatim from about.js)
 * three.js #abHeroCanvas network hero + GSAP hero entrance.
 * Self-gates on body.about; no-ops elsewhere. Needs window.THREE (three
 * 0.150.1 UMD); falls back to the CSS hero without THREE / WebGL / motion.
 * ========================================================================== */
/* AQM about — three.js "local growth network" hero + GSAP hero entrance.
   Loads after gsap, ScrollTrigger, home.js and the three.js global build.
   The page carries both `home` and `about` body classes, so all the
   editorial .hm-* section choreography (split titles, reveals, counters,
   sticky steps, marquee, scrub quote) is handled by home.js. This file
   owns ONLY the WebGL hero and its text intro.
   Degrades gracefully: no THREE / no WebGL / reduced-motion → the CSS hero
   (dark gradient + grid + red glow) stands on its own; nothing is hidden
   from CSS, so text is always visible. */
(function () {
  'use strict';
  if (!document.body.classList.contains('about')) return;

  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var hasGsap = typeof window.gsap !== 'undefined';

  /* ---------- Hero text entrance (JS-only hidden state → safe fallback) ---------- */
  (function () {
    if (!hasGsap || reduce) return;
    var inner = document.querySelector('.ab-hero-inner');
    if (!inner) return;
    var bits = inner.querySelectorAll('.badge, h1, p.lede, .cta, .ab-hero-note');
    if (!bits.length) return;
    gsap.set(bits, { autoAlpha: 0, y: 26 });
    gsap.to(bits, { autoAlpha: 1, y: 0, duration: 0.9, ease: 'power3.out', stagger: 0.1, delay: 0.15 });
    var cue = document.querySelector('.ab-hero-scroll');
    if (cue) { gsap.set(cue, { autoAlpha: 0 }); gsap.to(cue, { autoAlpha: 1, duration: 0.8, delay: 1.15 }); }
  })();

  /* ---------- three.js network ---------- */
  (function () {
    var canvas = document.getElementById('abHeroCanvas');
    if (!canvas || typeof window.THREE === 'undefined') return;
    var THREE = window.THREE;
    var hero = document.querySelector('.ab-hero');
    if (!hero) return;

    var renderer;
    try {
      renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: false, powerPreference: 'high-performance' });
    } catch (e) { return; } // no WebGL → CSS hero stays
    if (!renderer) return;

    var BG = 0x06080b;
    var DPR = Math.min(window.devicePixelRatio || 1, 2);
    renderer.setPixelRatio(DPR);
    renderer.setClearColor(BG, 1);

    var scene = new THREE.Scene();
    scene.fog = new THREE.FogExp2(BG, 0.018);

    var camera = new THREE.PerspectiveCamera(58, 1, 0.1, 400);
    camera.position.set(0, 0, 56);

    var group = new THREE.Group();
    scene.add(group);

    /* --- soft round dot sprite --- */
    function dotTexture() {
      var c = document.createElement('canvas');
      c.width = c.height = 64;
      var ctx = c.getContext('2d');
      var g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
      g.addColorStop(0, 'rgba(255,255,255,1)');
      g.addColorStop(0.35, 'rgba(255,255,255,0.85)');
      g.addColorStop(1, 'rgba(255,255,255,0)');
      ctx.fillStyle = g;
      ctx.beginPath();
      ctx.arc(32, 32, 32, 0, Math.PI * 2);
      ctx.fill();
      var tex = new THREE.CanvasTexture(c);
      return tex;
    }

    /* --- brand palette (steel / red / orange) --- */
    var STEEL = new THREE.Color(0x9fb0c4);
    var RED = new THREE.Color(0xef3050);
    var ORANGE = new THREE.Color(0xf6803f);

    var COUNT = 130;
    var SPREAD_X = 46, SPREAD_Y = 30, SPREAD_Z = 22;
    var LINK_DIST = 11;
    var MAX_LINKS = 280;

    var positions = new Float32Array(COUNT * 3);
    var colors = new Float32Array(COUNT * 3);
    var sizes = new Float32Array(COUNT);
    var nodes = []; // keep xyz for linking + drift phase

    for (var i = 0; i < COUNT; i++) {
      var x = (Math.random() * 2 - 1) * SPREAD_X;
      var y = (Math.random() * 2 - 1) * SPREAD_Y;
      var z = (Math.random() * 2 - 1) * SPREAD_Z;
      positions[i * 3] = x; positions[i * 3 + 1] = y; positions[i * 3 + 2] = z;

      var r = Math.random();
      var col;
      if (r < 0.12) col = RED.clone().multiplyScalar(0.9 + Math.random() * 0.3);
      else if (r < 0.22) col = ORANGE.clone().multiplyScalar(0.9 + Math.random() * 0.25);
      else col = STEEL.clone().multiplyScalar(0.55 + Math.random() * 0.5);
      colors[i * 3] = col.r; colors[i * 3 + 1] = col.g; colors[i * 3 + 2] = col.b;

      sizes[i] = (r < 0.22 ? 2.6 : 1.6) + Math.random() * 1.2;
      nodes.push({ x: x, y: y, z: z, ph: Math.random() * Math.PI * 2 });
    }

    var pGeo = new THREE.BufferGeometry();
    pGeo.setAttribute('position', new THREE.BufferAttribute(positions, 3));
    pGeo.setAttribute('color', new THREE.BufferAttribute(colors, 3));
    var pMat = new THREE.PointsMaterial({
      size: 2.4,
      map: dotTexture(),
      vertexColors: true,
      transparent: true,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
      sizeAttenuation: true
    });
    var points = new THREE.Points(pGeo, pMat);
    group.add(points);

    /* --- connecting lines (built once from base positions) --- */
    var linePos = [];
    var lineCol = [];
    var links = 0;
    for (var a = 0; a < COUNT && links < MAX_LINKS; a++) {
      for (var b = a + 1; b < COUNT && links < MAX_LINKS; b++) {
        var dx = nodes[a].x - nodes[b].x;
        var dy = nodes[a].y - nodes[b].y;
        var dz = nodes[a].z - nodes[b].z;
        var d = Math.sqrt(dx * dx + dy * dy + dz * dz);
        if (d < LINK_DIST) {
          var fade = 1 - d / LINK_DIST; // closer = brighter
          linePos.push(nodes[a].x, nodes[a].y, nodes[a].z, nodes[b].x, nodes[b].y, nodes[b].z);
          // faint red→steel mix on the link
          var lc = STEEL.clone().lerp(RED, 0.45).multiplyScalar(0.18 + fade * 0.5);
          lineCol.push(lc.r, lc.g, lc.b, lc.r, lc.g, lc.b);
          links++;
        }
      }
    }
    var lGeo = new THREE.BufferGeometry();
    lGeo.setAttribute('position', new THREE.BufferAttribute(new Float32Array(linePos), 3));
    lGeo.setAttribute('color', new THREE.BufferAttribute(new Float32Array(lineCol), 3));
    var lMat = new THREE.LineBasicMaterial({
      vertexColors: true,
      transparent: true,
      opacity: 0.55,
      depthWrite: false,
      blending: THREE.AdditiveBlending
    });
    var lines = new THREE.LineSegments(lGeo, lMat);
    group.add(lines);

    /* --- sizing --- */
    function resize() {
      var w = hero.clientWidth || window.innerWidth;
      var h = hero.clientHeight || window.innerHeight;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
    }
    resize();
    window.addEventListener('resize', resize);

    /* --- pointer parallax --- */
    var mx = 0, my = 0, tmx = 0, tmy = 0;
    if (finePointer) {
      window.addEventListener('pointermove', function (e) {
        tmx = (e.clientX / window.innerWidth) * 2 - 1;
        tmy = (e.clientY / window.innerHeight) * 2 - 1;
      }, { passive: true });
    }

    /* --- scroll fade (canvas dims as the hero leaves) --- */
    var scrollFade = 1;
    function onScroll() {
      var rect = hero.getBoundingClientRect();
      var p = 1 + rect.top / (rect.height || 1); // 1 at top, →0 as hero scrolls out
      scrollFade = Math.max(0, Math.min(1, p));
      canvas.style.opacity = (0.25 + 0.75 * scrollFade).toFixed(3);
    }
    window.addEventListener('scroll', onScroll, { passive: true });

    var clock = new THREE.Clock();

    function frame() {
      var t = clock.getElapsedTime();
      // ease pointer
      mx += (tmx - mx) * 0.04;
      my += (tmy - my) * 0.04;
      group.rotation.y = t * 0.045 + mx * 0.35;
      group.rotation.x = my * 0.22 + Math.sin(t * 0.18) * 0.04;
      camera.position.z = 56 + Math.sin(t * 0.25) * 3;
      renderer.render(scene, camera);
    }

    /* reduced motion → render one static, composed frame and stop */
    if (reduce) {
      group.rotation.set(-0.12, 0.5, 0);
      renderer.render(scene, camera);
      return;
    }

    /* run only while the hero is on screen */
    var running = false, rafId = null;
    function loop() { if (!running) { rafId = null; return; } frame(); rafId = requestAnimationFrame(loop); }
    function start() { if (!running) { running = true; clock.getDelta(); rafId = requestAnimationFrame(loop); } }
    function stop() { running = false; if (rafId) { cancelAnimationFrame(rafId); rafId = null; } }

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting) start(); else stop();
      }, { rootMargin: '120px 0px' }).observe(hero);
    } else {
      start();
    }

    // a couple of late refreshes so the canvas matches final layout/fonts
    window.addEventListener('load', resize);
  })();
})();
