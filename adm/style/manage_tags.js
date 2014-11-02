phpbb.addAjaxCallback('topictags.delete_tag', function(data) {
	$(this).parent().parent().remove();
});

$('.topictags_edit_tag').click(function(e) {
	e.preventDefault();
	$(this).parent().parent().find('.topictags_editable_tag').trigger("click");
});

$('.topictags_editable_tag').editable(function(value, settings) {
	var tag = $(this);
	var tag_count = tag.parent().parent().find('.tag_count');
	var url = window.location.href.split("#")[0] + '&action=edit';
	var phpbb_indicator = $('#loading_indicator');
	var old_tag = this.revert;
	phpbb_indicator.show();
	$.post(url, {
		old_tag_name : old_tag,
		new_tag_name : value,
	}).done(function(data) {
		if (!(data instanceof Object)) {
			console.log(data);
			data = {success: false};
		}
		if (data.success) {
			if (undefined !== data.tag_count) {
				// tag might be merged -> update tag_count
				tag_count.text(data.tag_count);
			}
			if (undefined !== data.merged && data.merged) {
				tag.parent().parent().remove();
			}
			alert("success");
		} else {
			if (undefined == data.error_msg) {
				data.error_msg = 'Unknown error. See javascript-console for server response.';
			}
			alert(data.error_msg);
			tag.text(old_tag);
		}
	}).fail(function() {
		alert('Unknown error. See javascript-console for server response.');
		tag.text(old_tag);
	}).always(function() {
		phpbb_indicator.hide();
	});
	return (value);
}, {
	type : 'text',
	submit : 'OK',
	cancel : 'X',
});