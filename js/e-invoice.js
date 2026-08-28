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
          $modal.find('.invoice-selected-info').addClass('d-none');
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
        return $('input[name="select-invoice"]').filter(function () {
          return !$(this).closest('tr').hasClass('d-none');
        });
      }

      // Dòng đã tích ở mọi trang, vì thao tác hàng loạt chạy trên cả bảng.
      function selectedUuids() {
        return $('input[name="select-invoice"]:checked').map(function () {
          return this.value;
        }).get();
      }

      // Đồng bộ ô chọn tất cả, số hóa đơn đang chọn và các nút thao tác hàng loạt.
      function refreshSelection() {
        const $visible = visibleInvoices();
        const total = selectedUuids().length;

        $('#select-invoice-all').prop(
          'checked',
          $visible.length > 0 && $visible.filter(':checked').length === $visible.length
        );

        $('.invoice-selected-count').text(total);
        $('.btn-file-multiple, .btn-accountant-multiple, .btn-items-multiple').prop('disabled', total === 0);
      }

      // Checkbox tất cả
      once('select-invoice-all', '#select-invoice-all', context).forEach(function (element) {

        $(element).on('change', function () {
          const isChecked = $(this).is(':checked');

          visibleInvoices().prop('checked', isChecked);
          refreshSelection();
        });
      });

      // Checkbox từng dòng
      once('select-invoice-item', '.select-invoice', context).forEach(function (element) {
        $(element).on('change', refreshSelection);
      });

      // Đổi trang chỉ ẩn hiện dòng nên phải tính lại ô chọn tất cả của trang mới.
      once('select-invoice-paging', '.invoice-pagination, .invoice-page-size', context).forEach(function (element) {
        $(element).on('click change', function () {
          window.setTimeout(refreshSelection, 0);
        });
      });

      // Tải file của nhiều hóa đơn.
      once('file-multiple-modal', '.btn-file-multiple', context).forEach(function (btn) {
        $(btn).on('click', function () {
          $('#fileInvoiceModal').find('#file-invoice-uuid').val(selectedUuids().join(','));
        });
      });

      // Hạch toán nhiều hóa đơn.
      once('accountant-multiple-modal', '.btn-accountant-multiple', context).forEach(function (btn) {
        $(btn).on('click', function () {
          const $modal = $('#uuidInvoiceModal');

          $modal.find('#invoice-uuid').val(selectedUuids().join(','));
          $modal.find('.invoice-selected-info').removeClass('d-none');
        });
      });

      // Nhập / xuất kho nhiều hóa đơn: gửi danh sách uuid sang form đối chiếu.
      // Gửi bằng POST vì danh sách uuid dài hơn giới hạn của đường dẫn.
      once('items-multiple', '.btn-items-multiple', context).forEach(function (btn) {
        $(btn).on('click', function () {
          const uuids = selectedUuids();

          if (!uuids.length) {
            return;
          }

          const form = $('.form-items-multiple');
          form.find('.input-items-uuid').val(uuids.join(','));
          form.get(0).submit();
        });
      });

      // Đổi kỳ tồn đầu kỳ thì điền lại hai ô ngày theo khoảng ngày của kỳ, sau
      // đó kế toán tự thu hẹp để xem phát sinh của từng đoạn trong kỳ. Không
      // điền thì ô ngày còn giữ khoảng của kỳ cũ và lọc ra đúng kỳ vừa bỏ.
      once('warehouse-period', '.warehouse-period-select', context).forEach(function (select) {
        select.addEventListener('change', function () {
          const option = this.options[this.selectedIndex];
          const start = document.getElementById('start-date');
          const end = document.getElementById('end-date');

          if (!option || !start || !end) {
            return;
          }

          [start, end].forEach(function (input) {
            input.min = option.dataset.start || '';
            input.max = option.dataset.end || '';
          });

          // "Khoảng ngày tự chọn" không có mốc nào để điền, giữ nguyên ngày
          // đang nhập dở.
          if (!this.value) {
            return;
          }

          start.value = option.dataset.start || '';
          end.value = option.dataset.end || '';
        });
      });

      once('select-invoice-init', '.invoice-table', context).forEach(refreshSelection);
    }
  };
})(jQuery, Drupal, once);
