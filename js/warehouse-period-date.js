/**
 * @file
 * Chỉ gọi AJAX cho ô ngày khi kế toán đã nhập xong.
 */

(function ($, Drupal, once) {
  "use strict";

  /**
   * Ô date của HTML5 bắn change ngay khi từng phần ngày vừa đủ hợp lệ, nên gõ
   * dở dang đã kéo theo một lượt dựng lại bảng. Chỉ nhận ngày khi con trỏ rời
   * khỏi ô và giá trị khác lúc bắt đầu sửa.
   */
  Drupal.behaviors.warehousePeriodDate = {
    attach: function (context) {
      once(
        "warehouse-period-date",
        '.warehouse-period-form input[type="date"]',
        context
      ).forEach(function (input) {
        var previous = input.value;

        input.addEventListener("focus", function () {
          previous = input.value;
        });

        input.addEventListener("blur", function () {
          if (input.value === previous) {
            return;
          }

          previous = input.value;
          $(input).trigger("warehousePeriodDate");
        });
      });
    },
  };
})(jQuery, Drupal, once);
