<?php
/**
 * aq_gallery — client-agnostic media gallery section (every AutoForge site gets it).
 *
 * Bulk-add from the media library, manual/auto ordering, responsive CSS grid,
 * an optional category filter/tab bar with per-tile label chips, and a
 * self-contained (no-library) click-to-enlarge lightbox. All ordering /
 * sanitizing / category logic lives in AQ_Gallery (pure, unit-tested); this
 * template only resolves attachment data and prints markup.
 *
 * Stored shape (see AQ_Gallery::sanitize_gallery):
 *   images[]        { id:int(attachment), caption?:string, category:string }
 *   columns 2..5, gap sm|md|lg|Npx, order_by, lightbox bool,
 *   filters_enabled bool, categories[] (ordered label list)
 *
 * Images are stored as attachment IDs; a basename fallback is resolved at
 * render via AQ_Content_Sync so hand-authored JSON still binds.
 */

if (!class_exists('AQ_Gallery')) {
	return;
}

$s       = $args['s'] ?? [];
$conf    = AQ_Gallery::sanitize_gallery(is_array($s) ? $s : []);
$editing = function_exists('ka_is_editing') && ka_is_editing();

/* Resolve each stored image to attachment-derived fields so the sort stays pure.
 * `orig` is the index into the section's `images` array — the stable hook the
 * canvas drag-reorder uses (it survives sorting/filtering of the display list). */
$resolved = [];
foreach ($conf['images'] as $orig => $img) {
	$raw = $img['id'];
	$att = is_numeric($raw)
		? (int) $raw
		: (class_exists('AQ_Content_Sync') ? (int) (AQ_Content_Sync::image_info((string) $raw)['id'] ?? 0) : 0);
	if ($att <= 0) {
		continue; // unresolved / deleted attachment → render nothing for it
	}
	$file = (string) get_attached_file($att);
	$resolved[] = [
		'id'       => $att,
		'orig'     => (int) $orig,
		'title'    => (string) get_the_title($att),
		'filename' => $file !== '' ? basename($file) : '',
		'date'     => (string) get_post_field('post_date', $att),
		'alt'      => trim((string) get_post_meta($att, '_wp_attachment_image_alt', true)),
		'caption'  => (string) ($img['caption'] ?? ''),
		'category' => (string) ($img['category'] ?? ''),
	];
}

// Zero output when there is nothing to show — EXCEPT in the visual editor, where
// an empty gallery renders a visible placeholder so a freshly-added section is
// obvious and clickable (the in-place editor opens on it). On the public site
// (not editing) an empty gallery still outputs nothing.
if (!$resolved) {
	if (function_exists('ka_is_editing') && ka_is_editing()) {
		?>
		<section class="aq-gallery aq-gallery--empty py-12 md:py-16 lg:py-20">
			<div class="container-edge container-edge--wide">
				<div class="aq-gallery__placeholder">
					<strong>Gallery</strong>
					<span>Add images from the sidebar to build this gallery.</span>
				</div>
			</div>
			<style>
			.aq-gallery__placeholder{border:2px dashed rgba(0,0,0,.2);border-radius:.75rem;padding:3rem 1.5rem;text-align:center;color:#5b6471;display:flex;flex-direction:column;gap:.35rem}
			.aq-gallery__placeholder strong{font-size:1.05rem;color:#15191f;letter-spacing:.02em;text-transform:uppercase}
			</style>
		</section>
		<?php
	}
	return;
}

$resolved = AQ_Gallery::sort_images($resolved, $conf['order_by']);

/* Filter bar: only when explicitly enabled AND 2+ categories are actually used. */
$cats     = $conf['filters_enabled'] ? AQ_Gallery::distinct_categories($resolved, $conf['categories']) : [];
$show_bar = count($cats) >= 2;

$gap_css = ['sm' => '0.75rem', 'md' => '1.5rem', 'lg' => '2rem'];
$gap     = $gap_css[$conf['gap']] ?? (preg_match('/^\d+px$/', $conf['gap']) ? $conf['gap'] : '1.5rem');
$lightbox = $conf['lightbox'];

// Print the self-contained CSS + JS exactly once per page (multiple galleries share it).
$print_assets = empty($GLOBALS['aq_gallery_assets_printed']);
$GLOBALS['aq_gallery_assets_printed'] = true;
?>
<?php
// Editor-only hooks: mark the gallery container + its ordering mode so the canvas
// can enable drag-to-reorder (only when order_by is manual). Zero output on the
// public site.
$edit_attrs = $editing ? ' data-aq-gallery data-aq-gallery-order="' . esc_attr($conf['order_by']) . '"' : '';
?>
<section class="aq-gallery py-12 md:py-16 lg:py-20"<?php echo $lightbox ? ' data-lightbox="1"' : ''; ?><?php echo $edit_attrs; ?>>
	<div class="container-edge container-edge--wide">
		<?php if ($show_bar) : ?>
		<div class="aq-gallery__filters" role="group" aria-label="Filter gallery by category">
			<button type="button" class="aq-gallery__filter is-active" data-filter="all" aria-pressed="true">All</button>
			<?php foreach ($cats as $cat) : ?>
			<button type="button" class="aq-gallery__filter" data-filter="<?php echo esc_attr(AQ_Gallery::cat_slug($cat)); ?>" aria-pressed="false"><?php echo esc_html($cat); ?></button>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
		<div class="aq-gallery__grid" style="--aqg-cols:<?php echo (int) $conf['columns']; ?>;--aqg-gap:<?php echo esc_attr($gap); ?>;">
			<?php foreach ($resolved as $i => $item) :
				$img_html = ka_picture($item['id'], [
					'size'  => 'aq_gallery',
					'sizes' => '(min-width: 1024px) ' . (string) round(100 / max(1, (int) $conf['columns'])) . 'vw, (min-width: 640px) 50vw, 100vw',
					'class' => 'aq-gallery__img',
				]);
				if ($img_html === '') {
					continue;
				}
				$cat_slug  = $item['category'] !== '' ? AQ_Gallery::cat_slug($item['category']) : '';
				$full      = (string) wp_get_attachment_image_url($item['id'], 'full');
				$alt       = $item['alt'] !== '' ? $item['alt'] : $item['title'];
				// Editor-only: stable original-index hook + draggable cue for canvas reorder.
				$item_attrs = $editing ? ' data-aq-gallery-item="' . (int) $item['orig'] . '" draggable="true"' : '';
				?>
			<figure class="aq-gallery__item"<?php echo $cat_slug !== '' ? ' data-category="' . esc_attr($cat_slug) . '"' : ''; ?><?php echo $item_attrs; ?><?php echo ka_field_attr('images', $i); ?>>
				<?php if ($editing) : ?><span class="aq-gallery__drag" aria-hidden="true" title="Drag to reorder">⠿</span><?php endif; ?>
				<?php if ($lightbox) : ?>
				<button type="button" class="aq-gallery__trigger" data-full="<?php echo esc_url($full); ?>" data-caption="<?php echo esc_attr($item['caption']); ?>" aria-label="<?php echo esc_attr('Enlarge image' . ($alt !== '' ? ': ' . $alt : '')); ?>">
					<?php echo $img_html; ?>
					<?php if ($item['category'] !== '') : ?><span class="aq-gallery__chip"><?php echo esc_html($item['category']); ?></span><?php endif; ?>
				</button>
				<?php else : ?>
				<?php echo $img_html; ?>
				<?php if ($item['category'] !== '') : ?><span class="aq-gallery__chip"><?php echo esc_html($item['category']); ?></span><?php endif; ?>
				<?php endif; ?>
				<?php if ($item['caption'] !== '') : ?>
				<figcaption class="aq-gallery__cap"><?php echo esc_html($item['caption']); ?></figcaption>
				<?php endif; ?>
			</figure>
			<?php endforeach; ?>
		</div>
	</div>
	<?php if ($print_assets) : ?>
	<style>
	.aq-gallery__filters{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;margin-bottom:2rem}
	.aq-gallery__filter{appearance:none;border:1px solid rgba(0,0,0,.14);background:#fff;color:inherit;font:inherit;font-size:.8125rem;font-weight:600;letter-spacing:.02em;text-transform:uppercase;padding:.5rem 1rem;border-radius:999px;cursor:pointer;transition:background-color .18s,color .18s,border-color .18s}
	.aq-gallery__filter:hover{border-color:rgba(0,0,0,.35)}
	.aq-gallery__filter.is-active{background:#1f2937;color:#fff;border-color:#1f2937}
	.aq-gallery__filter:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
	.aq-gallery__grid{display:grid;gap:var(--aqg-gap,1.5rem);grid-template-columns:repeat(1,minmax(0,1fr))}
	@media(min-width:640px){.aq-gallery__grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
	@media(min-width:1024px){.aq-gallery__grid{grid-template-columns:repeat(var(--aqg-cols,3),minmax(0,1fr))}}
	.aq-gallery__item{margin:0;position:relative;transition:opacity .2s}
	.aq-gallery__item[hidden]{display:none}
	.aq-gallery__trigger{display:block;width:100%;padding:0;border:0;background:none;cursor:zoom-in;position:relative}
	.aq-gallery__trigger:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
	.aq-gallery__img{display:block;width:100%;height:auto;object-fit:cover;aspect-ratio:4/3;border-radius:.5rem;box-shadow:0 4px 12px rgba(0,0,0,.1)}
	.aq-gallery__chip{position:absolute;left:.625rem;bottom:.625rem;background:rgba(17,24,39,.82);color:#fff;font-size:.6875rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:.25rem .55rem;border-radius:.3rem;pointer-events:none}
	.aq-gallery__cap{margin-top:.75rem;font-size:.875rem;text-align:center;opacity:.8}
	.aq-gallery__drag{display:none}
	.aq-gallery__item[draggable="true"]{cursor:grab}
	.aq-gallery__item[draggable="true"] .aq-gallery__drag{display:inline-flex;position:absolute;top:.5rem;right:.5rem;z-index:3;align-items:center;justify-content:center;background:rgba(17,24,39,.78);color:#fff;border-radius:.35rem;padding:.15rem .45rem;font-size:.85rem;line-height:1;cursor:grab}
	.aq-gallery__item.aqg-dragging{opacity:.45}
	.aq-gallery__item.aqg-over{outline:2px dashed #2563eb;outline-offset:3px;border-radius:.5rem}
	@media(prefers-reduced-motion:reduce){.aq-gallery__item.aqg-dragging{opacity:1;transition:none}}
	.aq-gallery-lb{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.9);padding:2rem}
	.aq-gallery-lb[hidden]{display:none}
	.aq-gallery-lb__img{max-width:min(92vw,1600px);max-height:82vh;width:auto;height:auto;border-radius:.5rem;box-shadow:0 12px 48px rgba(0,0,0,.5)}
	.aq-gallery-lb__cap{position:absolute;left:0;right:0;bottom:1.25rem;text-align:center;color:#fff;font-size:.9375rem;padding:0 2rem}
	.aq-gallery-lb__close{position:absolute;top:1rem;right:1.25rem;width:2.5rem;height:2.5rem;border:0;border-radius:999px;background:rgba(255,255,255,.14);color:#fff;font-size:1.5rem;line-height:1;cursor:pointer}
	.aq-gallery-lb__close:focus-visible{outline:2px solid #fff;outline-offset:2px}
	@media(prefers-reduced-motion:reduce){.aq-gallery__filter,.aq-gallery__item{transition:none}}
	</style>
	<script>
	(function(){
	if(window.__aqGalleryInit)return;window.__aqGalleryInit=1;
	var reduce=window.matchMedia&&matchMedia('(prefers-reduced-motion: reduce)').matches;
	/* ---- category filtering (event-delegated, works for every gallery) ---- */
	document.addEventListener('click',function(e){
		var btn=e.target.closest?e.target.closest('.aq-gallery__filter'):null;
		if(!btn)return;
		var bar=btn.closest('.aq-gallery__filters'),sec=btn.closest('.aq-gallery');
		if(!bar||!sec)return;
		var want=btn.getAttribute('data-filter');
		bar.querySelectorAll('.aq-gallery__filter').forEach(function(b){
			var on=b===btn;b.classList.toggle('is-active',on);b.setAttribute('aria-pressed',on?'true':'false');
		});
		sec.querySelectorAll('.aq-gallery__item').forEach(function(it){
			var cat=it.getAttribute('data-category')||'';
			it.hidden=!(want==='all'||cat===want);
		});
	});
	/* ---- lightbox (single shared overlay) ---- */
	var lb,lbImg,lbCap,lbClose,lastTrigger;
	function build(){
		lb=document.createElement('div');lb.className='aq-gallery-lb';lb.hidden=true;lb.setAttribute('role','dialog');lb.setAttribute('aria-modal','true');lb.setAttribute('aria-label','Image viewer');
		lbClose=document.createElement('button');lbClose.type='button';lbClose.className='aq-gallery-lb__close';lbClose.setAttribute('aria-label','Close');lbClose.innerHTML='&times;';
		lbImg=document.createElement('img');lbImg.className='aq-gallery-lb__img';lbImg.alt='';
		lbCap=document.createElement('div');lbCap.className='aq-gallery-lb__cap';
		lb.appendChild(lbClose);lb.appendChild(lbImg);lb.appendChild(lbCap);document.body.appendChild(lb);
		lb.addEventListener('click',function(e){if(e.target===lb)close();});
		lbClose.addEventListener('click',close);
		document.addEventListener('keydown',function(e){
			if(lb.hidden)return;
			if(e.key==='Escape'){e.preventDefault();close();}
			else if(e.key==='Tab'){e.preventDefault();lbClose.focus();} /* keep focus inside */
		});
	}
	function open(t){
		if(!lb)build();
		lastTrigger=t;
		lbImg.src=t.getAttribute('data-full')||'';
		var cap=t.getAttribute('data-caption')||'';
		lbImg.alt=t.getAttribute('aria-label')||'';
		lbCap.textContent=cap;lbCap.style.display=cap?'block':'none';
		lb.style.transition=reduce?'none':'';
		lb.hidden=false;lbClose.focus();
	}
	function close(){
		if(!lb||lb.hidden)return;
		lb.hidden=true;lbImg.src='';
		if(lastTrigger&&lastTrigger.focus){lastTrigger.focus();}
	}
	document.addEventListener('click',function(e){
		var sec=e.target.closest?e.target.closest('.aq-gallery[data-lightbox="1"]'):null;
		if(!sec)return;
		var t=e.target.closest('.aq-gallery__trigger');
		if(t){e.preventDefault();open(t);}
	});
	})();
	</script>
	<?php endif; ?>
</section>
