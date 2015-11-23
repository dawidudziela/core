<?php
/**
 * 2015-10-26
 * https://mage2.pro/t/145
 * @used-by \Df\Framework\Data\Form\Element\Color::_construct()
 * @used-by \Df\Framework\Data\Form\Element\Color::getAfterElementHtml()
 * @param string $script
 * @param array(string => mixed) $parameters
 * @return string
 */
function df_x_magento_init($script, array $parameters = []) {
	return df_tag('script', ['type' => 'text/x-magento-init'],
		json_encode(['*' => [$script => $parameters]])
	);
}

/**
 * 2015-02-17
 * Экранирует строку для вставки её в код на JavaScript.
 * @uses json_encode() рекомендуют
 * как самый правильный способ вставки строки из PHP в JavaScript:
 * http://stackoverflow.com/a/169035
 * Заменяем символ одинарной ковычки его кодом Unicode,
 * чтобы результат метода можно было вставлять внутрь обрамленной одиночными кавычками строки,
 * например:
	var $name = '<?php echo df_ejs($name); ?>';
 * @used-by df_admin_button_location()
 * @used-by Df_Admin_Config_DynamicTable_Column::renderTemplate()
 * @used-by app/design/adminhtml/rm/default/template/df/admin/column/select.phtml
 * @used-by app/design/adminhtml/rm/default/template/df/admin/field/button.phtml
 * @used-by app/design/frontend/rm/default/template/df/checkout/onepage/shipping_method/available/js.phtml
 * @param string $text
 * @return string
 */
function df_ejs($text) {return str_replace("'", '\u0027', df_trim(json_encode($text), '"'));}