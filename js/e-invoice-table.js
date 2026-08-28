/**
 * @file
 * Bảng danh sách hóa đơn: phân trang, ẩn hiện cột, sắp xếp và xuất Excel.
 *
 * Máy chủ trả về toàn bộ hóa đơn khớp bộ lọc, nên phân trang chạy ngay trên
 * trình duyệt: mỗi trang chỉ hiện số dòng người dùng chọn, không gọi lại máy chủ.
 * Số dòng mỗi trang và cột ẩn hiện lưu ở localStorage theo từng bảng.
 * Sắp xếp cũng chạy trên trình duyệt với toàn bộ dòng của bảng.
 * File Excel xuất ra gồm mọi dòng (kể cả trang khác) theo các cột đang hiển thị.
 */
(function (Drupal, once) {
  'use strict';

  var STORAGE_PREFIX = 'erp_e_invoice.columns.';
  var PAGE_SIZE_PREFIX = 'erp_e_invoice.page-size.';
  // Số trang hiện hai bên trang đang xem, phần còn lại rút gọn bằng dấu …
  var PAGE_WINDOW = 2;

  /**
   * Mô tả các cột của bảng theo thứ tự trong thead.
   */
  function getColumns(table) {
    var ths = table.querySelectorAll('thead tr:first-child th');

    return Array.prototype.map.call(ths, function (th, index) {
      var label = (th.dataset.colLabel || th.textContent || '').trim();

      return {
        index: index,
        th: th,
        label: label || Drupal.t('Column @number', { '@number': index + 1 }),
        key: th.dataset.colKey || label || 'col-' + index,
        lock: th.dataset.colLock === '1',
        exportable: th.dataset.colExport !== '0'
      };
    });
  }

  function readHidden(key) {
    try {
      var raw = window.localStorage.getItem(STORAGE_PREFIX + key);
      var list = raw ? JSON.parse(raw) : [];

      return Array.isArray(list) ? list : [];
    }
    catch (e) {
      return [];
    }
  }

  function saveHidden(key, list) {
    try {
      window.localStorage.setItem(STORAGE_PREFIX + key, JSON.stringify(list));
    }
    catch (e) {
      // Trình duyệt chặn localStorage thì chỉ mất phần ghi nhớ, không chặn thao tác.
    }
  }

  /**
   * Đổi phần đã lưu thành danh sách tên cột đang ẩn.
   *
   * Bản lưu cũ chứa số thứ tự cột nên vẫn phải nhận, cột đã bỏ khỏi bảng hoặc
   * cột khoá thì loại ra.
   */
  function toHiddenKeys(list, columns) {
    var keys = [];

    columns.forEach(function (column) {
      if (column.lock) {
        return;
      }

      var stored = list.indexOf(column.key) !== -1 || list.indexOf(column.index) !== -1;

      if (stored && keys.indexOf(column.key) === -1) {
        keys.push(column.key);
      }
    });

    return keys;
  }

  /**
   * Số thứ tự các cột đang ẩn, dùng để thao tác trên ô của bảng.
   */
  function toHiddenIndexes(keys, columns) {
    return columns.filter(function (column) {
      return !column.lock && keys.indexOf(column.key) !== -1;
    }).map(function (column) {
      return column.index;
    });
  }

  /**
   * Ẩn hiện cột trên toàn bộ dòng của bảng.
   */
  function applyColumns(table, hidden) {
    table.querySelectorAll('tr').forEach(function (row) {
      Array.prototype.forEach.call(row.cells, function (cell, index) {
        cell.classList.toggle('d-none', hidden.indexOf(index) !== -1);
      });
    });
  }

  /**
   * Ẩn hiện cột theo đúng phần đã lưu của bảng.
   */
  function restoreColumns(table, key) {
    var columns = getColumns(table);

    applyColumns(table, toHiddenIndexes(toHiddenKeys(readHidden(key), columns), columns));
  }

  /**
   * Dựng danh sách checkbox chọn cột.
   *
   * Mỗi lần tích bỏ tích là ghi lại localStorage theo từng bảng, nên lần vào
   * sau các cột đã tắt vẫn giữ nguyên.
   */
  function buildColumnMenu(table, menu, key) {
    var columns = getColumns(table);
    var hidden = toHiddenKeys(readHidden(key), columns);

    function apply() {
      saveHidden(key, hidden);
      applyColumns(table, toHiddenIndexes(hidden, columns));
    }

    menu.innerHTML = '';

    columns.forEach(function (column) {
      if (column.lock) {
        return;
      }

      var item = document.createElement('li');
      var label = document.createElement('label');
      var checkbox = document.createElement('input');
      var text = document.createElement('span');

      label.className = 'dropdown-item d-flex align-items-center gap-2 py-1 mb-0';
      checkbox.type = 'checkbox';
      checkbox.className = 'form-check-input m-0 flex-shrink-0';
      checkbox.checked = hidden.indexOf(column.key) === -1;
      text.textContent = column.label;

      checkbox.addEventListener('change', function () {
        hidden = hidden.filter(function (item_key) {
          return item_key !== column.key;
        });

        if (!this.checked) {
          hidden.push(column.key);
        }

        apply();
      });

      label.appendChild(checkbox);
      label.appendChild(text);
      item.appendChild(label);
      menu.appendChild(item);
    });

    var separator = document.createElement('li');
    separator.innerHTML = '<hr class="dropdown-divider">';
    menu.appendChild(separator);

    var reset = document.createElement('li');
    var resetButton = document.createElement('button');
    resetButton.type = 'button';
    resetButton.className = 'dropdown-item text-primary';
    resetButton.textContent = Drupal.t('Show all columns');
    resetButton.addEventListener('click', function () {
      hidden = [];
      apply();
      menu.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
        input.checked = true;
      });
    });
    reset.appendChild(resetButton);
    menu.appendChild(reset);

    applyColumns(table, toHiddenIndexes(hidden, columns));
  }

  /* ------------------------------------------------------------------------
   * Sắp xếp theo cột ngay trên trình duyệt.
   * --------------------------------------------------------------------- */

  var DATE_PATTERN = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/;

  /**
   * Đổi chuỗi số kiểu Việt Nam ("1.234.567", "1.234,5") thành số.
   */
  function toNumber(text) {
    if (typeof text === 'number') {
      return text;
    }

    if (!/\d/.test(text) || !/^-?[\d.,\s]+$/.test(text)) {
      return null;
    }

    var value = Number(text.replace(/\s/g, '').replace(/\./g, '').replace(',', '.'));

    return isNaN(value) ? null : value;
  }

  function toDate(text) {
    var parts = typeof text === 'string' ? text.match(DATE_PATTERN) : null;

    if (!parts) {
      return null;
    }

    return Date.UTC(Number(parts[3]), Number(parts[2]) - 1, Number(parts[1]));
  }

  /**
   * Giá trị dùng để so sánh: ưu tiên data-value (giữ số thô cho ô tiền tệ).
   */
  function getSortValue(cell) {
    if (!cell) {
      return '';
    }

    var raw = cell.dataset.value;

    if (raw !== undefined && raw !== '' && !isNaN(Number(raw))) {
      return Number(raw);
    }

    return (cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim();
  }

  /**
   * Cả cột là số, là ngày hay là chữ.
   */
  function detectKind(values) {
    var kind = null;

    for (var i = 0; i < values.length; i++) {
      var value = values[i];

      if (value === '' || value === null) {
        continue;
      }

      var current = toDate(value) !== null ? 'date' : (toNumber(value) !== null ? 'number' : 'text');

      if (kind === null) {
        kind = current;
      }
      else if (kind !== current) {
        return 'text';
      }
    }

    return kind || 'text';
  }

  function toSortKey(value, kind) {
    if (value === '' || value === null) {
      return null;
    }

    if (kind === 'date') {
      return toDate(value);
    }

    if (kind === 'number') {
      return toNumber(value);
    }

    return String(value).toLowerCase();
  }

  /**
   * Đánh lại số thứ tự sau khi đổi thứ tự dòng.
   */
  function renumber(table) {
    var columns = getColumns(table).filter(function (column) {
      return column.th.dataset.colIndex === '1';
    });

    if (!columns.length || !table.tBodies[0]) {
      return;
    }

    Array.prototype.forEach.call(table.tBodies[0].rows, function (row, position) {
      columns.forEach(function (column) {
        var cell = row.cells[column.index];

        if (cell) {
          cell.textContent = position + 1;
          cell.dataset.value = position + 1;
        }
      });
    });
  }

  function sortTable(table, columnIndex, direction) {
    var tbody = table.tBodies[0];

    if (!tbody) {
      return;
    }

    var entries = Array.prototype.map.call(tbody.rows, function (row, position) {
      return {
        row: row,
        // Thứ tự ban đầu do máy chủ trả về, dùng để giữ ổn định và để bỏ sắp xếp.
        origin: Number(row.dataset.rowOrder || position),
        value: direction ? getSortValue(row.cells[columnIndex]) : ''
      };
    });

    if (direction) {
      var kind = detectKind(entries.map(function (entry) {
        return entry.value;
      }));

      entries.forEach(function (entry) {
        entry.key = toSortKey(entry.value, kind);
      });

      entries.sort(function (a, b) {
        // Ô rỗng luôn nằm cuối bảng dù sắp xếp tăng hay giảm.
        if (a.key === null || b.key === null) {
          if (a.key === b.key) {
            return a.origin - b.origin;
          }

          return a.key === null ? 1 : -1;
        }

        var result = kind === 'text' ? a.key.localeCompare(b.key, 'vi') : a.key - b.key;

        if (result === 0) {
          return a.origin - b.origin;
        }

        return direction === 'desc' ? -result : result;
      });
    }
    else {
      entries.sort(function (a, b) {
        return a.origin - b.origin;
      });
    }

    var fragment = document.createDocumentFragment();

    entries.forEach(function (entry) {
      fragment.appendChild(entry.row);
    });

    tbody.appendChild(fragment);
    renumber(table);
  }

  /**
   * Gắn nút sắp xếp lên tiêu đề cột: tăng dần > giảm dần > bỏ sắp xếp.
   *
   * @param {HTMLTableElement} table
   *   Bảng danh sách.
   * @param {Function} onSort
   *   Gọi lại sau khi đổi thứ tự dòng, dùng để dựng lại phân trang.
   */
  function setupSort(table, onSort) {
    var tbody = table.tBodies[0];

    if (!tbody) {
      return;
    }

    Array.prototype.forEach.call(tbody.rows, function (row, position) {
      row.dataset.rowOrder = position;
    });

    getColumns(table).forEach(function (column) {
      var th = column.th;

      if (th.dataset.colSort === '0') {
        return;
      }

      var icon = document.createElement('i');

      icon.className = 'bi bi-arrow-down-up invoice-sort-icon';
      th.classList.add('invoice-sortable');
      th.setAttribute('aria-sort', 'none');
      th.appendChild(icon);

      th.addEventListener('click', function () {
        var direction = th.dataset.sortDirection === 'asc' ? 'desc'
          : (th.dataset.sortDirection === 'desc' ? '' : 'asc');

        // Mỗi lần chỉ sắp xếp theo một cột.
        table.querySelectorAll('thead th.invoice-sortable').forEach(function (other) {
          if (other !== th) {
            delete other.dataset.sortDirection;
            other.setAttribute('aria-sort', 'none');
            other.querySelector('.invoice-sort-icon').className = 'bi bi-arrow-down-up invoice-sort-icon';
          }
        });

        if (direction) {
          th.dataset.sortDirection = direction;
        }
        else {
          delete th.dataset.sortDirection;
        }

        th.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : (direction === 'desc' ? 'descending' : 'none'));
        icon.className = 'bi invoice-sort-icon '
          + (direction === 'asc' ? 'bi-sort-down-alt' : (direction === 'desc' ? 'bi-sort-down' : 'bi-arrow-down-up'));

        sortTable(table, column.index, direction);

        if (onSort) {
          onSort();
        }
      });
    });
  }

  /* ------------------------------------------------------------------------
   * Phân trang trên trình duyệt.
   * --------------------------------------------------------------------- */

  function readPageSize(key, fallback) {
    try {
      var raw = window.localStorage.getItem(PAGE_SIZE_PREFIX + key);
      var size = raw === null ? NaN : Number(raw);

      return isNaN(size) || size < 0 ? fallback : size;
    }
    catch (e) {
      return fallback;
    }
  }

  function savePageSize(key, size) {
    try {
      window.localStorage.setItem(PAGE_SIZE_PREFIX + key, String(size));
    }
    catch (e) {
      // Trình duyệt chặn localStorage thì chỉ mất phần ghi nhớ, không chặn thao tác.
    }
  }

  function formatNumber(value) {
    return value.toLocaleString('vi-VN');
  }

  /**
   * Các số trang cần vẽ, phần bị lược bỏ trả về null (dấu …).
   */
  function getPageItems(current, count) {
    var items = [];
    var previous = 0;

    for (var page = 1; page <= count; page++) {
      var keep = page === 1
        || page === count
        || Math.abs(page - current) <= PAGE_WINDOW;

      if (!keep) {
        continue;
      }

      if (previous && page - previous > 1) {
        items.push(null);
      }

      items.push(page);
      previous = page;
    }

    return items;
  }

  function createPageItem(label, page, options) {
    var item = document.createElement('li');
    var button = document.createElement(page === null ? 'span' : 'button');

    item.className = 'page-item'
      + (options.disabled ? ' disabled' : '')
      + (options.active ? ' active' : '');
    button.className = 'page-link';
    button.textContent = label;

    if (page === null) {
      item.classList.add('disabled');
    }
    else {
      button.type = 'button';
      button.disabled = !!options.disabled;
      button.addEventListener('click', function () {
        options.go(page);
      });
    }

    if (options.active) {
      item.setAttribute('aria-current', 'page');
    }

    item.appendChild(button);

    return item;
  }

  /**
   * Chia trang cho các dòng đang có sẵn trong bảng.
   *
   * @return {Object|null}
   *   Đối tượng có reset() để dựng lại phân trang sau khi đổi thứ tự dòng.
   */
  function setupPagination(table, scope, key) {
    var tbody = table.tBodies[0];

    if (!tbody) {
      return null;
    }

    var select = scope.querySelector('.invoice-page-size');
    var pages = scope.querySelector('.invoice-pages');
    var range = scope.querySelector('.invoice-range');
    var nav = pages ? pages.closest('.invoice-pagination') : null;

    // Người dùng chọn 0 nghĩa là xem hết, không chia trang.
    var fallback = select ? Number(select.value) || 0 : 0;
    var size = readPageSize(key, fallback);
    var current = 1;

    if (select) {
      var hasOption = Array.prototype.some.call(select.options, function (option) {
        return Number(option.value) === size;
      });

      if (hasOption) {
        select.value = String(size);
      }
      else {
        size = fallback;
      }
    }

    function renderRange(total, start, end) {
      if (!range) {
        return;
      }

      range.textContent = total
        ? Drupal.t('Showing @from - @to / @total', {
          '@from': formatNumber(start + 1),
          '@to': formatNumber(end),
          '@total': formatNumber(total)
        })
        : Drupal.t('No data');
    }

    function renderPages(count) {
      if (!pages) {
        return;
      }

      pages.innerHTML = '';

      if (nav) {
        nav.classList.toggle('d-none', count < 2);
      }

      if (count < 2) {
        return;
      }

      pages.appendChild(createPageItem('«', current - 1, {
        disabled: current === 1,
        go: go
      }));

      getPageItems(current, count).forEach(function (page) {
        pages.appendChild(page === null
          ? createPageItem('…', null, {})
          : createPageItem(formatNumber(page), page, {
            active: page === current,
            go: go
          }));
      });

      pages.appendChild(createPageItem('»', current + 1, {
        disabled: current === count,
        go: go
      }));
    }

    function render() {
      var rows = tbody.rows;
      var total = rows.length;
      var step = size > 0 ? size : total;
      var count = step > 0 ? Math.ceil(total / step) : 1;

      current = Math.min(Math.max(current, 1), Math.max(count, 1));

      var start = step > 0 ? (current - 1) * step : 0;
      var end = step > 0 ? Math.min(start + step, total) : total;

      Array.prototype.forEach.call(rows, function (row, position) {
        row.classList.toggle('d-none', position < start || position >= end);
      });

      renderRange(total, start, end);
      renderPages(count);
    }

    function go(page) {
      current = page;
      render();

      // Đổi trang thì xem lại từ đầu bảng.
      var wrapper = table.closest('.invoice-table-wrapper');

      if (wrapper) {
        wrapper.scrollTop = 0;
      }
    }

    if (select) {
      select.addEventListener('change', function () {
        size = Number(this.value) || 0;
        savePageSize(key, size);
        go(1);
      });
    }

    render();

    return {
      reset: function () {
        go(1);
      }
    };
  }

  /**
   * Dòng tiêu đề của file xuất: tên báo cáo và kỳ số liệu.
   *
   * Người nhận file thường không biết bảng được lọc theo khoảng ngày nào, nên
   * kỳ phải nằm trong chính file chứ không chỉ trên màn hình.
   */
  function getExportMeta(table) {
    var lines = [table.dataset.exportTitle, table.dataset.exportPeriod].filter(function (line) {
      return line;
    });

    if (!lines.length) {
      return [];
    }

    var rows = lines.map(function (line) {
      return [{ value: line, number: false }];
    });

    // Dòng trống ngăn phần tiêu đề với phần bảng.
    rows.push([{ value: '', number: false }]);

    return rows;
  }

  /**
   * Lấy dữ liệu bảng theo đúng các cột đang hiển thị.
   */
  function getExportRows(table) {
    var columns = getColumns(table).filter(function (column) {
      return column.exportable && !column.th.classList.contains('d-none');
    });

    var rows = [columns.map(function (column) {
      return { value: column.label, number: false };
    })];

    table.querySelectorAll('tbody tr, tfoot tr').forEach(function (row) {
      rows.push(columns.map(function (column) {
        var cell = row.cells[column.index];

        if (!cell) {
          return { value: '', number: false };
        }

        // Ô tiền tệ giữ giá trị thô để Excel tính toán được.
        var raw = cell.dataset.value;
        if (raw !== undefined && raw !== '' && !isNaN(Number(raw))) {
          return { value: Number(raw), number: true };
        }

        return {
          value: (cell.innerText || cell.textContent || '').replace(/\s+/g, ' ').trim(),
          number: false
        };
      }));
    });

    return rows;
  }

  /* ------------------------------------------------------------------------
   * Ghi file xlsx (OOXML) trực tiếp trên trình duyệt, không cần thư viện ngoài.
   * --------------------------------------------------------------------- */

  var CRC_TABLE = (function () {
    var table = new Uint32Array(256);

    for (var i = 0; i < 256; i++) {
      var value = i;
      for (var bit = 0; bit < 8; bit++) {
        value = value & 1 ? 0xEDB88320 ^ (value >>> 1) : value >>> 1;
      }
      table[i] = value >>> 0;
    }

    return table;
  })();

  function crc32(bytes) {
    var crc = 0xFFFFFFFF;

    for (var i = 0; i < bytes.length; i++) {
      crc = CRC_TABLE[(crc ^ bytes[i]) & 0xFF] ^ (crc >>> 8);
    }

    return (crc ^ 0xFFFFFFFF) >>> 0;
  }

  /**
   * Đóng gói các phần của file xlsx thành ZIP (store, không nén).
   */
  function zipStore(files) {
    var encoder = new TextEncoder();
    var chunks = [];
    var directory = [];
    var offset = 0;
    // Mốc thời gian cố định 01/01/2020 theo định dạng DOS.
    var dosTime = 0;
    var dosDate = 0x5021;

    files.forEach(function (file) {
      var name = encoder.encode(file.name);
      var data = encoder.encode(file.data);
      var crc = crc32(data);

      var local = new Uint8Array(30 + name.length);
      var localView = new DataView(local.buffer);
      localView.setUint32(0, 0x04034B50, true);
      localView.setUint16(4, 20, true);
      localView.setUint16(6, 0x0800, true);
      localView.setUint16(8, 0, true);
      localView.setUint16(10, dosTime, true);
      localView.setUint16(12, dosDate, true);
      localView.setUint32(14, crc, true);
      localView.setUint32(18, data.length, true);
      localView.setUint32(22, data.length, true);
      localView.setUint16(26, name.length, true);
      localView.setUint16(28, 0, true);
      local.set(name, 30);

      var entry = new Uint8Array(46 + name.length);
      var entryView = new DataView(entry.buffer);
      entryView.setUint32(0, 0x02014B50, true);
      entryView.setUint16(4, 20, true);
      entryView.setUint16(6, 20, true);
      entryView.setUint16(8, 0x0800, true);
      entryView.setUint16(10, 0, true);
      entryView.setUint16(12, dosTime, true);
      entryView.setUint16(14, dosDate, true);
      entryView.setUint32(16, crc, true);
      entryView.setUint32(20, data.length, true);
      entryView.setUint32(24, data.length, true);
      entryView.setUint16(28, name.length, true);
      entryView.setUint16(30, 0, true);
      entryView.setUint16(32, 0, true);
      entryView.setUint16(34, 0, true);
      entryView.setUint16(36, 0, true);
      entryView.setUint32(38, 0, true);
      entryView.setUint32(42, offset, true);
      entry.set(name, 46);

      chunks.push(local, data);
      directory.push(entry);
      offset += local.length + data.length;
    });

    var directorySize = directory.reduce(function (size, entry) {
      return size + entry.length;
    }, 0);

    var end = new Uint8Array(22);
    var endView = new DataView(end.buffer);
    endView.setUint32(0, 0x06054B50, true);
    endView.setUint16(4, 0, true);
    endView.setUint16(6, 0, true);
    endView.setUint16(8, directory.length, true);
    endView.setUint16(10, directory.length, true);
    endView.setUint32(12, directorySize, true);
    endView.setUint32(16, offset, true);
    endView.setUint16(20, 0, true);

    return new Blob(chunks.concat(directory, [end]), {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    });
  }

  function escapeXml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      // Ký tự điều khiển không hợp lệ trong XML.
      .replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '');
  }

  function columnName(index) {
    var name = '';
    var number = index + 1;

    while (number > 0) {
      var remainder = (number - 1) % 26;
      name = String.fromCharCode(65 + remainder) + name;
      number = (number - remainder - 1) / 26;
    }

    return name;
  }

  function sheetXml(rows, headerIndex) {
    var widths = [];

    // Dòng tiêu đề chỉ có một ô và rất dài, tính vào đây thì cột đầu bị kéo
    // rộng hết cỡ.
    rows.slice(headerIndex).forEach(function (row) {
      row.forEach(function (cell, index) {
        var length = String(cell.number ? Math.round(cell.value) : cell.value).length + 4;
        widths[index] = Math.min(60, Math.max(widths[index] || 10, length));
      });
    });

    var xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      + '<sheetViews><sheetView workbookViewId="0">'
      + '<pane ySplit="' + (headerIndex + 1) + '" topLeftCell="A' + (headerIndex + 2) + '"'
      + ' activePane="bottomLeft" state="frozen"/>'
      + '</sheetView></sheetViews>'
      + '<cols>';

    widths.forEach(function (width, index) {
      xml += '<col min="' + (index + 1) + '" max="' + (index + 1) + '" width="' + width + '" customWidth="1"/>';
    });

    xml += '</cols><sheetData>';

    rows.forEach(function (row, rowIndex) {
      xml += '<row r="' + (rowIndex + 1) + '">';

      row.forEach(function (cell, cellIndex) {
        var reference = columnName(cellIndex) + (rowIndex + 1);
        var style;

        if (rowIndex < headerIndex) {
          style = 4;
        }
        else if (rowIndex === headerIndex) {
          style = 1;
        }
        else {
          style = cell.number ? 2 : 3;
        }

        if (cell.number) {
          xml += '<c r="' + reference + '" s="' + style + '"><v>' + cell.value + '</v></c>';
        }
        else {
          xml += '<c r="' + reference + '" s="' + style + '" t="inlineStr"><is><t xml:space="preserve">'
            + escapeXml(cell.value) + '</t></is></c>';
        }
      });

      xml += '</row>';
    });

    return xml + '</sheetData></worksheet>';
  }

  function stylesXml() {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      + '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
      + '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
      + '<fonts count="2">'
      + '<font><sz val="11"/><name val="Calibri"/></font>'
      + '<font><b/><sz val="11"/><name val="Calibri"/></font>'
      + '</fonts>'
      + '<fills count="3">'
      + '<fill><patternFill patternType="none"/></fill>'
      + '<fill><patternFill patternType="gray125"/></fill>'
      + '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill>'
      + '</fills>'
      + '<borders count="2">'
      + '<border><left/><right/><top/><bottom/><diagonal/></border>'
      + '<border>'
      + '<left style="thin"><color rgb="FFB7B7B7"/></left>'
      + '<right style="thin"><color rgb="FFB7B7B7"/></right>'
      + '<top style="thin"><color rgb="FFB7B7B7"/></top>'
      + '<bottom style="thin"><color rgb="FFB7B7B7"/></bottom>'
      + '<diagonal/></border>'
      + '</borders>'
      + '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
      + '<cellXfs count="5">'
      + '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
      + '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
      + '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
      + '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
      + '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
      + '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
      + '</cellXfs>'
      + '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
      + '</styleSheet>';
  }

  function buildWorkbook(rows, sheetName, headerIndex) {
    var name = escapeXml(sheetName.replace(/[\\\/\?\*\[\]:]/g, ' ').substring(0, 31)) || 'Sheet1';

    return zipStore([
      {
        name: '[Content_Types].xml',
        data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          + '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          + '<Default Extension="xml" ContentType="application/xml"/>'
          + '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          + '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
          + '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
          + '</Types>'
      },
      {
        name: '_rels/.rels',
        data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
          + '</Relationships>'
      },
      {
        name: 'xl/workbook.xml',
        data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
          + ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
          + '<sheets><sheet name="' + name + '" sheetId="1" r:id="rId1"/></sheets>'
          + '</workbook>'
      },
      {
        name: 'xl/_rels/workbook.xml.rels',
        data: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          + '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
          + '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
          + '</Relationships>'
      },
      { name: 'xl/styles.xml', data: stylesXml() },
      { name: 'xl/worksheets/sheet1.xml', data: sheetXml(rows, headerIndex || 0) }
    ]);
  }

  function downloadBlob(blob, fileName) {
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');

    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    window.setTimeout(function () {
      URL.revokeObjectURL(url);
    }, 1000);
  }

  function exportTable(table) {
    var rows = getExportRows(table);

    if (rows.length < 2) {
      window.alert(Drupal.t('There is no data to export.'));
      return;
    }

    var meta = getExportMeta(table);
    var name = table.dataset.exportName || Drupal.t('Invoices');
    var range = table.dataset.exportRange ? '-' + table.dataset.exportRange : '';
    var today = new Date();
    var stamp = today.getFullYear()
      + ('0' + (today.getMonth() + 1)).slice(-2)
      + ('0' + today.getDate()).slice(-2);

    downloadBlob(
      buildWorkbook(meta.concat(rows), name, meta.length),
      name + range + '-' + stamp + '.xlsx'
    );
  }

  Drupal.behaviors.invoiceTable = {
    attach: function (context) {
      once('invoice-table', '.invoice-table', context).forEach(function (table) {
        var key = table.dataset.tableKey || 'invoice';
        var scope = table.closest('.list-invoice') || document;
        var menu = scope.querySelector('.invoice-column-menu');

        if (menu) {
          buildColumnMenu(table, menu, key);
        }
        else {
          restoreColumns(table, key);
        }

        var pagination = setupPagination(table, scope, key);

        setupSort(table, function () {
          if (pagination) {
            pagination.reset();
          }
        });

        scope.querySelectorAll('.btn-export-excel').forEach(function (button) {
          button.addEventListener('click', function () {
            var loading = Drupal.eInvoiceLoading;

            if (!loading) {
              exportTable(table);
              return;
            }

            // Dựng file chạy đồng bộ và khoá luôn trình duyệt, nên phải nhường
            // một nhịp cho lớp phủ kịp vẽ ra rồi mới làm.
            loading.show(Drupal.t('Preparing the Excel file...'), true);

            window.setTimeout(function () {
              try {
                exportTable(table);
              }
              finally {
                loading.hide();
              }
            }, 50);
          });
        });
      });
    }
  };
})(Drupal, once);
