jQuery(document).ready(function($) {
    // The nonce and ajaxurl are passed via wp_localize_script as 'drushfe_admin_params'

    function showNotice(message, type) {
        // Remove any existing Econt notices
        $('.econt-admin-notice').remove();
        var cssClass = (type === 'error') ? 'notice-error' : 'notice-success';
        var notice = $('<div class="notice ' + cssClass + ' is-dismissible econt-admin-notice"><p>' + message + '</p>' +
            '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button></div>');
        $('.wrap h1').first().after(notice);
        notice.find('.notice-dismiss').on('click', function() {
            notice.fadeOut(200, function() { $(this).remove(); });
        });
        // Auto-dismiss after 5 seconds
        setTimeout(function() { notice.fadeOut(400, function() { $(this).remove(); }); }, 5000);
    }

    $(document).on('click', '.econt-cancel-shipment', function(e) {
        e.preventDefault();
        if (!confirm(drushfe_admin_params.i18n.confirm_cancel)) {
            return;
        }
        const orderId = $(this).data('order-id');
        const row = $(this).closest('tr');

        $.ajax({
            url: drushfe_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_cancel_shipment',
                order_id: orderId,
                nonce: drushfe_admin_params.nonce
            },
            beforeSend: function() {
                row.css('opacity', '0.5');
            },
            success: function(response) {
                if (response.success) {
                    // Replace waybill cell with a Generate button
                    var waybillCell = row.find('.column-waybill');
                    waybillCell.html(
                        '<button class="button econt-generate-waybill" data-order-id="' + orderId + '">' +
                        drushfe_admin_params.i18n.generate +
                        '</button>'
                    );
                    row.css('opacity', '1');
                    showNotice(response.data, 'success');
                } else {
                    showNotice(response.data, 'error');
                    row.css('opacity', '1');
                }
            }
        });
    });

    $(document).on('click', '.econt-request-courier', function(e) {
        e.preventDefault();
        const orderId = $(this).data('order-id');
        const button = $(this);

        $.ajax({
            url: drushfe_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_request_courier',
                order_id: orderId,
                nonce: drushfe_admin_params.nonce
            },
            beforeSend: function() {
                button.text(drushfe_admin_params.i18n.requesting).prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    button.replaceWith('<span style="color: green;">' + drushfe_admin_params.i18n.requested + '</span>');
                    showNotice(response.data, 'success');
                } else {
                    showNotice(response.data, 'error');
                    button.text(drushfe_admin_params.i18n.request_courier).prop('disabled', false);
                }
            }
        });
    });

    $(document).on('click', '.econt-generate-waybill', function(e) {
        e.preventDefault();
        const orderId = $(this).data('order-id');
        const button = $(this);

        $.ajax({
            url: drushfe_admin_params.ajax_url,
            type: 'POST',
            data: {
                action: 'drushfe_generate_waybill',
                order_id: orderId,
                nonce: drushfe_admin_params.nonce
            },
            beforeSend: function() {
                button.text(drushfe_admin_params.i18n.generating).prop('disabled', true);
            },
            success: function(response) {
                if (response.success) {
                    // Reload the page to show the new waybill info
                    location.reload();
                } else {
                    showNotice(response.data, 'error');
                    button.text(drushfe_admin_params.i18n.generate).prop('disabled', false);
                }
            }
        });
    });
});
