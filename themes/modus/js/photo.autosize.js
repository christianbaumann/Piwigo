function rvas_get_available_size(){
		var width = $("#theImage").width(),
			zoom = 1,
			docHeight;

		if ("innerHeight" in window) {
			docHeight = window.innerHeight;
			if (document.documentElement.clientWidth > window.innerWidth && window.innerWidth)
				zoom = document.documentElement.clientWidth / window.innerWidth;
			docHeight = Math.floor(docHeight*zoom);
		}
		else
			docHeight = document.documentElement.offsetHeight;
		var height = docHeight - Math.ceil($("#theImage").offset().top);

		var dpr = window.devicePixelRatio && window.devicePixelRatio>1 ? window.devicePixelRatio : 1;
		width = Math.floor(width*dpr); height = Math.floor(height*dpr);

		document.cookie= 'phavsz='+width+'x'+height+'x'+dpr+';path='+RVAS.cp;
		return {w:width, h:height, dpr:dpr, zoom:zoom};
}

/* Derivative sizes are rounded, so a file this much short of the display size still covers it. */
var RVAS_ROUNDING_PX = 1;

/* The files the photo can be shown from, smallest first: the derivatives, then
   the original when the viewer may see it and it is larger than all of them. */
function rvas_files(){
	var files = RVAS.derivatives.slice(),
		largest = files[files.length-1];
	if (RVAS.original.url && (!largest || RVAS.original.w > largest.w))
		files.push({w:RVAS.original.w, h:RVAS.original.h, url:RVAS.original.url, type:'original'});
	return files;
}

/* The smallest file that covers a display size given in CSS pixels on this
   screen's pixel ratio, or the largest file when none does. */
function rvas_choose(display){
	var dpr = window.devicePixelRatio && window.devicePixelRatio>1 ? window.devicePixelRatio : 1,
		files = rvas_files();
	for (var i=0; i<files.length; i++){
		if (files[i].w >= display.w*dpr - RVAS_ROUNDING_PX && files[i].h >= display.h*dpr - RVAS_ROUNDING_PX)
			return files[i];
	}
	return files[files.length-1];
}

$(document).ready( function() {
	if (window.changeImgSrc) {
		RVAS.changeImgSrcOrig = changeImgSrc;
		changeImgSrc = function() {
			RVAS.disable = 1;

			$('#theMainImage').hide();
			$('.img-loader-derivatives').show();

			RVAS.changeImgSrcOrig.apply(undefined, arguments);

			const dpr = window.devicePixelRatio || 1;
			if (dpr == 1) {
				$('.img-loader-derivatives').hide();
				$('#theMainImage').show();	
				return;
			}
			const currentDerivatives = RVAS.derivatives.filter((d) => d.type == arguments[1]);
			if (!currentDerivatives[0]) return;
			
			const w = Math.floor(currentDerivatives[0].w / dpr);
			const h = Math.floor(currentDerivatives[0].h / dpr);

			$('#theMainImage').attr({
				width: w,
				height: h
			});
		}
	}

	$(window).resize(function() {
		var w = $("body").width(),
			de = $(document.documentElement);
		if (document.location.search.indexOf("slideshow")==-1) {
			if (w<1262)
				de.removeClass("wide");
			else
				de.addClass("wide");
		}
	});

	$("#theMainImage").click( function(e) {
		if (!$(this).attr("usemap") && e.clientY) {
			var pct = (e.pageX - $(this).offset().left) / $(this).width()
				, clientY = e.pageY - $(this).offset().top;
			if (pct < 0.3) {
				if ($("#linkPrev").length && clientY>15)
					window.location = $("#linkPrev").attr("href");
			}
			else if (pct > 0.7 ) {
				if ($("#linkNext").length && clientY>15)
					window.location = $("#linkNext").attr("href");
			}
			else if (clientY/$(this).height() < 0.5 && clientY>15) {
				var href = $(".pwg-icon-arrow-n").parent("a").attr("href");
				if (href)
					window.location = href;
			}
		}
	});
});
