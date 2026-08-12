(function ($, Drupal, once) {
  'use strict';

  /** ----------------------------------------
   *  TÌm sản phẩm
   * -----------------------------------------*/
  Drupal.behaviors.invoiceSearch = {
    attach(context) {
      once('invoice-autocomplete', '.input-search-product', context).forEach(function (element) {
        const $input = $(element);

        let originalValue = '';
        let debounceTimer = null;

        $input.autocomplete({
          source: function (request, response) {
            const term = originalValue = request.term.trim();
            if (term) {
              if (debounceTimer) {
                clearTimeout(debounceTimer);
              }
              debounceTimer = setTimeout(() => {
                if (!term) return;

                $.ajax({
                  url: $input.data('autocomplete-path'),
                  data: { q: term },
                  dataType: 'json',
                  success: response
                });
              }, 150);
            }
          },

          select: function (event, ui) {
            event.preventDefault();
            originalValue = ui.item.label + ' (' + ui.item.value + ')';
            $(this).val(originalValue);
          },

          focus: function (event, ui) {
            event.preventDefault();
            $(this).val(ui.item.label + ' (' + ui.item.value + ')');
          },
        });

        $(document).on('mouseleave', 'ul.ui-autocomplete', function () {
          $input.val(originalValue);
        });

      });
    }
  };

})(jQuery, Drupal, once);
