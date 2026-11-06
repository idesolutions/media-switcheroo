(function ($) {
	'use strict';

	const i18n = ( window.mediaSwitcherooAdmin && window.mediaSwitcherooAdmin.i18n ) || {};
	let frame;
	$('#media-switcheroo-select-media').on('click', function (event) {
		event.preventDefault();
		if (frame) {
			frame.open();
			return;
		}
		frame = wp.media({
			title: i18n.chooseTitle,
			button: { text: i18n.chooseButton },
			multiple: false
		});
		frame.on('select', function () {
			const attachment = frame.state().get('selection').first().toJSON();
			const url = new URL(window.location.href);
			url.searchParams.set('attachment_id', attachment.id);
			window.location.href = url.toString();
		});
		frame.open();
	});

	$('#media-switcheroo-replacement-file').on('change', function () {
		const name = this.files && this.files[0] ? this.files[0].name : '';
		if (name) $('#media-switcheroo-file-name').text(name);
	});

	$('#media-switcheroo-replace-form').on('submit', function () {
		const button = $('#media-switcheroo-submit-replace');
		button.prop('disabled', true).text(i18n.replacing);
	});
})(jQuery);
