/**
 * All client-side behavior for the theme, ported from the Astro site's
 * inline component scripts: mobile nav drawer, desktop mega menu,
 * FAQ accordion, sticky call bar, and scroll-reveal animation.
 * No dependencies, loaded deferred.
 */
(function () {
	"use strict";

	/* ---------- Mobile drawer + mega menu (Header.astro) ---------- */
	function initNav() {
		var toggle = document.querySelector("[data-nav-toggle]");
		var mobile = document.querySelector("[data-nav-mobile]");
		if (toggle && mobile) {
			toggle.addEventListener("click", function () {
				var open = mobile.classList.toggle("hidden");
				toggle.setAttribute("aria-expanded", open ? "false" : "true");
			});

			mobile.querySelectorAll("details").forEach(function (det) {
				det.addEventListener("toggle", function () {
					if (det.open) {
						mobile.querySelectorAll("details").forEach(function (other) {
							if (other !== det) other.open = false;
						});
					}
				});
			});
		}

		var triggers = document.querySelectorAll("[data-mega-trigger]");
		var panels = document.querySelectorAll("[data-mega-panel]");

		function closeAll() {
			triggers.forEach(function (t) { t.setAttribute("aria-expanded", "false"); });
			panels.forEach(function (p) { p.classList.remove("mega-open"); });
		}

		function open(key) {
			closeAll();
			var trigger = document.querySelector('[data-mega-trigger="' + key + '"]');
			var panel = document.querySelector('[data-mega-panel="' + key + '"]');
			if (trigger) trigger.setAttribute("aria-expanded", "true");
			if (panel) panel.classList.add("mega-open");
		}

		triggers.forEach(function (trigger) {
			var key = trigger.getAttribute("data-mega-trigger");
			var item = trigger.closest("[data-mega-item]");

			trigger.addEventListener("click", function (e) {
				e.preventDefault();
				var isOpen = trigger.getAttribute("aria-expanded") === "true";
				if (isOpen) closeAll();
				else open(key);
			});

			if (item) {
				item.addEventListener("mouseenter", function () { open(key); });
				item.addEventListener("focusin", function () { open(key); });
			}
		});

		var header = document.querySelector("header");
		if (header) header.addEventListener("mouseleave", closeAll);

		document.addEventListener("keydown", function (e) {
			if (e.key === "Escape") {
				closeAll();
				if (document.activeElement && document.activeElement.blur) {
					document.activeElement.blur();
				}
			}
		});

		document.addEventListener("click", function (e) {
			if (!(e.target instanceof Element) || !e.target.closest("header")) closeAll();
		});
	}

	/* ---------- FAQ accordion (page-level script in Astro) ---------- */
	function initFaq() {
		document.querySelectorAll(".faq-item").forEach(function (item) {
			var btn = item.querySelector(".faq-toggle");
			if (!btn) return;
			btn.addEventListener("click", function () {
				var isOpen = item.getAttribute("data-open") === "true";
				item.setAttribute("data-open", String(!isOpen));
				btn.setAttribute("aria-expanded", String(!isOpen));
			});
		});
	}

	/* ---------- Sticky call bar (StickyCallBar.astro) ---------- */
	function initCallBar() {
		var bar = document.getElementById("sticky-call-bar");
		var dismissBtn = document.getElementById("sticky-call-bar-dismiss");
		if (!bar || !dismissBtn) return;

		if (sessionStorage.getItem("callbar-dismissed") === "1") return;

		var shown = false;
		var SCROLL_THRESHOLD = 400;

		function show() {
			if (!shown) {
				shown = true;
				bar.classList.add("is-visible");
			}
		}

		function onScroll() {
			if (window.scrollY > SCROLL_THRESHOLD) {
				show();
				window.removeEventListener("scroll", onScroll, { passive: true });
			}
		}

		window.addEventListener("scroll", onScroll, { passive: true });

		var footer = document.querySelector("footer");
		if (footer) {
			var observer = new IntersectionObserver(
				function (entries) {
					var entry = entries[0];
					if (entry.isIntersecting) {
						bar.classList.remove("is-visible");
					} else if (shown) {
						bar.classList.add("is-visible");
					}
				},
				{ threshold: 0 }
			);
			observer.observe(footer);
		}

		dismissBtn.addEventListener("click", function () {
			bar.classList.add("is-dismissed");
			sessionStorage.setItem("callbar-dismissed", "1");
		});
	}

	/* ---------- Scroll reveal (BaseLayout.astro) ---------- */
	function initReveal() {
		var sections = document.querySelectorAll("main > section, main > div > section");
		var targets = [];
		sections.forEach(function (s) {
			var container = s.querySelector(".container-edge, .container, [class*='container']") || s;
			var children = Array.prototype.slice.call(container.children);
			var list = children.length ? children : [s];
			list.forEach(function (c, i) {
				c.classList.add("reveal");
				c.style.transitionDelay = Math.min(i * 60, 240) + "ms";
				targets.push(c);
			});
		});
		var io = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (e) {
					if (e.isIntersecting) {
						e.target.classList.add("reveal-in");
						io.unobserve(e.target);
					}
				});
			},
			{ threshold: 0.12, rootMargin: "0px 0px -40px 0px" }
		);
		targets.forEach(function (t) { io.observe(t); });
	}

	/* ---------- Back-to-top button (scripts.js on the static site) ---------- */
	function initToTop() {
		var toTop = document.getElementById("toTop");
		if (!toTop) return;
		var ticking = false;
		function update() {
			toTop.classList.toggle("visible", window.scrollY > 400);
			ticking = false;
		}
		window.addEventListener("scroll", function () {
			if (!ticking) { requestAnimationFrame(update); ticking = true; }
		}, { passive: true });
		update();
		toTop.addEventListener("click", function () {
			window.scrollTo({ top: 0, behavior: "smooth" });
		});
	}

	function init() {
		initNav();
		initFaq();
		initCallBar();
		initReveal();
		initToTop();
	}

	if (document.readyState !== "loading") init();
	else document.addEventListener("DOMContentLoaded", init);
})();
