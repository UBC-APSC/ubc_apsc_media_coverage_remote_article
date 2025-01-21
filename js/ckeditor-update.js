(function ($, Drupal) {
  /**
   * Add new custom command to update CKEditor body
   */
  Drupal.AjaxCommands.prototype.UpdateCkeditorText = function (ajax, response, status ) {
	  
	    var selector = jQuery(response.selector);
		var data = response.args[0];
		
		// Get editor instance by id
		const domEditableElement = selector.attr('data-ckeditor5-id');
		
		// Get the editor instance from the editable element.
		const editorInstance = Drupal.CKEditor5Instances.get(domEditableElement);
		
		if (editorInstance) {
			// Set the new content in the editor instance
			editorInstance.setData(data);
		} else {
			console.error('CKEditor instance not found.');
        }
  }
})(jQuery, Drupal);
