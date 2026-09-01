<?php
/**
 * AutoForge → Help screen. One plain-English page explaining every part of
 * the plugin for non-technical site owners — what each AutoForge screen does
 * and how to use it, written for someone who has never touched WordPress
 * settings before. Lives in the shared engine, so every site running
 * AutoForge gets the same up-to-date guide automatically; it never needs a
 * per-client copy (the exact "everyone quietly drifts out of date" trap this
 * plugin's Forms/lead-capture system just got un-forked from — see
 * class-lead-capture.php's history for why that matters).
 *
 * Static content only (no settings to save), so this file is just render().
 */

if (!defined('ABSPATH')) {
	exit;
}

class AQ_Help {

	const CAP  = 'manage_options';
	const SLUG = 'aq-help';

	public static function register(): void {
		add_action('admin_menu', [__CLASS__, 'menu'], 30);
	}

	public static function menu(): void {
		add_submenu_page('aq-dashboard', 'Help', 'Help', self::CAP, self::SLUG, [__CLASS__, 'render']);
	}

	/** One collapsible topic: a plain-English name, a one-line summary always
	 *  visible, and the full explanation revealed on click. No JS — native
	 *  <details>, so it works even if a page's own scripts fail to load. */
	private static function topic(string $title, string $summary, string $body_html, bool $open = false): void {
		printf(
			'<details class="aq-help__topic"%s><summary><strong>%s</strong><span class="aq-help__sum">%s</span></summary><div class="aq-help__body">%s</div></details>',
			$open ? ' open' : '',
			esc_html($title),
			esc_html($summary),
			$body_html // trusted, code-authored HTML — not user input
		);
	}

	public static function render(): void {
		if (!current_user_can(self::CAP)) {
			return;
		}
		AQ_Admin_Hub::open('Help & Documentation', 'Plain-English guide to every screen in AutoForge — no tech background needed.', 'aq-help');
		?>
		<style>
			.aq-help__intro { color:#3a4048; font-size:14px; line-height:1.6; margin:0 0 18px; }
			.aq-help__topic { background:#fff; border:1px solid #e6e8eb; border-radius:12px; margin:0 0 10px; overflow:hidden; }
			.aq-help__topic summary { list-style:none; cursor:pointer; padding:16px 20px; display:flex; align-items:baseline; gap:12px; flex-wrap:wrap; }
			.aq-help__topic summary::-webkit-details-marker { display:none; }
			.aq-help__topic summary::before { content:'▸'; color:#5b6471; margin-right:4px; font-size:12px; }
			.aq-help__topic[open] summary::before { content:'▾'; }
			.aq-help__topic summary strong { font-family:Poppins, Inter, system-ui, sans-serif; font-size:15px; color:#0d1014; }
			.aq-help__sum { color:#5b6471; font-size:13px; }
			.aq-help__body { padding:0 20px 20px; color:#2c3238; font-size:13px; line-height:1.7; border-top:1px solid #eef1f5; }
			.aq-help__body p { margin:12px 0 0; }
			.aq-help__body ul, .aq-help__body ol { margin:10px 0 0; padding-left:22px; }
			.aq-help__body li { margin:4px 0; }
			.aq-help__body code { background:#f2f4f6; padding:1px 6px; border-radius:5px; font-size:12px; }
			.aq-help__tip { margin-top:12px; background:#f4f8f6; border:1px solid #cfe6da; border-radius:8px; padding:10px 14px; }
			.aq-help__tip strong { color:#1a6f3f; }
			.aq-help__group { font-family:Poppins, Inter, system-ui, sans-serif; font-size:12px; text-transform:uppercase; letter-spacing:.06em; color:#5b6471; font-weight:700; margin:26px 0 10px; }
			.aq-help__group:first-of-type { margin-top:6px; }
		</style>

		<div class="aq-panel">
			<p class="aq-help__intro">
				AutoForge is the one plugin that runs this whole website — the pages you see, how the site shows up in Google, where your
				contact-form leads go, and how fast the site loads. Everything below is organized by the menu item on the left (under
				<strong>AutoForge</strong>). Click any question to open it; click again to close it. If a screen mentioned here isn't
				showing in your menu, your site simply isn't using that feature yet — that's normal, nothing is broken.
			</p>

			<div class="aq-help__group">Content</div>

			<?php self::topic('Pages', 'Edit the words, images, and sections on every page of your site.', '
				<p>This is where you edit what visitors actually see. Click <strong>Open editor</strong> next to any page in the list to change its
				text, swap photos, reorder sections, or add a new section (like a testimonials block or a call-to-action).</p>
				<ul>
					<li><strong>Structured</strong> pages are built from editable blocks — click any piece of text or image directly in the editor to change it.</li>
					<li><strong>Raw HTML</strong> pages are older-style pages that aren\'t broken into blocks yet; they still work, they\'re just less flexible to edit here.</li>
					<li>Changes save automatically as you edit — there\'s no separate "publish" step for most edits.</li>
				</ul>
				<div class="aq-help__tip"><strong>Tip:</strong> use the search box at the top of the Pages list to jump straight to a page by name.</div>
			', true); ?>

			<?php self::topic('Styles', 'Your site\'s colors, fonts, and overall look, in one place.', '
				<p>Controls the visual theme of the whole site — brand colors, fonts, and spacing defaults. Changing something here updates it
				everywhere at once, instead of you having to fix every page individually.</p>
				<p>Use this if you\'ve rebranded, want a different accent color, or want to try a different font pairing.</p>
			'); ?>

			<?php self::topic('Media', 'Alt text for your images — written automatically, never over what a person typed.', '
				<p><strong>Alt text</strong> is the short description attached to an image that screen readers read aloud and Google uses to
				understand the picture. Every image should have one; most uploads don\'t.</p>
				<ul>
					<li>When <strong>Write alt text automatically</strong> is on, any new image that arrives without alt text is described in the
					background within about a minute — whether it was uploaded here, picked in the page editor, or imported with the site.</li>
					<li><strong>Generate missing alt text</strong> works through images already in your library that have none. It runs in small
					batches; you can leave the page and come back.</li>
					<li>AutoForge only ever fills an <em>empty</em> alt. If you (or anyone) typed a description, it is never changed.</li>
					<li>Purely decorative images (textures, dividers) are marked decorative and deliberately left with an empty alt — that is
					the correct, accessible choice.</li>
					<li>To fix a description, open the image (the <strong>Edit</strong> link in "Recently written") and change its alt text there.</li>
				</ul>
				<div class="aq-help__tip"><strong>Needs:</strong> a Claude key under Integrations. The daily allowance caps how many images are described per day so cost can never run away.</div>
			'); ?>

			<div class="aq-help__group">Getting found on Google</div>

			<?php self::topic('SEO', 'The titles and descriptions that show up in Google search results.', '
				<p>"SEO" just means "how easy it is for people to find your site on Google." This screen lets you set, per page:</p>
				<ul>
					<li>The <strong>title</strong> that shows as the blue link in search results.</li>
					<li>The <strong>description</strong> — the couple of sentences shown under that title.</li>
					<li>Keywords and other technical details that help Google understand what the page is about.</li>
				</ul>
				<p>A page with both a title and description filled in is more likely to show up well in search — the Overview screen tracks
				this as your "SEO Complete" percentage.</p>
			'); ?>

			<?php self::topic('SEO Agent', 'An automated helper that scans your site and suggests SEO fixes.', '
				<p>This runs in the background and periodically checks your pages for common SEO problems (missing descriptions, thin content,
				broken internal links, etc.), then surfaces a prioritized list of things worth fixing. Think of it as a standing health check
				you don\'t have to remember to run yourself.</p>
			'); ?>

			<?php self::topic('Locations', 'The towns, cities, or service areas your business covers.', '
				<p>If your business serves specific towns or regions, list them here. This powers "service area" pages and helps local search
				(someone searching "house cleaning in Woburn") find the right page on your site.</p>
			'); ?>

			<div class="aq-help__group">Site structure</div>

			<?php self::topic('Navigation', 'The menu links at the top of your website.', '
				<p>Add, remove, rename, or reorder the links visitors see in your header menu. Changes here show up on every page immediately.</p>
			'); ?>

			<?php self::topic('Footer', 'The links, text, and info at the very bottom of every page.', '
				<p>Controls what shows in the footer — typically contact info, secondary links (like Privacy Policy), and copyright text.
				Like Navigation, one change here updates every page at once.</p>
			'); ?>

			<?php self::topic('Redirects', 'Send an old web address automatically to a new one.', '
				<p>Use this when a page\'s address (URL) changes, or you delete a page, and you don\'t want visitors (or Google) hitting a broken
				"page not found" error. A redirect says "anyone who visits /old-page should be sent to /new-page instead."</p>
				<ul>
					<li>The simple list handles the common case: one exact old address → one new address.</li>
					<li>The <strong>Advanced</strong> section (collapsed by default) supports pattern-based rules for more complex cases — most people never need it.</li>
					<li>There\'s also a "recent broken links" panel that shows real 404 errors visitors hit, so you can add a redirect with one click.</li>
				</ul>
			'); ?>

			<?php self::topic('Logo', 'Upload and position your logo in the header and footer.', '
				<p>Set the logo image used in the site header, the footer, and (if configured) a different "sticky" version that appears once
				a visitor scrolls down. You can also apply simple color adjustments (like making a logo appear white on a dark footer)
				without needing a designer to create a second version of the file.</p>
			'); ?>

			<div class="aq-help__group">Leads &amp; customers</div>

			<?php self::topic('Forms', 'Where contact-form submissions go, and what the notification email looks like.', '
				<p>Every "contact us" or "get an estimate" form on your site funnels through here. This screen controls:</p>
				<ul>
					<li><strong>Who gets emailed</strong> when someone submits a form (the "Send to" and "BCC" addresses).</li>
					<li><strong>The email\'s subject and an optional intro message</strong> — both support simple placeholders like <code>{name}</code> or
					<code>{city}</code> that get swapped for the visitor\'s actual details.</li>
					<li><strong>Delivery settings (SMTP)</strong> — an optional way to send that email through your own mailbox for more reliable delivery.</li>
					<li><strong>Your CRM connection (GoHighLevel/GHL)</strong> — if configured, every submission is also pushed straight into your CRM as a
					new contact, with the message attached as a note.</li>
					<li><strong>Spam protection (Cloudflare Turnstile)</strong> — an invisible-to-most-visitors check that blocks bots from spamming your forms.
					Add the site key and secret key here to turn it on for every form at once.</li>
					<li>A <strong>"send a test email"</strong> button so you can preview exactly what a real submission looks like, without it touching
					your CRM or a real visitor being involved.</li>
				</ul>
				<div class="aq-help__tip"><strong>Tip:</strong> if you ever suspect leads aren\'t coming through, start here — check the "Send to" address
				is correct and send yourself a test email.</div>
			'); ?>

			<?php self::topic('Integrations', 'Connect outside tools — like your CRM — to your site.', '
				<p>Where you paste in connection details for third-party services the site talks to (most commonly your CRM/GoHighLevel account).
				Once connected here, other screens (like Forms) can use that connection automatically.</p>
			'); ?>

			<?php self::topic('Chatbot', 'An AI chat widget visitors can talk to on your site.', '
				<p>Turns on (and configures) a chat bubble visitors can click to ask questions and get instant answers, without waiting for a human
				to respond. Optional — leave it off if you don\'t want it.</p>
			'); ?>

			<div class="aq-help__group">Behind the scenes</div>

			<?php self::topic('Performance', 'How fast your site loads, and cache controls.', '
				<p>Shows whether the built-in speed-up system ("Boost") is active, and gives you a one-click way to clear the site\'s cache
				(a temporary "photocopy" of your pages the server keeps to load faster) if you\'ve just made a change and it doesn\'t seem to
				be showing up yet.</p>
				<div class="aq-help__tip"><strong>Tip:</strong> if you edit a page and the live site still shows the old version after a minute, clear the
				cache from here — that fixes it almost every time.</div>
			'); ?>

			<?php self::topic('Legal Pages', 'Your Privacy Policy, Terms of Service, and similar required pages.', '
				<p>Manages the standard legal pages every site needs. You can either write the content directly here or paste in an embed
				code from a legal-policy service (like Termly), and it\'ll display correctly either way.</p>
			'); ?>

			<?php self::topic('Tracking', 'Analytics and ad-tracking codes (Google Analytics, Google Ads, etc.).', '
				<p>Paste in tracking codes from services like Google Analytics so you can see visitor stats, or from Google/Facebook Ads so
				conversions get tracked properly. This also respects your site\'s environment — tracking is automatically turned off on a
				staging/test copy of the site so test traffic never pollutes your real analytics.</p>
			'); ?>

			<?php self::topic('Import', 'Bulk-load content from a spreadsheet or file instead of typing it in one page at a time.', '
				<p>A power-user tool for loading a lot of content at once (for example, migrating many blog posts or pages in from another
				system). Most day-to-day editing happens in Pages instead — use Import only when you have a batch of content ready to load.</p>
			'); ?>

			<p class="aq-help__intro" style="margin-top:24px;">
				Still stuck, or something looks broken? The safest next step is always to reach out to your developer/agency contact rather
				than guessing — nothing on this page can permanently break your site, but some settings (like Redirects or Integrations) are
				easier to get right with a second pair of eyes.
			</p>
		</div>
		<?php
		AQ_Admin_Hub::close();
	}
}
