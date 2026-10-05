/* Fork-local (docs/agents/decisions/0032-modus-picture-fit-and-zoom-is-a-fork-local-theme-edit.md).
   The photo on the picture page is fitted to its area, or shown at 100 % of the
   original. Zoom sets the photo's layout size rather than a transform, so
   anything watching its box - the persons overlay - follows it. */
var pwgZoom = (function($){
	var FIT = 'fit', NATURAL = 'natural';
	var mode = FIT,
		zoomedIn = false,
		areaWidth = null;

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
		   the load handler sizes once the photo is there. */
		if (img.complete && $img.attr('src') !== loader)
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
		var fit = fitScale(a),
			scale = mode == FIT ? fit : naturalScale(),
			o = original(),
			display = {w: Math.floor(o.w * scale), h: Math.floor(o.h * scale)};

		zoomedIn = scale > fit;
		/* Past fit the photo scrolls inside #theImage instead of the page. */
		$('#theImage').css('max-height', zoomedIn ? Math.floor(a.h) : '');
		/* A pinch-zoomed page shows the photo larger than its layout size. */
		show(rvas_choose({w: display.w * a.zoom, h: display.h * a.zoom}), display);
	}

	function setMode(m){
		mode = m;
		RVAS.disable = 0;
		apply();
	}

	function init(){
		var $img = $('#theMainImage');
		/* A PDF shown embedded has no photo to zoom. */
		if (!$img.length){
			$('#zoomFit, #zoomNatural').remove();
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

			/* A click on a photo zoomed past fit is meant for the photo, so it
			   must not reach the navigation handler in photo.autosize.js. */
			document.getElementById('theImage').addEventListener('click', function(e){
				if (zoomedIn && e.target.id == 'theMainImage')
					e.stopPropagation();
			}, true);

			/* The size menu shows its file at natural size and leaves the zoom. */
			if (window.changeImgSrc){
				var sizeMenu = changeImgSrc;
				changeImgSrc = function(url, typeSave, typeMap){
					zoomedIn = false;
					$('#theImage').css('max-height', '');
					$('#theMainImage').css({width: '', height: ''})
						.data('rvas-file', RVAS.derivatives.filter(function(d){ return d.type == typeMap; })[0]);
					sizeMenu.apply(undefined, arguments);
				};
			}
		});
	}

	return {init: init};
})(jQuery);
