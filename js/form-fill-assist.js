(function (Drupal, once, $) {
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

	Drupal.behaviors.ubcApscExternalAutofillFocus = {
		attach(context, settings) {
			const externalId = settings.ubcApscRemoteArticle?.externalTermId || '';

			once('ubc-apsc-external-autofill', 'select[name="field_announcement_type"], input[name="field_announcement_type"]', context)
				.forEach((el) => {
					el.addEventListener('change', () => {
						const value = el.type === 'radio'
							? document.querySelector('input[name="field_announcement_type"]:checked')?.value
							: el.value;
						if (externalId && String(value) !== String(externalId)) return;

						const details = document.getElementById('edit-group-original-article-source');
						if (details) details.open = true;

						const input = document.querySelector('.remote-article-url, input[name="remote_article__url"]');
						if (!input) return;

						input.scrollIntoView({ behavior: 'smooth', block: 'center' });
						input.focus({ preventScroll: true });
					});
				});
		},
	};
})(Drupal, once, jQuery);
