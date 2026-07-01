/**
 * All client-side behavior for the theme, ported from the Astro site's
 * inline component scripts: mobile nav drawer, desktop mega menu,
 * FAQ accordion, sticky call bar, and scroll-reveal animation.
 * No dependencies, loaded deferred.
 */
(function () {
	"use strict";

	/* ---------- Mobile drawer + mega menu (aqm-base site-header.php) ---------- */
	function initNav() {
		/* Mobile drawer: #menuToggle opens #mobileMenu, #menuClose / Escape /
		   outside-click close it. */
		var menuToggle = document.getElementById("menuToggle");
		var mobileMenu = document.getElementById("mobileMenu");
		var menuClose = document.getElementById("menuClose");

		function isMenuOpen() {
			return !!mobileMenu && mobileMenu.classList.contains("is-open");
		}

		function openMenu() {
			if (!mobileMenu) return;
			mobileMenu.classList.add("is-open");
			if (menuToggle) menuToggle.setAttribute("aria-expanded", "true");
		}

		function closeMenu() {
			if (!mobileMenu) return;
			mobileMenu.classList.remove("is-open");
			if (menuToggle) menuToggle.setAttribute("aria-expanded", "false");
		}

		if (menuToggle && mobileMenu) {
			menuToggle.setAttribute("aria-controls", "mobileMenu");
			menuToggle.addEventListener("click", function () {
				if (isMenuOpen()) closeMenu();
				else openMenu();
			});
		}

		if (menuClose) {
			menuClose.addEventListener("click", function () {
				closeMenu();
				if (menuToggle) menuToggle.focus();
			});
		}

		document.addEventListener("click", function (e) {
			if (!isMenuOpen() || !(e.target instanceof Element)) return;
			if (mobileMenu.contains(e.target)) return;
			if (menuToggle && menuToggle.contains(e.target)) return;
			closeMenu();
		});

		/* ---------- Desktop mega menu: li.has-mega > a + div.mega ---------- */
		var megaItems = [];
		document.querySelectorAll("li.has-mega").forEach(function (item, i) {
			var trigger = item.querySelector(":scope > a");
			var panel = item.querySelector(":scope > .mega");
			if (!trigger || !panel) return;

			var slug = item.getAttribute("data-nav") || ("item-" + i);
			var panelId = panel.id || ("mega-panel-" + slug);
			panel.id = panelId;

			trigger.setAttribute("aria-haspopup", "true");
			trigger.setAttribute("aria-expanded", "false");
			trigger.setAttribute("aria-controls", panelId);

			megaItems.push({ item: item, trigger: trigger, panel: panel });
		});

		function closeMega(entry) {
			entry.panel.classList.remove("mega-open");
			entry.trigger.setAttribute("aria-expanded", "false");
		}

		function openMega(entry) {
			megaItems.forEach(function (other) {
				if (other !== entry) closeMega(other);
			});
			entry.panel.classList.add("mega-open");
			entry.trigger.setAttribute("aria-expanded", "true");
		}

		function closeAllMega() {
			megaItems.forEach(closeMega);
		}

		megaItems.forEach(function (entry) {
			entry.trigger.addEventListener("click", function (e) {
				e.preventDefault();
				if (entry.panel.classList.contains("mega-open")) closeMega(entry);
				else openMega(entry);
			});

			entry.item.addEventListener("mouseenter", function () { openMega(entry); });
			entry.item.addEventListener("mouseleave", function () { closeMega(entry); });
			entry.trigger.addEventListener("focus", function () { openMega(entry); });

			entry.item.addEventListener("focusout", function (e) {
				if (!entry.item.contains(e.relatedTarget)) closeMega(entry);
			});

			entry.trigger.addEventListener("keydown", function (e) {
				if (e.key === "Escape") {
					closeMega(entry);
					entry.trigger.focus();
				}
			});
		});

		document.addEventListener("click", function (e) {
			if (!(e.target instanceof Element) || e.target.closest("li.has-mega")) return;
			closeAllMega();
		});

		document.addEventListener("keydown", function (e) {
			if (e.key !== "Escape") return;
			closeAllMega();
			if (isMenuOpen()) {
				closeMenu();
				if (menuToggle) menuToggle.focus();
			}
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

	/* ---------- Sticky header state + optional logo swap (AutoForge -> Logo) ---------- */
	function initStickyHeader() {
		var header = document.querySelector("nav.top");
		if (!header) return;
		var img = header.querySelector("img[data-logo-sticky]");
		var ticking = false;
		function update() {
			var scrolled = window.scrollY > 10;
			header.classList.toggle("is-scrolled", scrolled);
			if (img) {
				var want = scrolled ? img.getAttribute("data-logo-sticky") : img.getAttribute("data-logo-default");
				if (want && img.getAttribute("src") !== want) img.setAttribute("src", want);
			}
			ticking = false;
		}
		window.addEventListener("scroll", function () {
			if (!ticking) { requestAnimationFrame(update); ticking = true; }
		}, { passive: true });
		update();
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
		initStickyHeader();
	}

	if (document.readyState !== "loading") init();
	else document.addEventListener("DOMContentLoaded", init);
})();
