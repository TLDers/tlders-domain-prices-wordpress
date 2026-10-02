// Filters the registrar affiliate-link table on the settings screen.
(function () {
  var filter = document.getElementById('tlders-registrar-filter');
  var mine = document.getElementById('tlders-registrar-mine');
  var table = document.getElementById('tlders-registrar-links');
  if (!filter || !mine || !table) {
    return;
  }
  function apply() {
    var q = filter.value.trim().toLowerCase();
    table.querySelectorAll('tbody tr').forEach(function (row) {
      var input = row.querySelector('input');
      var matches = row.getAttribute('data-name').indexOf(q) !== -1;
      var hasLink = input && input.value.trim() !== '';
      row.style.display = matches && (!mine.checked || hasLink) ? '' : 'none';
    });
  }
  filter.addEventListener('input', function () {
    if (filter.value) {
      mine.checked = false; // searching means looking for a registrar to add
    }
    apply();
  });
  mine.addEventListener('change', apply);
  apply();
})();
