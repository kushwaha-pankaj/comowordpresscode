/**
 * Checkout Page Payment Method Handler
 * Provides better UX for payment method selection
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        initPaymentMethodSelector();
    });

    function initPaymentMethodSelector() {
        const $paymentSection = $('#payment');
        if (!$paymentSection.length) return;

        // Wait for payment methods to load
        setTimeout(function() {
            setupPaymentOptions();
        }, 500);

        // Also listen for WooCommerce updates
        $(document.body).on('updated_checkout', function() {
            setupPaymentOptions();
        });
    }

    function setupPaymentOptions() {
        const $paymentSection = $('#payment');
        const $paymentMethods = $paymentSection.find('ul.payment_methods');
        
        if (!$paymentMethods.length) return;

        // Check if we already set up custom UI
        if ($paymentSection.hasClass('ct-payment-customized')) {
            return;
        }

        // Find Google Pay button
        const $gpayButton = $paymentSection.find('[id*="express-checkout"] button, [class*="express-checkout"] button, .wc-block-components-express-payment button').first();
        const $cardMethod = $paymentMethods.find('li.wc_payment_method').first();
        
        // Create custom payment options container
        let $customOptions = $paymentSection.find('.ct-payment-options');
        
        if (!$customOptions.length) {
            $customOptions = $('<div class="ct-payment-options"></div>');
            $paymentMethods.before($customOptions);
        }

        // Clear existing options
        $customOptions.empty();

        // Create Google Pay option
        if ($gpayButton.length) {
            const $gpayOption = $('<div class="ct-payment-option ct-payment-gpay" data-method="gpay">' +
                '<div class="ct-payment-option-header">' +
                '<input type="radio" name="ct_payment_method" id="ct_payment_gpay" value="gpay">' +
                '<label for="ct_payment_gpay">' +
                '<span class="ct-payment-icon"></span>' +
                '<span class="ct-payment-label">Pay with Google Pay</span>' +
                '</label>' +
                '</div>' +
                '</div>');
            
            $customOptions.append($gpayOption);
            
            // Handle Google Pay selection
            $gpayOption.find('input[type="radio"]').on('change', function() {
                if ($(this).is(':checked')) {
                    // Uncheck card method
                    $cardMethod.find('input[type="radio"]').prop('checked', false);
                    $cardMethod.removeClass('ct-payment-selected');
                    
                    // Hide card form
                    $cardMethod.find('.payment_box').slideUp(300);
                    
                    // Show Google Pay button container
                    const $gpayContainer = $gpayButton.closest('.wc-block-components-express-payment, [id*="express-checkout"], [class*="express-checkout"]');
                    if ($gpayContainer.length) {
                        $gpayContainer.css({
                            'display': 'block',
                            'margin-top': '18px',
                            'margin-left': '54px',
                            'margin-right': '20px',
                            'margin-bottom': '18px'
                        }).slideDown(300);
                    }
                    
                    // Mark as selected
                    $('.ct-payment-option').removeClass('ct-payment-selected');
                    $gpayOption.addClass('ct-payment-selected');
                    
                    // The actual Google Pay button will handle the payment when clicked
                }
            });
        }

        // Create Card Payment option
        if ($cardMethod.length) {
            const $cardLabel = $cardMethod.find('label').first().text().trim() || 'Credit/Debit Card';
            const $cardOption = $('<div class="ct-payment-option ct-payment-card" data-method="card">' +
                '<div class="ct-payment-option-header">' +
                '<input type="radio" name="ct_payment_method" id="ct_payment_card" value="card">' +
                '<label for="ct_payment_card">' +
                '<span class="ct-payment-icon">💳</span>' +
                '<span class="ct-payment-label">Pay by Card</span>' +
                '</label>' +
                '</div>' +
                '</div>');
            
            $customOptions.append($cardOption);
            
            // Handle Card selection
            $cardOption.find('input[type="radio"]').on('change', function() {
                if ($(this).is(':checked')) {
                    // Check card method radio
                    $cardMethod.find('input[type="radio"]').prop('checked', true).trigger('change');
                    $cardMethod.addClass('ct-payment-selected');
                    
                    // Show card form with animation
                    const $cardForm = $cardMethod.find('.payment_box');
                    if ($cardForm.length) {
                        $cardForm.css('display', 'block').hide().slideDown(300);
                    }
                    
                    // Hide Google Pay button container
                    const $gpayContainer = $gpayButton.closest('.wc-block-components-express-payment, [id*="express-checkout"], [class*="express-checkout"]');
                    if ($gpayContainer.length) {
                        $gpayContainer.slideUp(300, function() {
                            $(this).css('display', 'none');
                        });
                    }
                    
                    // Mark as selected
                    $('.ct-payment-option').removeClass('ct-payment-selected');
                    $cardOption.addClass('ct-payment-selected');
                }
            });

            // Sync with original card method radio
            $cardMethod.find('input[type="radio"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $cardOption.find('input[type="radio"]').prop('checked', true);
                    $cardOption.addClass('ct-payment-selected');
                    $('.ct-payment-option').not($cardOption).removeClass('ct-payment-selected');
                }
            });
        }

        // Hide original payment methods list (but keep it for form submission)
        $paymentMethods.css({
            'position': 'absolute',
            'opacity': '0',
            'pointer-events': 'none',
            'height': '1px',
            'overflow': 'hidden'
        });

        // Hide Google Pay button initially
        if ($gpayButton.length) {
            $gpayButton.closest('.wc-block-components-express-payment, [id*="express-checkout"], [class*="express-checkout"]').hide();
        }

        // Hide card form initially
        if ($cardMethod.length) {
            $cardMethod.find('.payment_box').hide();
        }

        // Mark as customized
        $paymentSection.addClass('ct-payment-customized');

        // Set default selection if none selected
        if (!$('.ct-payment-option input[type="radio"]:checked').length && $cardOption) {
            $cardOption.find('input[type="radio"]').prop('checked', true).trigger('change');
        }
    }
})(jQuery);

