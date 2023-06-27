(function($) {
	// found possible alternative values, enable replacing field input by clicking on alternative value
    $(document).on('click','.remote-value-alternative', function(e){
		var target = $(this).attr('data-update-target')
		var source = $(this).attr('data-update-alt-value');
		var update_val = $( source ).text();
		var prev_val = $( target ).val();
		$( target ).val( update_val );
		$(source).text(prev_val);
		e.preventDefault();
	});
  
	// once the media field has been filled, return to top of page to show messages
	$( document ).ajaxSuccess(function( event, request, settings ) {
		if ( settings.url === "/node/add/media_coverage?ajax_form=1&_wrapper_format=drupal_ajax" ) {
			$( document ).scrollTop( 60 );
		}
	});
})(jQuery);
