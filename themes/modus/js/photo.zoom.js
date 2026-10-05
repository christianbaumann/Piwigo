/* Fork-local (docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md).
   The photo on the picture page is fitted to its area, shown at 100 % of the
   original, or zoomed in steps between the two bounds. Zoom sets the photo's layout size rather than a transform, so
   anything watching its box - the persons overlay - follows it. */
var pwgZoom = (function($){
	var FIT = 'fit', NATURAL = 'natural';
	/* One +/- step multiplies the zoom by this; MAX_SCALE is 400 % of the original. */
	var STEP = 1.25, MAX_SCALE = 4;
	/* Scales this close are equal: a step there and back is not exact in floating point. */
	var SCALE_EPSILON = 1e-9;
	/* Wheel travel per step: one notch of a mouse wheel. A touchpad pinch sends
	   many small deltas, which add up to a step instead of making one each. */
	var WHEEL_STEP_PX = 100;
	/* The class the persons editor puts on its stage while it draws regions
	   with a mouse drag (plugins/persons/template/editor.js). */
	var EDITOR_DRAWING = 'persons-tagging';
	/* mode is FIT, NATURAL, or a scale against the original's size. */
	var mode = FIT,
		scale = null,
		fit = null,
		zoomedIn = false,
		/* True from a drag-to-pan's press until the click its release fires. */
		panning = false,
		areaWidth = null,
		wheelTravel = 0;

	/* The photo's pixel size as the gallery stores it, in display orientation.
	   Without one, the largest derivative stands in; undefined without either. */
	function original(){
		var d = RVAS.derivatives;
		return RVAS.original.w ? RVAS.original : d[d.length-1];
	}

	/* The width and height #theImage offers the photo, in CSS pixels, and the
	   pinch zoom of a mobile browser. */
	function area(){
		var available = rvas_get_available_size();
		return {w: available.w / available.dpr, h: available.h / available.dpr, zoom: available.zoom};
	}

	function fitScale(a){
		var o = original();
		return Math.min(a.w / o.w, a.h / o.h);
	}

	/* 100 %: the original's size, or the largest derivative's when the viewer
	   may not see the original. */
	function naturalScale(){
		var files = rvas_files();
		return files[files.length-1].w / original().w;
	}

	function show(file, display){
		var $img = $('#theMainImage'),
			img = $img[0],
			current = $img.data('rvas-file'),
			loader = $('.img-loader-derivatives').attr('src');

		/* Before the first file arrives the element holds the loading GIF, which
		   the load handler sizes once the photo is there. Any other file takes
		   the new size at once, so a zoom step can be centred before a bigger
		   file has loaded. */
		if ($img.attr('src') !== loader)
			$img.css({width: display.w, height: display.h});
		$img.attr({width: display.w, height: display.h});

		/* Never swap down: a file that is already bigger is sharper, and loaded. */
		if (!current || current.w < file.w){
			$img.attr('src', file.url.replace(/&amp;/g, '&')).data('rvas-file', file);
			$('#derivativeSwitchBox .switchCheck').css('visibility','hidden');
			$('#derivativeChecked'+file.type).css('visibility','visible');
		}
		else
			file = current;

		/* The <map> coords are in the file's own pixels. */
		if (mode == FIT && file.type != 'original' && display.w == file.w && display.h == file.h)
			$img.attr('usemap', '#map'+file.type);
		else
			$img.removeAttr('usemap');
	}

	function apply(a){
		if (!document.getElementById('theMainImage') || !original())
			return;
		a = a || area();
		areaWidth = a.w;
		fit = fitScale(a);
		/* A step the area has outgrown is fit, and stays fit when it shrinks again. */
		if (mode != FIT && mode != NATURAL && mode <= fit * (1 + SCALE_EPSILON))
			mode = FIT;
		scale = mode == FIT ? fit : mode == NATURAL ? naturalScale() : mode;
		var o = original(),
			display = {w: Math.floor(o.w * scale), h: Math.floor(o.h * scale)};

		zoomedIn = scale > fit;
		/* Past fit the photo scrolls inside #theImage instead of the page. */
		$('#theImage').css('max-height', zoomedIn ? Math.floor(a.h) : '')
			.toggleClass('zoomPannable', zoomedIn);
		/* A pinch-zoomed page shows the photo larger than its layout size. */
		show(rvas_choose({w: display.w * a.zoom, h: display.h * a.zoom}), display);
	}

	function setMode(m){
		mode = m;
		RVAS.disable = 0;
		apply();
	}

	/* The middle of what #theImage shows - its client box, cut to the window -
	   in window coordinates. */
	function areaCentre(){
		var area = document.getElementById('theImage'),
			box = area.getBoundingClientRect(),
			left = box.left + area.clientLeft,
			top = box.top + area.clientTop;
		return {
			x: (Math.max(left, 0) + Math.min(left + area.clientWidth, window.innerWidth)) / 2,
			y: (Math.max(top, 0) + Math.min(top + area.clientHeight, window.innerHeight)) / 2
		};
	}

	/* One step in or out, clamped to [fit, MAX_SCALE], keeping the photo point
	   at the middle of the area where it was. */
	function step(factor){
		if (fit === null)
			return;
		var img = document.getElementById('theMainImage'),
			o = original(),
			shown = img.getBoundingClientRect().width,
			/* After the size menu the photo shows its file, not the last zoom. */
			from = RVAS.disable ? shown / o.w : scale,
			to = Math.min(Math.max(from * factor, fit), Math.max(fit, MAX_SCALE));
		/* Nothing to step from while the size menu's file is still loading (the
		   photo is hidden then), and zooming out never enlarges a photo shown
		   below fit - a small one at 100 %. */
		if (!shown || (factor < 1 && to > from) || Math.abs(to - from) <= SCALE_EPSILON * from)
			return;

		var c = areaCentre(),
			r = img.getBoundingClientRect(),
			point = {x: (c.x - r.left) / r.width, y: (c.y - r.top) / r.height};

		setMode(to <= fit * (1 + SCALE_EPSILON) ? FIT : to);

		var area = document.getElementById('theImage');
		c = areaCentre();
		r = img.getBoundingClientRect();
		area.scrollLeft += r.left + point.x * r.width - c.x;
		area.scrollTop += r.top + point.y * r.height - c.y;
	}

	function overPhoto(e){
		var r = document.getElementById('theMainImage').getBoundingClientRect();
		return e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom;
	}

	function init(){
		var $img = $('#theMainImage');
		/* A PDF shown embedded has no photo to zoom. */
		if (!$img.length){
			$('#zoomFit, #zoomNatural, #zoomIn, #zoomOut').remove();
			return;
		}

		$img.off('load').on('load', function() {
			const attrW = $(this).attr('width');
			const attrH = $(this).attr('height');
			$(this).css({
				'width': attrW ? attrW : 'auto',
				'height': attrH ? attrH : 'auto',
			});

			$('.img-loader-derivatives').hide();
			$('#theMainImage').show();
		});

		apply();

		$(function(){
			$(window).resize(function(){
				var a = area();
				/* On the narrow layout only a new width re-fits: a mobile browser
				   changes the height whenever its address bar shows or hides. */
				if (RVAS.disable || (!$('html').hasClass('wide') && a.w == areaWidth))
					return;
				apply(a);
			});

			/* The area can narrow with no window resize, e.g. when the page
			   grows a scrollbar after the photo has loaded. */
			if (window.ResizeObserver){
				new ResizeObserver(function(){
					if (RVAS.disable)
						return;
					var a = area();
					if (a.w != areaWidth)
						apply(a);
				}).observe(document.getElementById('theImage'));
			}

			$('#zoomFit').click(function(e){ e.preventDefault(); setMode(FIT); });
			$('#zoomNatural').click(function(e){ e.preventDefault(); setMode(NATURAL); });
			$('#zoomIn').click(function(e){ e.preventDefault(); step(STEP); });
			$('#zoomOut').click(function(e){ e.preventDefault(); step(1 / STEP); });

			/* Keys and wheel only where the control is: not in the slideshow. */
			if ($('#zoomFit').length){
				$(document).keydown(function(e){
					/* Ctrl/Cmd with these keys is the browser's own page zoom; and
					   an input - the persons name picker - takes them as text. */
					if (e.ctrlKey || e.metaKey || e.altKey || $(e.target).is(':input') || e.target.isContentEditable)
						return;
					if (e.key == '+')
						step(STEP);
					else if (e.key == '-')
						step(1 / STEP);
					else if (e.key == '0')
						setMode(FIT);
					else
						return;
					e.preventDefault();
				});

				/* Ctrl/Cmd + wheel over the photo zooms it instead of the page;
				   a plain wheel keeps scrolling. */
				document.getElementById('theImage').addEventListener('wheel', function(e){
					if (!(e.ctrlKey || e.metaKey) || !overPhoto(e))
						return;
					e.preventDefault();
					wheelTravel += e.deltaMode ? Math.sign(e.deltaY) * WHEEL_STEP_PX : e.deltaY;
					if (Math.abs(wheelTravel) < WHEEL_STEP_PX)
						return;
					step(wheelTravel < 0 ? STEP : 1 / STEP);
					wheelTravel = 0;
				}, {passive: false});
			}

			/* A click on a photo zoomed past fit is meant for the photo, so it
			   must not reach the navigation handler in photo.autosize.js. */
			document.getElementById('theImage').addEventListener('click', function(e){
				if ((zoomedIn || panning) && e.target.id == 'theMainImage')
					e.stopPropagation();
			}, true);

			/* Past fit the photo follows a mouse drag - except while the persons
			   editor draws a region with that drag. Its overlay takes the pointer
			   then, so the target check alone keeps the drag off today; the class
			   check keeps it off should the overlay ever let the pointer through.
			   The guard above holds back the click a drag ends in, even when a
			   zoom key has fitted the photo meanwhile. */
			var theImage = document.getElementById('theImage');
			theImage.addEventListener('mousedown', function(e){
				if (!zoomedIn || e.button != 0 || e.target.id != 'theMainImage'
					|| $(e.target).closest('.'+EDITOR_DRAWING).length)
					return;
				/* No native drag of the image file. */
				e.preventDefault();
				/* By deltas, so a zoom step during the drag carries on from where
				   the step left the photo. */
				var x = e.clientX, y = e.clientY;
				panning = true;
				$(theImage).addClass('zoomPanning');

				function pan(m){
					/* The release went elsewhere - a context menu, another window. */
					if (!(m.buttons & 1)){
						release();
						return;
					}
					theImage.scrollLeft -= m.clientX - x;
					theImage.scrollTop -= m.clientY - y;
					x = m.clientX;
					y = m.clientY;
				}
				function release(){
					document.removeEventListener('mousemove', pan);
					document.removeEventListener('mouseup', release);
					$(theImage).removeClass('zoomPanning');
					/* The click this release fires is dispatched before the timeout. */
					setTimeout(function(){ panning = false; }, 0);
				}
				document.addEventListener('mousemove', pan);
				document.addEventListener('mouseup', release);
			});

			/* The size menu shows its file at natural size and leaves the zoom. */
			if (window.changeImgSrc){
				var sizeMenu = changeImgSrc;
				changeImgSrc = function(url, typeSave, typeMap){
					zoomedIn = false;
					$('#theImage').css('max-height', '').removeClass('zoomPannable');
					$('#theMainImage').css({width: '', height: ''})
						.data('rvas-file', RVAS.derivatives.filter(function(d){ return d.type == typeMap; })[0]);
					sizeMenu.apply(undefined, arguments);
				};
			}
		});
	}

	return {init: init};
})(jQuery);
