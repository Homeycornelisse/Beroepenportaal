(function (wp) {
  const { registerBlockType } = wp.blocks;
  const el = wp.element.createElement;
  const { Notice } = wp.components;

  registerBlockType('bp/cv', {
    title: 'Beroepen Portaal: CV',
    icon: 'media-document',
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
        el('strong', null, 'CV module'),
        el(
          Notice,
          { status: 'info', isDismissible: false },
          'Op de live pagina zie je het CV upload/download scherm.'
        )
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp);
