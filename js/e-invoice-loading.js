/**
 * @file
 * Báo cho người dùng biết trang đang xử lý.
 *
 * Các thao tác của hóa đơn và kho phần lớn là gửi form rồi tải lại trang, có
 * cái chạy vài giây (tạo phiếu kho cho hàng chục hóa đơn, kéo hóa đơn về, phát
 * hành hóa đơn). Không báo gì thì người dùng tưởng chưa bấm trúng và bấm lại —
 * với nút tạo phiếu kho thì lần bấm thứ hai là một phiếu trùng.
 *
 * Nút vừa bấm bị khoá và gắn vòng quay ngay, còn lớp phủ toàn trang chỉ hiện
 * khi việc kéo dài quá DELAY để thao tác nhanh không bị nháy.
 */
(function (Drupal, once) {
  'use strict';

  /**
   * Thời gian chờ trước khi hiện lớp phủ, tính bằng mili giây.
   */
  var DELAY = 250;

  /**
   * Số việc đang chạy; lớp phủ chỉ tắt khi mọi việc đã xong.
   */
  var pending = 0;

  /**
   * Thời gian tối đa giữ lớp phủ, tính bằng mili giây.
   */
  var TIMEOUT = 30000;

  /**
   * Bộ đếm giờ của lần hiện lớp phủ đang chờ.
   */
  var timer = null;

  /**
   * Bộ đếm giờ của chốt chặn tự mở khoá.
   */
  var guard = null;

  /**
   * Lớp phủ dùng chung, dựng một lần rồi giữ lại.
   */
  function overlay() {
    var element = document.getElementById('e-invoice-loading');

    if (element) {
      return element;
    }

    element = document.createElement('div');
    element.id = 'e-invoice-loading';
    element.className = 'e-invoice-loading';
    element.setAttribute('role', 'status');
    element.setAttribute('aria-live', 'polite');
    element.innerHTML = '<div class="e-invoice-loading__box">'
      + '<div class="spinner-border text-primary" aria-hidden="true"></div>'
      + '<span class="e-invoice-loading__text"></span>'
      + '</div>';

    document.body.appendChild(element);

    return element;
  }

  /**
   * Bắt đầu một việc: khoá màn hình sau DELAY.
   *
   * @param {string} message
   *   Dòng chữ hiện kèm vòng quay.
   * @param {boolean} immediate
   *   Hiện ngay thay vì chờ DELAY. Dùng cho việc chạy đồng bộ ngay trên trình
   *   duyệt: nó chiếm luôn luồng chính nên hẹn giờ không chạy được, chờ xong
   *   mới hiện thì lớp phủ chỉ loé lên đúng lúc không còn cần nữa.
   */
  function show(message, immediate) {
    pending++;

    var element = overlay();
    element.querySelector('.e-invoice-loading__text').textContent = message
      || Drupal.t('Processing, please wait...');

    if (immediate) {
      element.classList.add('is-visible');
    }

    if (timer === null && !immediate) {
      timer = window.setTimeout(function () {
        timer = null;

        // Việc có thể đã xong trong lúc chờ, lúc đó khỏi hiện nữa.
        if (pending > 0) {
          overlay().classList.add('is-visible');
        }
      }, DELAY);
    }

    // Chốt chặn cuối: đoán sai một thao tác là người dùng ngồi nhìn lớp phủ
    // không bao giờ tắt, thà tự mở khoá còn hơn khoá nhầm cả trang.
    window.clearTimeout(guard);
    guard = window.setTimeout(reset, TIMEOUT);
  }

  /**
   * Kết thúc một việc.
   */
  function hide() {
    pending = Math.max(0, pending - 1);

    if (pending > 0) {
      return;
    }

    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }

    window.clearTimeout(guard);
    overlay().classList.remove('is-visible');
  }

  /**
   * Bỏ mọi việc đang đếm, dùng khi quay lại trang từ bộ nhớ đệm trình duyệt.
   */
  function reset() {
    pending = 0;

    if (timer !== null) {
      window.clearTimeout(timer);
      timer = null;
    }

    window.clearTimeout(guard);

    overlay().classList.remove('is-visible');

    document.querySelectorAll('.is-loading').forEach(function (button) {
      button.classList.remove('is-loading');
      button.disabled = false;
    });
  }

  /**
   * Khoá nút vừa bấm và gắn vòng quay vào trước nhãn.
   *
   * @param {Element} button
   *   Nút hoặc liên kết vừa bấm.
   */
  function busy(button) {
    if (!button || button.classList.contains('is-loading')) {
      return;
    }

    // Cả hai việc đều lùi lại một nhịp vì hàm này chạy ở pha bắt, tức là trước
    // khi trình duyệt kịp làm việc của cú bấm: khoá nút thì nút không gửi kèm
    // giá trị (form đối chiếu phân biệt "Tạo phiếu" với "Không nhập" bằng chính
    // giá trị đó), còn gắn lớp cho liên kết thì pointer-events chặn luôn cú bấm.
    window.setTimeout(function () {
      button.classList.add('is-loading');

      // Liên kết không có thuộc tính disabled, CSS lo phần chặn bấm tiếp.
      if (button.tagName === 'BUTTON' || button.tagName === 'INPUT') {
        button.disabled = true;
      }
    }, 0);
  }

  /**
   * Các nút tự chuyển trang bằng JS thay vì gửi form.
   *
   * Cố tình liệt kê từng cái chứ không bắt mọi nút: nút chỉ đổi dữ liệu ngay
   * trên trang (xuất Excel, ẩn hiện cột, mở hộp thoại) mà bị khoá màn hình thì
   * lớp phủ không bao giờ tắt vì chẳng có trang mới nào tải về.
   */
  var NAVIGATING_BUTTONS = '.btn-search-date, .btn-import, .btn-export, .btn-items-multiple';

  /**
   * Liên kết này có rời trang không.
   *
   * @param {Element} element
   *   Liên kết vừa bấm.
   */
  function navigates(element) {
    if (element.hasAttribute('data-bs-toggle') || element.hasAttribute('data-bs-dismiss')) {
      return false;
    }

    var href = element.getAttribute('href');

    if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
      return false;
    }

    // Liên kết mở tab mới để trang hiện tại nguyên vẹn, và use-ajax do Drupal
    // xử lý qua sự kiện ajax bên dưới.
    return element.target !== '_blank' && !element.classList.contains('use-ajax');
  }

  Drupal.eInvoiceLoading = {
    show: show,
    hide: hide,
    reset: reset,
    busy: busy
  };

  Drupal.behaviors.eInvoiceLoading = {
    attach: function (context) {
      once('e-invoice-loading', 'body', context).forEach(function (body) {
        // Gửi form là rời trang, trừ form lọc chạy bằng JS đã tự chuyển hướng.
        body.addEventListener('submit', function (event) {
          var form = event.target;

          if (form.hasAttribute('data-no-loading')) {
            return;
          }

          busy(event.submitter);
          show(form.dataset.loadingMessage);
        }, true);

        // Liên kết dẫn sang trang khác.
        body.addEventListener('click', function (event) {
          var link = event.target.closest('a.btn, a.dropdown-item');

          if (link && navigates(link)) {
            busy(link);
            show(link.dataset.loadingMessage);
            return;
          }

          // Nút tự chuyển trang bằng JS: sự kiện submit không bắt được vì form
          // được gọi bằng form.submit(), vốn không kích hoạt trình xử lý nào.
          var button = event.target.closest(NAVIGATING_BUTTONS);

          if (button && !button.disabled) {
            busy(button);
            show(button.dataset.loadingMessage);
          }
        }, true);

        // Chuyển trang bằng phím quay lại: trang lấy từ bộ nhớ đệm còn nguyên
        // lớp phủ của lần rời đi.
        window.addEventListener('pageshow', reset);
      });

      // Yêu cầu ajax của Drupal (hộp thoại chi tiết, nạp lại ô chọn kỳ) và các
      // lệnh gọi jQuery khác trong module.
      if (window.jQuery) {
        once('e-invoice-loading-ajax', 'html').forEach(function () {
          window.jQuery(document)
            .on('ajaxSend', function () {
              show();
            })
            .on('ajaxComplete', function () {
              hide();
            });
        });
      }
    }
  };
})(Drupal, once);
