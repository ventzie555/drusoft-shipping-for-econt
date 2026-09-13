jQuery(document).ready(function($) {

    var params = drushfe_metabox_params;

    function showMetaboxNotice(message, type) {
        var $notice = $('#econt-metabox-notice');
        var color = (type === 'error') ? '#a00' : '#00a32a';
        $notice.html('<p style="color: ' + color + ';">' + message + '</p>');
        setTimeout(function() { $notice.fadeOut(400, function() { $(this).html('').show(); }); }, 5000);
    }

    // Generate Waybill
    $(document).on('click', '.econt-order-generate', function(e) {
        e.preventDefault();
        var button = $(this);
        var orderId = button.data('order-id');

        button.text(params.i18n.generating).prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_generate_waybill',
                order_id: orderId,
                nonce: params.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Reload to show the waybill info
                    location.reload();
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text(params.i18n.generate_waybill).prop('disabled', false);
                }
            }
        });
    });

    // Request Courier
    $(document).on('click', '.econt-order-request-courier', function(e) {
        e.preventDefault();
        var button = $(this);
        var orderId = button.data('order-id');

        button.text(params.i18n.requesting).prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_request_courier',
                order_id: orderId,
                nonce: params.nonce
            },
            success: function(response) {
                if (response.success) {
                    button.replaceWith(
                        '<span class="button disabled" style="text-align:center; color: green;">' +
                        params.i18n.courier_requested + '</span>'
                    );
                    showMetaboxNotice(response.data, 'success');
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text(params.i18n.request_courier).prop('disabled', false);
                }
            }
        });
    });

    // Cancel Shipment
    $(document).on('click', '.econt-order-cancel', function(e) {
        e.preventDefault();
        if (!confirm(params.i18n.confirm_cancel)) {
            return;
        }

        var button = $(this);
        var orderId = button.data('order-id');
        var $content = $('#econt-metabox-content');

        button.text(params.i18n.cancelling).prop('disabled', true);

        $.ajax({
            url: params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_cancel_shipment',
                order_id: orderId,
                nonce: params.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Replace metabox content with Generate button
                    $content.html(
                        '<p>' + params.i18n.no_waybill + '</p>' +
                        '<button type="button" class="button button-primary econt-order-generate" data-order-id="' + orderId + '">' +
                        params.i18n.generate_waybill + '</button>'
                    );
                    showMetaboxNotice(response.data, 'success');
                } else {
                    showMetaboxNotice(response.data, 'error');
                    button.text(params.i18n.cancel_shipment).prop('disabled', false);
                }
            }
        });
    });
});

