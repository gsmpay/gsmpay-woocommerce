const gsmpay_settings = window.wc.wcSettings.getSetting( 'WC_GSMPay_data', {} );
const gsmpay_label = window.wp.htmlEntities.decodeEntities( gsmpay_settings.title ) || '';

const gsmpay_content = () => {
    return window.wp.htmlEntities.decodeEntities( gsmpay_settings.description || '' );
};

const gsmpay_block_gateway = {
    name: 'WC_GSMPay',
    label: gsmpay_label,
    content: Object( window.wp.element.createElement )( gsmpay_content, null ),
    edit: Object( window.wp.element.createElement )( gsmpay_content, null ),
    canMakePayment: () => true,
    ariaLabel: gsmpay_label,
    supports: {
        features: gsmpay_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(gsmpay_block_gateway);
