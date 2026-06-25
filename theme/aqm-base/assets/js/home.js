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
