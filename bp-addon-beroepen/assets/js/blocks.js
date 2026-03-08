(function (blocks, element) {
  const el = element.createElement;
  const registerBlockType = blocks.registerBlockType;

  registerBlockType('bp/beroepen', {
    apiVersion: 2,
    title: 'Beroepen',
    icon: 'list-view',
    category: 'widgets',
    supports: {
      align: ['wide', 'full']
    },
    edit: function () {
      return el(
        'div',
        {
          style: {
            padding: '16px',
            border: '1px solid #d6dce8',
            borderRadius: '10px',
            background: '#f8fafc'
          }
        },
        'Beroepen addon: dit blok toont de beroepenkaarten op de voorzijde.'
      );
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element);
