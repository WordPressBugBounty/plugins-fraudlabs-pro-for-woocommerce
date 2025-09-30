jQuery(document).ready(function($){
	$('#button-purge').on('click', function(e) {
		if (!confirm('WARNING: All data will be permanently deleted from the local storage (WordPress), and this action is not reversible. Are you sure you want to proceed with the deletion?')) {
			e.preventDefault();
		} else {
			$('#form-purge').submit();
		}
	});

	$('#enable_wc_fraudlabspro_auto_change_status').on('click', function(e) {
		if ($(this).is(":checked")) {
			if (!confirm('NOTE: By enabling this option, it will auto sync the WooCommerce Completed order with the FraudLabs Pro Approve status and WooCommerce Cancelled order with the FraudLabs Pro Reject status. Are you sure to enable it?')) {
				e.preventDefault();
			}
		}
	});

	$('#validation_sequence').on('click', function() {
		if ($('#validation_sequence').val() == 'after') {
			$('#enable_wc_advanced_velocity').prop('disabled', true);
			$('#enable_wc_advanced_velocity_tr').hide();
			$('#fraud_message').prop('disabled', true);
			$('#fraud_message_tr').hide();
			$('#db_err_status').prop('disabled', false);
			$("#approve_status").prop("disabled", false);
			$("#review_status").prop("disabled", false);
		} else {
			$('#enable_wc_advanced_velocity').prop('disabled', false);
			$('#enable_wc_advanced_velocity_tr').show();
			$('#fraud_message').prop('disabled', false);
			$('#fraud_message_tr').show();
			$("#db_err_status").val("");
			$('#db_err_status').prop('disabled', true);
			$("#approve_status").val("");
			$("#review_status").val("");
			$("#approve_status").prop("disabled", true);
			$("#review_status").prop("disabled", true);
		}
	});

	if ($('#validation_sequence').val() == 'before') {
		$('#enable_wc_advanced_velocity').prop('disabled', false);
		$('#enable_wc_advanced_velocity_tr').show();
		$('#fraud_message').prop('disabled', false);
		$('#fraud_message_tr').show();
		$("#db_err_status").val("");
		$('#db_err_status').prop('disabled', true);
		$("#approve_status").val("");
		$("#review_status").val("");
		$("#approve_status").prop("disabled", true);
		$("#review_status").prop("disabled", true);
	}

	if ($('#validation_sequence').val() == 'after') {
		$("#approve_status").prop("disabled", false);
		$("#review_status").prop("disabled", false);
		$('#db_err_status').prop('disabled', false);
		$('#enable_wc_advanced_velocity_tr').hide();
		$('#fraud_message_tr').hide();
	}

	$('#enable_wc_fraudlabspro_debug_log').on('click', function() {
		if(!$('#enable_wc_fraudlabspro_debug_log').is(':checked')) {
			$('#wc_fraudlabspro_debug_log_path').prop('disabled', true);
		} else {
			$('#wc_fraudlabspro_debug_log_path').prop('disabled', false);
		}
	});

	$('.dismiss-button').on('click', function(e) {
		$('#modal-step-1').css('display', 'none');
	});

	$('#setup_flp_key').on('input', function() {
		$('#btn-to-step-2').prop('disabled', !($(this).val().length == 32));
	});

	$('#btn-to-step-2').on('click', function(e) {
		e.preventDefault();

		$('#modal-step-1').css('display', 'none');
		$('#modal-step-2').css('display', 'block');

		var $btn = $(this);

		$('#fraudlabs_pro_key_validation_status').html('<span class="dashicons dashicons-update spin"></span> Validating API Key...');
		$btn.prop('disabled', true);

		$.post(ajaxurl, { action: 'fraudlabspro_woocommerce_validate_api_key', token: $('#setup_flp_key').val(), __nonce: $('#validate_api_key_nonce').val() }, function(response) {
			if (response.indexOf("SUCCESS") == 0) {
				$('#btn-to-step-3').prop('disabled', false);
				$('#fraudlabs_pro_key_validation_status').html('<span class="dashicons dashicons-yes-alt" style="color:green;"></span> You are currently subscribed to a ' + response.substr(8) + ' Plan.</p></div>');
			} else {
				$('#btn-to-step-1').prop('disabled', false);
				$('#fraudlabs_pro_key_validation_status').html('<span class="dashicons dashicons-warning" style="color:red;"></span> Invalid API Key. Please click on <strong>&laquo; Previous</strong> button to re-enter the API Key.');
			}
		})
		.error(function() {
			$('#fraudlabs_pro_key_validation_status').html('<span class="dashicons dashicons-warning" style="color:red;"></span> Validation skipped. Unable to validate the API Key.');
			$('#btn-to-step-3').prop('disabled', false);
		})
		.always(function() {
		});
	});

	$('#btn-to-step-1').on('click', function(e) {
		e.preventDefault();

		$('#modal-step-1').css('display', 'block');
		$('#modal-step-2').css('display', 'none');
		$('#btn-to-step-2').prop('disabled', false);
	});

	$('#btn-to-step-3').on('click', function() {
		$('#modal-step-2').css('display', 'none');
		$('#modal-step-3').css('display', 'block');
	});
});