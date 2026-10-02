// "Domain Prices" block: server-rendered (same output as the shortcodes),
// written against the wp.* globals so it needs no build step.
(function (wp) {
  var el = wp.element.createElement;
  var __ = wp.i18n.__;
  var InspectorControls = wp.blockEditor.InspectorControls;
  var useBlockProps = wp.blockEditor.useBlockProps;
  var c = wp.components;
  var ServerSideRender = wp.serverSideRender;
  var data = window.tldersDpBlock || { views: {}, pages: [] };

  var viewOptions = Object.keys(data.views).map(function (key) {
    return { value: key, label: data.views[key] };
  });
  var pageOptions = [{ value: 0, label: __('— Same page —', 'tlders-domain-prices') }].concat(
    data.pages.map(function (p) { return { value: p.id, label: p.title }; })
  );

  wp.blocks.registerBlockType('tlders/domain-prices', {
    edit: function (props) {
      var a = props.attributes;
      var set = function (key) {
        return function (value) {
          var update = {};
          update[key] = value;
          props.setAttributes(update);
        };
      };
      var fields = [
        el(c.SelectControl, { key: 'view', label: __('Show', 'tlders-domain-prices'), value: a.view, options: viewOptions, onChange: set('view') }),
      ];
      if (a.view === 'table' || a.view === 'price') {
        fields.push(el(c.TextControl, { key: 'tld', label: __('Extension', 'tlders-domain-prices'), help: __('e.g. com, io, in', 'tlders-domain-prices'), value: a.tld, onChange: set('tld') }));
      }
      if (a.view === 'cheapest') {
        fields.push(el(c.TextControl, { key: 'tlds', label: __('Extensions', 'tlders-domain-prices'), help: __('Comma-separated. Empty uses your popular TLDs from Settings.', 'tlders-domain-prices'), value: a.tlds, onChange: set('tlds') }));
      }
      if (a.view === 'table' || a.view === 'search') {
        fields.push(el(c.RangeControl, { key: 'limit', label: __('Registrars to show', 'tlders-domain-prices'), min: 1, max: 50, value: a.limit, onChange: set('limit') }));
      }
      if (a.view === 'search') {
        fields.push(el(c.SelectControl, {
          key: 'page',
          label: __('Search results page', 'tlders-domain-prices'),
          help: __('For a sidebar or header, pick a page with a full-width search block so results get room.', 'tlders-domain-prices'),
          value: a.page,
          options: pageOptions,
          onChange: function (v) { props.setAttributes({ page: parseInt(v, 10) || 0 }); },
        }));
      }

      return el('div', useBlockProps(),
        el(InspectorControls, null, el(c.PanelBody, { title: __('Domain prices', 'tlders-domain-prices') }, fields)),
        el(ServerSideRender, { block: 'tlders/domain-prices', attributes: a })
      );
    },
    save: function () {
      return null; // rendered on the server
    },
  });
})(window.wp);
