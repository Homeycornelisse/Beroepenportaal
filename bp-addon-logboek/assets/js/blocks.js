(function (blocks, element, blockEditor, components, i18n) {
  var el = element.createElement;
  var __ = i18n.__;
  var InspectorControls = blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var ToggleControl = components.ToggleControl;
  var TextControl = components.TextControl;

  blocks.registerBlockType('bp/tweedespoor-logboek-client', {
    title: 'Beroepen Portaal: 2eSpoor Logboek (Cliënt)',
    icon: 'clipboard',
    category: 'widgets',
    keywords: ['beroep', 'portaal', 'logboek', '2espoor', '2e spoor'],
    attributes: {
      title: { type: 'string', default: '2e Spoor Logboek' },
      introTitle: { type: 'string', default: '2e Spoor Re-integratie Logboek' },
      introText: { type: 'string', default: 'Houd bij welke activiteiten je onderneemt voor je re-integratie. Exporteer als professionele PDF voor je begeleider of werkgever.' },
      showStats: { type: 'boolean', default: true },
      showFilter: { type: 'boolean', default: true },
      showExport: { type: 'boolean', default: true },
      showPortaal: { type: 'boolean', default: true }
    },
    edit: function (props) {
      var a = props.attributes;
      return el(
        element.Fragment,
        {},
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: 'Instellingen', initialOpen: true },
            el(TextControl, {
              label: 'Titel bovenaan',
              value: a.title,
              onChange: function (v) { props.setAttributes({ title: v }); }
            }),
            el(TextControl, {
              label: 'Banner titel',
              value: a.introTitle,
              onChange: function (v) { props.setAttributes({ introTitle: v }); }
            }),
            el(TextControl, {
              label: 'Banner tekst',
              value: a.introText,
              onChange: function (v) { props.setAttributes({ introText: v }); }
            }),
            el(ToggleControl, {
              label: 'Toon statistiek kaarten',
              checked: !!a.showStats,
              onChange: function (v) { props.setAttributes({ showStats: !!v }); }
            }),
            el(ToggleControl, {
              label: 'Toon filter',
              checked: !!a.showFilter,
              onChange: function (v) { props.setAttributes({ showFilter: !!v }); }
            }),
            el(ToggleControl, {
              label: 'Toon PDF export knop',
              checked: !!a.showExport,
              onChange: function (v) { props.setAttributes({ showExport: !!v }); }
            }),
            el(ToggleControl, {
              label: 'Toon Portaal link',
              checked: !!a.showPortaal,
              onChange: function (v) { props.setAttributes({ showPortaal: !!v }); }
            })
          )
        ),
        el(
          'div',
          { style: { padding: '18px', border: '2px dashed #0047AB', borderRadius: '12px', background: '#fff' } },
          el('div', { style: { fontWeight: 800, marginBottom: '6px' } }, '2eSpoor Logboek (Cliënt)'),
          el('div', { style: { color: '#64748b', fontSize: '13px' } }, 'Dit blok toont het logboek op de website. Opslaan en bekijk de pagina.')
        )
      );
    },
    save: function () { return null; }
  });

  blocks.registerBlockType('bp/tweedespoor-logboek-begeleider', {
    title: 'Beroepen Portaal: 2eSpoor Logboek (Begeleider)',
    icon: 'admin-users',
    category: 'widgets',
    keywords: ['beroep', 'portaal', 'logboek', 'begeleider', '2espoor', '2e spoor'],
    attributes: {
      title: { type: 'string', default: 'Begeleider Logboek' },
      showExport: { type: 'boolean', default: true }
    },
    edit: function (props) {
      var a = props.attributes;
      return el(
        element.Fragment,
        {},
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: 'Instellingen', initialOpen: true },
            el(TextControl, {
              label: 'Titel bovenaan',
              value: a.title,
              onChange: function (v) { props.setAttributes({ title: v }); }
            }),
            el(ToggleControl, {
              label: 'Toon PDF export knop',
              checked: !!a.showExport,
              onChange: function (v) { props.setAttributes({ showExport: !!v }); }
            })
          )
        ),
        el(
          'div',
          { style: { padding: '18px', border: '2px dashed #0047AB', borderRadius: '12px', background: '#fff' } },
          el('div', { style: { fontWeight: 800, marginBottom: '6px' } }, '2eSpoor Logboek (Begeleider)'),
          el('div', { style: { color: '#64748b', fontSize: '13px' } }, 'Dit blok toont begeleider-notities per cliënt.')
        )
      );
    },
    save: function () { return null; }
  });

})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
