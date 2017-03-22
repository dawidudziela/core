<?php
namespace Df\Payment\W;
use Df\Payment\Method as M;
use Df\Payment\W\Exception\NotForUs;
use Magento\Sales\Model\Order\Payment as OP;
use Magento\Sales\Model\Order\Payment\Transaction as T;
/**
 * 2017-03-15
 * @see \Df\PaypalClone\W\Nav
 * @see \Df\StripeClone\W\Nav
 */
abstract class Nav {
	/**
	 * 2016-07-20
	 * 2017-01-04
	 * Внутренний полный идентификатор текущей транзакции.
	 * Он используется лишь для присвоения его транзакции
	 * (чтобы в будущем мы смогли найти эту транзакцию по её идентификатору).
	 * @used-by op()
	 * @see \Df\PaypalClone\W\Nav::id()
	 * @see \Df\StripeClone\W\Nav::id()
	 * @see \Dfe\AllPay\W\Nav\Offline::id()
	 * @return string
	 */
	abstract protected function id();

	/**
	 * 2017-01-05
	 * 2017-03-16
	 * Преобразует некую основу в идентификатор родительской транзакции в Magento.
	 * Эта основа в настоящее время бывает 2-х видов:
	 *
	 * 1) Идентификатор платежа в платёжной системе.
	 * Это случай Stripe-подобных платёжных систем: у них идентификатор формируется платёжной системой.
	 *
	 * 2) Локальный внутренний идентификатор родительской транзакции.
	 * Это случай PayPal-подобных платёжных систем, когда мы сами ранее сформировали
	 * идентификатор запроса к платёжной системе (этот запрос и является родительской транзакцией).
	 * Мы намеренно передавали идентификатор локальным (без приставки с именем модуля)
	 * для удобства работы с этими идентификаторами в интерфейсе платёжной системы:
	 * ведь там все идентификаторы имели бы одинаковую приставку.
	 * Такой идентификатор формируется в методах:
	 * @see \Df\PaypalClone\Charge::requestId()
	 * @see \Dfe\AllPay\Charge::requestId()
	 * Глобальный внутренний идентификатор отличается наличием приставки «<имя модуля>-».
	 * @used-by pid()
	 * @see \Df\PaypalClone\W\Nav::pidAdapt()
	 * @see \Df\StripeClone\W\Nav::pidAdapt()
	 * @param string $id
	 * @return string
	 */
	abstract protected function pidAdapt($id);

	/**
	 * 2017-03-15
	 * @used-by i()
	 * @used-by \Df\Payment\W\F::__construct()
	 * @param Event $e
	 */
	final function __construct(Event $e) {$this->_e = $e;}

	/**
	 * 2016-07-10
	 * 2017-01-04
	 * Добавил возможность возвращения null:
	 * такое происходит, например, когда мы проводим тестовый платёж на локальном компьютере,
	 * а платёжная система присылает оповещение на наш сайт mage2.pro/sandbox
	 * В такой ситуации не стоит падать с искючительной ситуацией,
	 * а лучше просто ответить: «The event is not for our store».
	 * Так и раньше вели себя мои Stripe-подобные модули,
	 * теперь же я распространил такое поведение на все мои платёжные модули.
	 * 2017-01-06
	 * Для Stripe-подобных платёжных модулей алгоритм раньше был таким:
	 *	$id = df_fetch_one('sales_payment_transaction', 'payment_id', ['txn_id' => $this->id()]);
	 *	return !$id ? null : df_load(Payment::class, $id);
	 * https://github.com/mage2pro/core/blob/1.11.6/Payment/Transaction.php?ts=4#L16-L29
	 * @used-by m()
	 * @used-by o()
	 * @used-by \Df\Payment\W\Handler::op()
	 * @used-by \Df\PaypalClone\W\Confirmation::capture()
	 * @used-by \Df\StripeClone\W\Strategy::ii()
	 * @return OP|null
	 */
	final function op() {if (!isset($this->{__METHOD__})) {
		/** @var OP|null $op */
		$this->{__METHOD__} = df_n_set($op = dfp_webhook_case(dfp($this->p())));
		if ($op) {
			/**
			 * 2017-01-16
			 * A) Этот код:
			 * A.1) Устанавливает идентификатор текущей транзакции.
			 * A.2) Указывает идентификатор родительской транзакции.
			 * A.3) Присваивает транзакции информацию из запроса платёжной системы.
			 *
			 * Б) При этом код НЕ ДОБАВЛЯЕТ ТРАНЗАКЦИЮ!
			 * Б.1) Для PayPal-подобных платёжных модулей добавление транзакции происходит в методе
			 * @see \Df\PaypalClone\W\Confirmation::_handle()
			 *
			 *) Б.2) Для Stripe-подобных платёжных модулей добавление транзакции происходит неявно
			 * при вызове методов ядра:
			 *
			 * Б.2.1) Для операции «authorize» addTransaction() вызывается из:
			 * @see \Magento\Sales\Model\Order\Payment\Operations\AuthorizeOperation::authorize():
			 * 		$transaction = $payment->addTransaction(Transaction::TYPE_AUTH);
			 * https://github.com/magento/magento2/blob/2.1.3/app/code/Magento/Sales/Model/Order/Payment/Operations/AuthorizeOperation.php#L50
			 *
			 * Б.2.2) Для операции «capture» addTransaction() вызывается из:
			 * @see \Magento\Sales\Model\Order\Payment\Operations\CaptureOperation::capture()
			 * транзакция на самом деле тоже добавляется, и тоже через builder, просто чуть иным кодом:
			 *	$transactionBuilder = $this->transactionBuilder->setPayment($payment);
			 *	$transactionBuilder->setOrder($order);
			 *	$transactionBuilder->setFailSafe(true);
			 *	$transactionBuilder->setTransactionId($payment->getTransactionId());
			 *	$transactionBuilder->setAdditionalInformation($payment->getTransactionAdditionalInfo());
			 *	$transactionBuilder->setSalesDocument($invoice);
			 *	$transaction = $transactionBuilder->build(Transaction::TYPE_CAPTURE);
			 *
			 * Б.2.3) При этом ядро при вызове (из ядра)
			 * @see \Magento\Sales\Model\Order\Payment\Transaction\Manager::generateTransactionId()
			 * смотрит, не были ли ранее установлены идентификаторы транзакции,
			 * и если были, то не перетирает их:
			 *
			 *	if (!$payment->getParentTransactionId()
			 *		&& !$payment->getTransactionId() && $transactionBasedOn
			 *	) {
			 *		$payment->setParentTransactionId($transactionBasedOn->getTxnId());
			 *	}
			 *	// generate transaction id for an offline action or payment method that didn't set it
			 *	if (
			 *		($parentTxnId = $payment->getParentTransactionId())
			 *		&& !$payment->getTransactionId()
			 *	) {
			 *		return "{$parentTxnId}-{$type}";
			 *	}
			 *	return $payment->getTransactionId();
			 * https://github.com/magento/magento2/blob/2.0.0/app/code/Magento/Sales/Model/Order/Payment/Transaction/Manager.php#L73-L80
			 */
			$op->setTransactionId($this->id());
			/** 2016-07-12 @used-by \Magento\Sales\Model\Order\Payment\Transaction\Builder::linkWithParentTransaction() */
			$op->setParentTransactionId($this->pid());
			df_trd_set($op, $this->_e->r());
		}
	}return df_n_get($this->{__METHOD__});}

	/**
	 * 2016-07-10
	 * 2016-12-30
	 * Возвращает транзакцию Magento, породившую данное оповещение от платёжной системы (webhook event).
	 * В то же время не каждое оповещение от платёжной системы инициируется запросом от Magento:
	 * например, оповещение могло быть инициировано некими действиями администратора магазина
	 * в административном интерфейсе магазина в платёжной системе.
	 * Однако первичная транзакция всё равно должна в Magento присутствовать.
	 * 2017-01-08
	 * Добавил обработку ситуации, когда родительская транзакция не найдена.
	 * Такое возможно, например, когда мы выполнили из административной части Stripe
	 * запрос на capture для локального (localhost) магазина, а оповещение пришло на mage2.pro/sandbox.
	 * Так вот, если просто свалиться с исключительной ситуацией (код HTTP 500),
	 * то Stripe задолбает повторными запросами.
	 * Надо вернуть код HTTP 200 и человекопонятное сообщение: мол, запрос — не для нашего магазина.
	 * @used-by op()
	 * @used-by \Dfe\SecurePay\Signer\Response::values()
	 * @return T
	 * @throws NotForUs
	 */
	final function p() {return dfc($this, function() {/** @var string $id */return
		df_transx($id = $this->pid(), false) ?: df_error(new NotForUs(
			"It seems like this notification is not for our store "
			."because it refers to a transaction «{$id}», which is absent in the store's database."
		))
	;});}

	/**
	 * 2017-03-16
	 * Возвращает идентификатор родительской транзакции в Magento.
	 * @used-by op()
	 * @used-by p()
	 * @used-by \Df\StripeClone\W\Strategy::parentId()
	 * @return string
	 */
	final function pid() {return dfc($this, function() {return $this->pidAdapt($this->_e->pid());});}

	/**
	 * 2017-03-15
	 * @final I do not use the PHP «final» keyword here to allow refine the return type using PHPDoc.
	 * @used-by \Df\StripeClone\W\Nav::id()
	 * @return Event
	 */
	protected function e() {return $this->_e;}

	/**
	 * 2017-03-17
	 * @final I do not use the PHP «final» keyword here to allow refine the return type using PHPDoc.
	 * $m здесь НЕ СОДЕРЖИТ корректного II.
	 * Для вычисления корректного II нам ещё предстоит провести кучу операций:
	 * 1) Определить, к какой транзакции Magento относится данное событие.
	 * 2) Загрузить эту транзакцию из БД.
	 * 3) По транзакции получить II.
	 * Это всё нам ещё предстоит!
	 * @used-by \Df\PaypalClone\W\Nav::e2i()
	 * @used-by \Df\StripeClone\W\Nav::e2i()
	 * @return M
	 */
	protected function mPartial() {return $this->_e->m();}

	/**
	 * 2017-03-15
	 * @used-by __construct()
	 * @used-by e()
	 * @used-by mPartial()
	 * @used-by op()
	 * @used-by pid()
	 * @var Event
	 */
	private $_e;
}