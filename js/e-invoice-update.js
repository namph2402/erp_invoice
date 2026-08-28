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

  /** ----------------------------------------
   *  Chọn nhanh cách xử lý cho mọi dòng hàng
   * -----------------------------------------*/
  Drupal.behaviors.invoiceBulkAction = {
    attach(context) {
      once('invoice-bulk-action', '.invoice-bulk-action', context).forEach(function (element) {
        element.addEventListener('change', function () {
          const value = this.value;

          if (!value) {
            return;
          }

          document.querySelectorAll('select.invoice-item-action').forEach(function (select) {
            if (select.value === value) {
              return;
            }

            select.value = value;
            // #states của Drupal nghe sự kiện change nên phải bắn lại sau khi đổi.
            select.dispatchEvent(new Event('change', { bubbles: true }));
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
