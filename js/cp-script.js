// Prevent conflict with other libraries
jQuery.noConflict();

(function( $ ) {
    $(function() {

        ////////////////////////////////
        // Countdown timer for transaction verification
        ////////////////////////////////
        var cpCount = parseInt($('input[name="cp_order_remaining_time"]').val());
        var cpCounter = setInterval(timer, 1000);

        function formatTime(seconds) {
            var h = Math.floor(seconds / 3600),
                m = Math.floor((seconds % 3600) / 60),
                s = seconds % 60;
            if (h < 10) h = "0" + h;
            if (m < 10) m = "0" + m;
            if (s < 10) s = "0" + s;
            return h > 0 ? h + ":" + m + ":" + s : m + ":" + s;
        }

        function timer() {
            if (cpCount <= 0) {
                clearInterval(cpCounter);
                $('.cp-counter').html('00:00');
                return;
            }
            cpCount--;
            $('.cp-counter').html(formatTime(cpCount));
        }

        ////////////////////////////////
        // Copy button action
        ////////////////////////////////
        $(document).on('click', '.cp-copy-btn', function(e){
            var btn = $(this);
            var input = btn.closest('.cp-input-box').find('input');

            input.select();
            try {
                var successful = document.execCommand("copy");
                if (successful) {
                    btn.addClass('cp-copied');
                    setTimeout(() => btn.removeClass('cp-copied'), 1000);
                } else {
                    alert("Copying not supported. Please copy manually.");
                }
            } catch (err) {
                alert("Copying not supported. Please copy manually.");
            }
        });

        ////////////////////////////////
        // Transaction Verification
        ////////////////////////////////
        var cp_interval;
        verifyTransaction();

        function verifyTransaction(){
            clearTimeout(cp_interval);
            cp_interval = null;

            $.ajax({
                url: '/wp-admin/admin-ajax.php',
                type: "POST",
                data: {
                    action: "nekopay_verify_payment",
                    order_id: $('input[name="cp_order_id"]').val()
                },
                dataType: "json",
                success: function(response) {
                    console.log(response);
                    var order_message = $('.cp-payment-msg');
                    var order_info_holder = $('.cp-payment-info-holder');
                    var order_status = $('.cp-payment-info-status');

                    // Update status message
                    order_status.html(response.message || "Processing...");

                    // Handle specific status updates
                    if (["waiting", "detected", "failed"].includes(response.status)) {
                        if (response.status === "detected") {
                            clearInterval(cpCounter);
                            $('.cp-counter').html('00:00');
                        }
                        cp_interval = setTimeout(verifyTransaction, 10000);
                    } else if (response.status === "expired") {
                        order_message.html('The payment time for this order has expired! Do not make any payments as they will be invalid. If you already paid, please wait.');
                        setTimeout(() => location.reload(), 2000);
                    } else if (response.status === "confirmed") {
                        order_info_holder.addClass('cp-confirmed');
                        clearInterval(cpCounter);
                        $('.cp-counter').html('00:00');
                        setTimeout(() => location.reload(), 2000);
                    }
                },
                error: function() {
                    console.error("Verification request failed.");
                }
            });
        }
    });
})(jQuery);
