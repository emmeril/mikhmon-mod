(function (window, document) {
  'use strict';

  function cellValue(cell) {
    if (!cell) return '';
    var value = cell.getAttribute('data-sort-value');
    return value !== null ? value : (cell.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function parseValue(value) {
    var text = String(value).trim();
    var numeric = text.match(/^\s*[^\d+\-]*([-+]?\d[\d.,]*)\s*(?:%|[a-zA-Z]{1,5})?\s*$/);
    if (numeric) {
      var normalized = numeric[1];
      var lastDot = normalized.lastIndexOf('.');
      var lastComma = normalized.lastIndexOf(',');
      if (lastDot !== -1 && lastComma !== -1) {
        var decimalSeparator = lastDot > lastComma ? '.' : ',';
        normalized = normalized.replace(decimalSeparator === '.' ? /,/g : /\./g, '').replace(decimalSeparator, '.');
      } else if (/^[+-]?\d{1,3}([.,]\d{3})+$/.test(normalized)) {
        normalized = normalized.replace(/[.,]/g, '');
      } else {
        normalized = normalized.replace(',', '.');
      }
      return { type: 'number', value: Number(normalized) };
    }
    var timestamp = Date.parse(value);
    if (!isNaN(timestamp) && /\d/.test(value)) {
      return { type: 'date', value: timestamp };
    }
    return { type: 'text', value: String(value).toLowerCase() };
  }

  function sortTable(table, column, direction) {
    var body = table.tBodies && table.tBodies[0];
    if (!body) return;
    var rows = Array.prototype.slice.call(body.rows);
    rows.sort(function (left, right) {
      var a = parseValue(cellValue(left.cells[column]));
      var b = parseValue(cellValue(right.cells[column]));
      var result;
      if (a.type === b.type && a.value === b.value) result = 0;
      else if (a.type === b.type) result = a.value < b.value ? -1 : 1;
      else result = String(a.value).localeCompare(String(b.value), undefined, { numeric: true, sensitivity: 'base' });
      return direction * result;
    });
    rows.forEach(function (row) { body.appendChild(row); });
  }

  function updateHeader(header, direction) {
    Array.prototype.forEach.call(header.parentNode.cells, function (other) {
      if (other === header) return;
      if (other.classList.contains('table-sortable')) other.setAttribute('aria-sort', 'none');
      var otherIcon = other.querySelector('.table-sort-icon');
      if (otherIcon) otherIcon.className = 'fa fa-sort table-sort-icon';
    });
    var icon = header.querySelector('.table-sort-icon') || header.querySelector('.fa-sort');
    if (!icon) {
      icon = document.createElement('i');
      icon.className = 'fa table-sort-icon';
      header.insertBefore(icon, header.firstChild);
    }
    icon.className = 'fa table-sort-icon fa-sort-' + (direction > 0 ? 'asc' : 'desc');
    header.setAttribute('aria-sort', direction > 0 ? 'ascending' : 'descending');
  }

  function makeSortable(table) {
    if (!table || table.getAttribute('data-sort-ready') === '1') return;
    var body = table.tBodies && table.tBodies[0];
    if (!body || !table.tHead) return;
    var head = null;
    Array.prototype.forEach.call(table.tHead.rows, function (row) {
      var simpleRow = Array.prototype.every.call(row.cells, function (cell) { return cell.colSpan === 1; });
      if (simpleRow && (!head || row.cells.length >= head.cells.length)) head = row;
    });
    if (!head && table.tHead.rows.length) head = table.tHead.rows[table.tHead.rows.length - 1];
    if (!head) return;
    Array.prototype.forEach.call(head.cells, function (header, index) {
      var label = header.textContent.replace(/\s+/g, ' ').trim().toLowerCase();
      if (header.getAttribute('data-sortable') === 'false' || !label || /^(aksi|action|tindakan)$/.test(label)) return;
      header.classList.add('pointer', 'table-sortable');
      header.title = header.title || 'Click to sort';
      header.setAttribute('role', 'button');
      header.setAttribute('tabindex', '0');
      header.setAttribute('aria-sort', 'none');
      var icon = header.querySelector('.table-sort-icon') || header.querySelector('.fa-sort');
      if (!icon) {
        icon = document.createElement('i');
        header.insertBefore(icon, header.firstChild);
      }
      icon.classList.add('fa', 'table-sort-icon');
      icon.style.marginRight = '5px';
      if (!icon.classList.contains('fa-sort-asc') && !icon.classList.contains('fa-sort-desc')) icon.classList.add('fa-sort');
      var direction = -1;
      header.addEventListener('click', function () {
        direction *= -1;
        var currentIndex = Array.prototype.indexOf.call(header.parentNode.cells, header);
        sortTable(table, currentIndex < 0 ? index : currentIndex, direction);
        updateHeader(header, direction);
      });
      header.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          header.click();
        }
      });
    });
    table.setAttribute('data-sort-ready', '1');
  }

  function makeAllSortable(root) {
    root = root || document;
    if (root.nodeType === 1 && root.matches && root.matches('table')) makeSortable(root);
    Array.prototype.forEach.call(root.querySelectorAll('table'), makeSortable);
  }

  // Replace the legacy helper so existing pages and newly loaded partials share one sorter.
  window.makeSortable = makeSortable;
  window.makeAllSortable = makeAllSortable;
  window.sortTable = function (table, column, direction) { sortTable(table, column, direction || 1); };

  document.addEventListener('DOMContentLoaded', function () {
    makeAllSortable(document);
    if (window.MutationObserver) {
      new MutationObserver(function (records) {
        records.forEach(function (record) {
          Array.prototype.forEach.call(record.addedNodes, function (node) {
            if (node.nodeType === 1) makeAllSortable(node);
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  });
})(window, document);
