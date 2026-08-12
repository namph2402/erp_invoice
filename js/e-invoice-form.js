(function ($, Drupal, once) {
  'use strict';

  /** ----------------------------------------
   *  Lấy tất cả rows và row cuối
   * -----------------------------------------*/
  const RowFinder = {
    all() { return $('[id^="field-invoice-items-values"] tbody tr') },
    last() { return this.all().last() },
    hasValue($row) {
      return Boolean($row && $row.find('[name^="field_invoice_items"][name$="[item_name]"]').val());
    }
  };

  /** ----------------------------------------
   *  Điền dữ liệu vào row
   * -----------------------------------------*/
  function UIFill($row, p) {
    $row.find('[name^="field_invoice_items"][name$="[item_code]"]').val(p.code);
    $row.find('[name^="field_invoice_items"][name$="[item_name]"]').val(p.name);
    $row.find('[name^="field_invoice_items"][name$="[item_unit]"]').val(p.unit);
    $row.find('[name^="field_invoice_items"][name$="[item_type]"]').val(p.type);

    const $qty = $row.find('[name^="field_invoice_items"][name$="[item_quantity]"]');
    const $tax = $row.find('[name^="field_invoice_items"][name$="[item_vat_rate]"]');

    if (Drupal.inputPattern && typeof Drupal.inputPattern.setValue === 'function') {
      Drupal.inputPattern.setValue($qty, 1);
      Drupal.inputPattern.setValue($tax, p.tax || '');
    } else {
      $qty.val(1);
      $tax.val(p.tax || '');
    }

    $qty.trigger('input');
    $tax.trigger('change');

    Drupal.attachBehaviors($row.get(0));
  }

  /** ----------------------------------------
   *  AJAX lấy thông tin supplies
   * -----------------------------------------*/
  function getSuppliesInfo(data) {
    return $.ajax({
      url: Drupal.url('accountant/invoice-search-items'),
      method: 'POST',
      dataType: 'json',
      data: data
    });
  }

  /** ----------------------------------------
   *  Tăng số lượng nếu sản phẩm đã có
   * -----------------------------------------*/
  function increaseIfExists(label) {
    let updated = false;

    RowFinder.all().each((i, row) => {
      const $row = $(row);
      const $row_value = $row.find('[name^="field_invoice_items"][name$="[item_name]"]').val();

      if ($row_value === label) {
        const $qty = $row.find('[name^="field_invoice_items"][name$="[item_quantity]"]');
        const newQty = Number($qty.val() || 1) + 1;
        if (Drupal.inputPattern && typeof Drupal.inputPattern.setValue === 'function') {
          Drupal.inputPattern.setValue($qty, newQty);
        } else {
          $qty.val(newQty);
        }
        $qty.trigger('input');
        updated = true;
      }
    });

    return updated;
  }

  /** ----------------------------------------
   *  TÌm sản phẩm
   * -----------------------------------------*/
  Drupal.behaviors.invoiceSearch = {
    attach(context) {
      once('invoice-autocomplete', '#edit-choose-item-global', context).forEach(function (element) {
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

          focus: function (event, ui) {
            event.preventDefault();
            $(this).val(ui.item.label + ' (' + ui.item.value + ')');
          },

          select: function (event, ui) {
            event.preventDefault();

            $input.val('');
            $input.blur();
            originalValue = '';

            const product = ui.item;
            if (!product || !product.value) return false;

            if (increaseIfExists(product.label)) return false;
            const $lastRow = RowFinder.last();
            getSuppliesInfo({target_id: product.value}).then((res) => {
              if (!res.success) return;
              UIFill($lastRow, res.data);
            });

            return false;
          }
        });

        $input.on('focus', function () {
          if (RowFinder.hasValue(RowFinder.last())) {
            const $btn = $('[id^="edit-field-invoice-items-add-more"]');
            if ($btn.length) {
              $btn.trigger('mousedown');
            }
          }
          if ($(this).val().trim() == '') {
            setTimeout(() => $input.autocomplete('search', ''), 50);
          }
        });

        $(document).on('mouseleave', 'ul.ui-autocomplete', function () {
          $input.val(originalValue);
        });

      });
    }
  };

  /** ----------------------------------------
   *  Tính toán
   * -----------------------------------------*/
  Drupal.behaviors.invoiceCalculate = {
    attach: function (context, settings) {
      
      $(once('hidenFieldPreOrder', '.invoice-form', context)).each(function () {
        $(this)
          .find(
            'input[name*="field_invoice_amount"],' +
            'input[name*="field_invoice_discount_amount"],' +
            'input[name*="field_invoice_vat_amount"],' +
            'input[name*="field_invoice_total_amount"]'
          )
          .attr('readonly', 'readonly')
          .addClass('bg-secondary bg-opacity-25');
      });
      
      $(once('hidenFieldPreOrder', '[id^="field-invoice-items-values"]', context)).each(function () {
        const $table = $(this);
        const hideColumns = [0, 1, 2, 7, 9, 10, 12, 15];
        $table.find('tr').each(function () {
          hideColumns.forEach(i => {
            $(this).find('th, td').eq(i).addClass('d-none');
          });
        });
        $table.find('th').eq(3).addClass('w-25');
        $table.find('td select[name$="[item_type]"]').removeClass('w-auto').css('width', '100px');
        calculateTotals($(this).find('tbody'));
      });

      $(once('regexDiscount', 'input[name$="[item_discount_rate]"]', context)).each(function () {
        $(this)
          .attr('pattern', '^\\d+(,\\d+)?%?$')
          .on('input', function () {
            this.value = this.value.replace(/[^0-9%,]/g, '').replace(/(.*)%+/g, '$1%');
          })
          .on('keydown', function (event) {
            if (event.key === ' ' || (event.key === '%' && this.value.includes('%'))) {
              event.preventDefault();
            }
          });
      });

      $(once('regexTax', 'input[name$="[item_vat_rate]"]', context)).each(function () {
        $(this)
        .attr({
          min: 0,
          max: 100,
          step: 0.01
        })
        .on('input', function () {
          let value = parseFloat(this.value);
          if (value > 100) this.value = 100;
          if (value < 0) this.value = 0;
        });
      });

      // Tính toán khi thay đổi quantity hoặc price [name^="field_invoice_items"][name$="[item_quantity]
      $(once('invoice_price', 'input[name$="[item_quantity]"], input[name$="[item_price]"], input[name$="[item_discount_rate]"], input[name$="[item_vat_rate]"]', context)).on('input', function () {
        const $row = $(this).closest('tr');

        const price = Number(Drupal.inputPattern.getValue($row.find('input[name$="[item_price]"]'))) || 0;
        const quantity = Number(Drupal.inputPattern.getValue($row.find('input[name$="[item_quantity]"]'))) || 0;
        const vat_rate = Number(Drupal.inputPattern.getValue($row.find('input[name$="[item_vat_rate]"]'))) || 0;
        const discount_rate = $row.find('input[name$="[item_discount_rate]"]').val();

        const numericValue = discount_rate.replace('%', '').replace(',', '.') || 0;

        const amount = price * quantity;
        $row.find('input[name$="[item_amount]"]').val(amount);
        
        // Tính toán chiết khấu.
        let discount_value;
        if (discount_rate.includes('%')) {
          const percentageValue = Math.min(numericValue, 100);
          discount_value = Math.floor((amount * percentageValue) / 100);
        } else {
          discount_value = Math.floor(Math.min(numericValue * quantity, amount));
        }
        $row.find('input[name$="[item_discount_amount]"]').val(discount_value);
        
        const amount_without_vat = amount - discount_value;
        $row.find('input[name$="[item_amount_without_vat]"]').val(amount_without_vat);

        // Tính toán thuế.
        const vat_amount = (amount_without_vat * vat_rate) / 100;
        $row.find('input[name$="[item_vat_amount]"]').val(vat_amount);

        const total_amount = amount_without_vat + vat_amount;
        Drupal.inputPattern.setValue($row.find('input[name$="[item_total_amount]"]'), total_amount);

        calculateTotals($row.closest('tbody'));
      });

      // Hàm tính toán tổng
      function calculateTotals($tbody) {
        let totalAmount = 0;
        let totalDiscount = 0;
        let totalVat = 0;

        $tbody.find('tr').each(function () {
          const $row = $(this);
          const $priceInput = $row.find('input[name$="[item_price]"]');
          const $quantityInput = $row.find('input[name$="[item_quantity]"]');
          const $discountInput = $row.find('input[name*="[item_discount_amount]"]');
          const $vatInput = $row.find('Input[name*="[item_vat_amount]"]');

          // Kiểm tra nếu không có bất kỳ input nào thì bỏ qua dòng này
          if (!$priceInput.length && !$quantityInput.length && !$discountInput.length && !$vatInput.length) {
            return;
          }

          const price = Number(Drupal.inputPattern.getValue($priceInput)) || 0;
          const quantity = Number(Drupal.inputPattern.getValue($quantityInput)) || 0;
          const discount_amount = Number($discountInput.val()) || 0;
          const vat_amount = Number($vatInput.val()) || 0;

          const rowSubtotal = Math.max(price * quantity, 0);

          totalAmount += rowSubtotal;
          totalDiscount += discount_amount;
          totalVat += vat_amount;
        });

        const totalWithoutVat = totalAmount - totalDiscount;
        const totalPayment = totalAmount - totalDiscount + totalVat;

        setValueIfNonZero('input[name*="field_invoice_amount"]', totalAmount);
        setValueIfNonZero('input[name*="field_invoice_discount_amount"]', totalDiscount);
        setValueIfNonZero('input[name*="field_invoice_vat_amount"]', totalVat);
        setValueIfNonZero('input[name*="field_invoice_total_amount"]', totalPayment);
        setValueIfNonZero('input[name*="field_invoice_amount_without_vat"]', totalWithoutVat);
      }

      function setValueIfNonZero(selector, value) {
        const $input = $(selector);
        if ($input.length) {
          if (value !== 0) {
            Drupal.inputPattern.setValue($input, value);
          } else {
            Drupal.inputPattern.setValue($input, 0);
          }
        }
      }
    }
  };

})(jQuery, Drupal, once);
