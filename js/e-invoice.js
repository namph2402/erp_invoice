(function ($, Drupal, once) {
  Drupal.behaviors.invoiceModal = {
    attach: function (context) {
      // Tìm kiếm hóa đơn theo bộ lọc.
      once('search-invoice', '.list-invoice .header-wrapper', context).forEach(function (wrapper) {

        const form = wrapper.querySelector('form');
        form.addEventListener('submit', function () {
          const url = new URL(window.location.href);
          const params = new URLSearchParams(new FormData(form));

          params.delete('search_date');

          url.search = params.toString();
          window.location.href = url.toString();
        });

        wrapper.querySelectorAll('.btn-search-date').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const url = new URL(window.location.href);
            const params = new URLSearchParams(url.search);

            params.set('search_date', this.dataset.searchDate);
            params.delete('start_date');
            params.delete('end_date');
            params.delete('import');

            url.search = params.toString();
            window.location.href = url.toString();
          });
        });

        wrapper.querySelectorAll('.btn-import').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const url = new URL(window.location.href);
            const params = new URLSearchParams(url.search);
            params.set('import', 'true');
            params.delete('search_date');
            params.delete('start_date');
            params.delete('end_date');
            params.delete('page');
            url.search = params.toString();
            window.location.href = url.toString();
          });
        });

        wrapper.querySelectorAll('.btn-export').forEach(function (btn) {
          btn.addEventListener('click', function () {
            const url = new URL(window.location.href);
            const params = new URLSearchParams(url.search);
            params.set('export', 'true');
            params.delete('search_date');
            params.delete('start_date');
            params.delete('end_date');
            params.delete('page');
            url.search = params.toString();
            window.location.href = url.toString();
          });
        });
      });

      // Gán uuid hóa đơn vào input.
      once('uuid-invoice-modal', '.btn-uuid-invoice', context).forEach(function (btn) {
        $(btn).on('click', function () {
          const $modal = $('#uuidInvoiceModal');
          const input = $modal.find('#invoice-uuid');
          input.val($(this).data('invoice-uuid'));
        });
      });

      // Gán dữ liệu thanh toán của hóa đơn vào form.
      once('payment-invoice-modal', '.btn-payment-invoice', context).forEach(function (btn) {
        $(btn).on('click', function () {
          const $modal = $('#paymentInvoiceModal');
          const data = $(this).data();

          $modal.find('#payment-invoice-uuid').val(data.invoiceUuid);
          $modal.find('#payment-due-date').val(data.paymentDueDate || '');
          $modal.find('#payment-amount').val(data.paymentAmount || 0);
          $modal.find('#payment-amount-not').val(data.paymentAmountNot || '');
          $modal.find('#payment-status').val(data.paymentStatus || 0);
        });
      });

      // Lấy mẫu hóa đơn.
      once('config-invoice-modal', '.btn-config-invoice', context).forEach(function (btn) {
        $(btn).on('click', function () {
          const $modal = $('#configInvoiceModal');

          const company_id = $(this).data('company-id');

          const inputUuid = $modal.find('#invoice-uuid');
          inputUuid.val($(this).data('invoice-uuid'));

          const inputFile = $modal.find('#invoice-get-file');
          inputFile.val("TRUE");

          const form = $modal.find('#config-form');
          form.attr('action', $(this).data('routing'));

          const $select = $modal.find('#template-value');
          $select.prop('disabled', true);
          $select.html(`<option value="">${Drupal.t("Loading")}...</option>`);
          
          const $companySelect = $modal.find('#company_template');
          $companySelect.val(company_id);
          
          $('#template-message').text("");

          $.ajax({
            url: Drupal.url('accountant/e-invoice-tempaltes/' + company_id),
            type: 'GET',
            dataType: 'json',
            success: function (response) {
              $select.empty();
              $select.append(`<option value="">${Drupal.t("Select invoice template")}</option>`);

              if (response["success"]) {
                response["data"].forEach(function (item) {
                  $select.append(
                    $('<option>', {
                      value: JSON.stringify(item),
                      text: item.name + "(" + item.serial + ")"
                    })
                  );
                });
              } else {
                $select.html(`<option value="">${Drupal.t("There is no invoice template.")}</option>`);
                $('#template-message').text(response["message"] || Drupal.t("Invoice sampling error"));
              }
              $select.prop('disabled', false);
            },
            error: function () {
              $select.html(`<option value="">${Drupal.t("There is no invoice template.")}</option>`);
              $('#template-message').text(Drupal.t("Undefined error"));
            }
          });
        });
      });

      // Phát hành nhiều hóa đơn.
      once('issue-multiple-modal', '.btn-issue-multiple', context).forEach(function (btn) {
        $(btn).on('click', function () {
          const $modal = $('#configInvoiceModal');

          const company_id = $(this).data('company-id');

          const selectedIds = [];

          $('input[name="select-invoice"]:checked').each(function () {
            selectedIds.push($(this).val());
          });

          const inputUuid = $modal.find('#invoice-uuid');
          inputUuid.val(selectedIds.join(','));

          const inputFile = $modal.find('#invoice-get-file');
          inputFile.val("FALSE");

          const form = $modal.find('#config-form');
          form.attr('action', $(this).data('routing'));

          const $select = $modal.find('#template-value');
          $select.prop('disabled', true);
          $select.html(`<option value="">${Drupal.t("Loading")}...</option>`);
          
          const $companySelect = $modal.find('#company_template');
          $companySelect.val(company_id);
          
          $('#template-message').text("");

          $.ajax({
            url: Drupal.url('accountant/e-invoice-tempaltes/' + company_id),
            type: 'GET',
            dataType: 'json',
            success: function (response) {
              $select.empty();
              $select.append(`<option value="">${Drupal.t("Select invoice template")}</option>`);

              if (response["success"]) {
                response["data"].forEach(function (item) {
                  $select.append(
                    $('<option>', {
                      value: JSON.stringify(item),
                      text: item.name + "(" + item.serial + ")"
                    })
                  );
                });
              } else {
                $select.html(`<option value="">${Drupal.t("There is no invoice template.")}</option>`);
                $('#template-message').text(response["message"] || Drupal.t("Invoice sampling error"));
              }
              $select.prop('disabled', false);
            },
            error: function () {
              $select.html(`<option value="">${Drupal.t("There is no invoice template.")}</option>`);
              $('#template-message').text(Drupal.t("Undefined error"));
            }
          });
        });
      });

      // Bảng nạp sẵn mọi hóa đơn khớp bộ lọc rồi mới chia trang, nên chọn tất cả
      // chỉ tính các dòng của trang đang xem như khi máy chủ còn tự chia trang.
      function visibleInvoices() {
        return $('input[name="select-invoice"]', context).filter(function () {
          return !$(this).closest('tr').hasClass('d-none');
        });
      }

      // Checkbox tất cả
      once('select-invoice-all', '#select-invoice-all', context).forEach(function (element) {

        $(element).on('change', function () {
          const isChecked = $(this).is(':checked');

          visibleInvoices().prop('checked', isChecked);
        });
      });

      // Checkbox từng dòng
      once('select-invoice-item', '.select-invoice', context).forEach(function (element) {
        $(element).on('change', function () {
          const $visible = visibleInvoices();
          const checked = $visible.filter(':checked').length;
          $('#select-invoice-all', context).prop('checked', $visible.length > 0 && $visible.length === checked);
        });
      });
    }
  };
})(jQuery, Drupal, once);
