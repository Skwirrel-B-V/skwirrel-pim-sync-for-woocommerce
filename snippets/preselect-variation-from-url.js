/**
 * Pre-select a WooCommerce variation based on the URL.
 *
 * This script works with the Skwirrel variation permalink system.
 * When a visitor lands on /product/{product-slug}/{variation-slug}/,
 * the plugin injects the variation ID into the form. This script
 * ensures the correct variation is selected and displayed.
 *
 * HOW TO USE:
 * This script is automatically injected by the plugin when variation
 * permalinks are enabled. You only need this file if you want to
 * customize the pre-selection behavior.
 *
 * You can also manually trigger variation pre-selection by adding
 * a data attribute to the variations form:
 *
 *   <form data-preselected-variation="123" class="variations_form">
 *
 * @package Skwirrel_PIM_Sync
 */
(function ($) {
  'use strict';

  if (typeof $ === 'undefined') {
    return;
  }

  $(document).ready(function () {
    var $form = $('form.variations_form');
    if (!$form.length) {
      return;
    }

    var variationId = parseInt($form.attr('data-preselected-variation'), 10);
    if (!variationId || isNaN(variationId)) {
      return;
    }

    // Wait for WooCommerce to initialize the variation form.
    $form.on('wc_variation_form', function () {
      var variations = $form.data('product_variations');
      if (!variations || !variations.length) {
        return;
      }

      // Find the target variation.
      var target = null;
      for (var i = 0; i < variations.length; i++) {
        if (variations[i].variation_id === variationId) {
          target = variations[i];
          break;
        }
      }

      if (!target) {
        return;
      }

      // Set each attribute dropdown to the correct value.
      var attrs = target.attributes;
      for (var key in attrs) {
        if (attrs.hasOwnProperty(key)) {
          var $select = $form.find('[name="' + key + '"]');
          if ($select.length && attrs[key]) {
            $select.val(attrs[key]).trigger('change');
          }
        }
      }

      // Trigger WooCommerce to check and display the variation.
      $form
        .trigger('woocommerce_variation_select_change')
        .trigger('check_variations');
    });
  });
})(jQuery);
