define(['Magento_Ui/js/grid/columns/select'], function (Column) {
/** 2017-09-06 @uses Class::extend() https://github.com/magento/magento2/blob/2.2.0-rc2.3/app/code/Magento/Ui/view/base/web/js/lib/core/class.js#L106-L140 */	
return Column.extend ({
	/**
	 * 2016-07-28
	 * Цель перекрытия — задействование в качестве результата сырого значения
	 * в том случае, когда сырое значение отсутствует в справочнике значений.
	 * Родительская реалиация работает так: если сырое значение отсутствует в справочнике значений,
	 * то родительская реализация возвращает пустую строку.
	 *
	 * Нас это не устраивает.
	 * Мы хотим возможности отображения в колонке «Payment Method»
	 * административной таблице заказов расширенного названия способа оплаты для заказов.
	 * Эти расширенные названия будут настраиваться моими конкретными платёжными модулями.
	 * Например, вместо «歐付寶 allPay» может отображаться «歐付寶 allPay (Bank Card)».
	 *
	 * В ядре в данном контексте сырым значением является код способа оплаты,
	 * например: «dfe_allpay».
	 * Далее ядро смотрит в справочнике, какое название соответствует коду «dfe_allpay»,
	 * и возвращает строку «歐付寶 allPay».
	 *
	 * В нашем же случае мы методом \Df\Payment\Observer\DataProvider\SearchResult::execute()
	 * запихнули в поле «payment_method» наших строк расширенное название наших способов оплаты
	 * (наприме, «歐付寶 allPay (Bank Card)»).
	 *
	 * Разумеется, такие значения отсутствуют в справочнике значений.
	 * Вот мы и хотим, чтобы в такой ситуации возвращалась не пустая строка, а сырое значение.
	 *
	 * @param {Object} record
	 * @returns {String}
	 */
	getLabel: function(record) {
		var result = this._super(); /** @type {String} */
		return result.length ? result : record[this.index];
	}
});});
