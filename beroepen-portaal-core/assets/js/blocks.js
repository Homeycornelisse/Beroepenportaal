(function (wp) {
  const { registerBlockType } = wp.blocks;
  const { __ } = wp.i18n;
  const { InspectorControls } = wp.blockEditor;
  const { PanelBody, SelectControl, TextControl, Notice } = wp.components;
  const el = wp.element.createElement;

  registerBlockType('bp/portaal-page', {
    title: 'Beroepen Portaal: Pagina',
    icon: 'id-alt',
    category: 'widgets',
    supports: {
      align: ['full', 'wide'],
    },
    attributes: {
      screen: { type: 'string', default: 'dashboard' },
      align: { type: 'string', default: '' },
    },
    edit: function (props) {
      const screen = props.attributes.screen;
      return [
        el(
          InspectorControls,
          { key: 'inspector' },
          el(
            PanelBody,
            { title: 'Instellingen', initialOpen: true },
            el(SelectControl, {
              label: 'Welke pagina wil je tonen?',
              value: screen,
              options: [
                { label: 'Dashboard', value: 'dashboard' },
                { label: 'Account', value: 'account' },
                { label: 'Inbox', value: 'inbox' },
                { label: 'Uitleg', value: 'uitleg' },
                { label: 'Login', value: 'login' },
                { label: 'Home (verwijder dit blok — bouw zelf met blokken)', value: 'home' },
                { label: 'Beroepen (uitgeschakeld)', value: 'beroepen' },
              ],
              onChange: function (val) {
                props.setAttributes({ screen: val });
              },
            })
          )
        ),
        el(
          'div',
          {
            key: 'preview',
            style: {
              padding: '16px',
              border: screen === 'home' ? '2px solid #f59e0b' : '1px dashed #ccd0d4',
              borderRadius: '12px',
              background: screen === 'home' ? '#fffbeb' : '#fff',
            },
          },
          el('strong', null, 'Beroepen Portaal: Pagina'),
          screen === 'home'
            ? el('div', { style: { marginTop: '8px', color: '#92400e', fontSize: '13px' } },
                '⚠️ Home-modus: dit blok toont niets op de website. Verwijder dit blok en bouw de homepage zelf met WordPress-blokken (Cover, Groep, HTML, Kolommen, etc.). Gebruik het blok "Login / Uitloggen knop" voor de inlogknop.'
              )
            : el('div', null, 'Weergave: ' + screen)
        ),
      ];
    },
    save: function () {
      return null; // dynamic
    },
  });

  registerBlockType('bp/rechten-per-gebruiker', {
    title: 'Beroepen Portaal: Rechten per gebruiker',
    icon: 'admin-users',
    category: 'widgets',
    edit: function () {
      return el(
        'div',
        {
          style: {
            padding: '16px',
            border: '1px dashed #ccd0d4',
            borderRadius: '12px',
            background: '#fff',
          },
        },
        el('strong', null, 'Rechten per gebruiker'),
        el(
          Notice,
          { status: 'info', isDismissible: false },
          'Dit blok is bedoeld voor leidinggevenden. Op de live pagina zie je de echte beheerknoppen.'
        )
      );
    },
    save: function () {
      return null;
    },
  });
  registerBlockType('bp/login-knop', {
    title: 'Beroepen Portaal: Login / Uitloggen knop',
    icon: 'admin-users',
    category: 'widgets',
    attributes: {
      login_url: { type: 'string', default: '' },
    },
    edit: function (props) {
      const loginUrl = props.attributes.login_url;
      return [
        el(
          InspectorControls,
          { key: 'inspector' },
          el(
            PanelBody,
            { title: 'Knop instellingen', initialOpen: true },
            el(TextControl, {
              label: 'Login URL (waar de knop naar doorlinkt)',
              value: loginUrl,
              placeholder: 'https://voorbeeld.nl/login-portaal',
              onChange: function (val) {
                props.setAttributes({ login_url: val });
              },
              help: 'Laat leeg om de standaard login-portaal pagina te gebruiken.',
            })
          )
        ),
        el(
          'div',
          {
            key: 'preview',
            style: {
              padding: '16px',
              border: '1px dashed #ccd0d4',
              borderRadius: '12px',
              background: '#fff',
            },
          },
          el('strong', null, 'Login / Uitloggen knop'),
          el('div', { style: { marginTop: '8px' } },
            el('span', {
              style: {
                display: 'inline-block',
                padding: '10px 20px',
                background: '#003082',
                color: '#fff',
                borderRadius: '8px',
                fontSize: '14px',
              }
            }, 'Inloggen →')
          ),
          el('div', { style: { marginTop: '6px', fontSize: '12px', color: '#94a3b8' } },
            loginUrl ? 'Inloggen → ' + loginUrl : 'Inloggen → (standaard login-portaal pagina)'
          )
        ),
      ];
    },
    save: function () {
      return null; // dynamic
    },
  });
})(window.wp);
