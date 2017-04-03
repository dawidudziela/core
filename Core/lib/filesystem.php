<?php
use Df\Core\Exception as DFE;
use Df\Core\Helper\Path as DfPath;
use Magento\Framework\Filesystem\Directory\Read as DirectoryRead;
use Magento\Framework\Filesystem\Directory\ReadInterface as DirectoryReadInterface;
use Magento\Framework\Filesystem\Directory\Write as DirectoryWrite;
use Magento\Framework\Filesystem\Directory\WriteInterface as DirectoryWriteInterface;
use Magento\Framework\Filesystem\File\ReadInterface as FileReadInterface;
use Magento\Framework\Filesystem\File\Read as FileRead;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Framework\Filesystem\File\Write as FileWrite;
use Magento\Framework\Filesystem\Io\File as File;
use Magento\Framework\Module\Dir as ModuleDir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
if (!defined('DS')) {
	define('DS', DIRECTORY_SEPARATOR);
}

/**
 * 2016-12-23
 * Удаляет из сообщений типа
 * «Warning: Division by zero in C:\work\mage2.pro\store\vendor\mage2pro\stripe\Method.php on line 207»
 * файловый путь до папки Magento.
 * @param string $m
 * @return string
 */
function df_adjust_paths_in_message($m) {
	/** @var int $bpLen */
	$bpLen = mb_strlen(BP);
	do {
		/** @var int|false $begin */
		$begin = mb_strpos($m, BP);
		if (false === $begin) {
			break;
		}
		/** @var int|false $begin */
		$end = mb_strpos($m, '.php', $begin + $bpLen);
		if (false === $end) {
			break;
		}
		// 2016-12-23
		// длина «.php»
		$end += 4;
		$m =
			mb_substr($m, 0, $begin)
			// 2016-12-23
			// + 1, чтобы отсечь «/» или «\» после BP
			. df_path_n(mb_substr($m, $begin + $bpLen + 1, $end - $begin - $bpLen - 1))
			. mb_substr($m, $end)
		;
	} while(true);
	return $m;
}

/**
 * 2015-11-28
 * http://stackoverflow.com/a/10368236
 * @param string $fileName
 * @return string
 */
function df_file_ext($fileName) {return pathinfo($fileName, PATHINFO_EXTENSION);}

/**
 * Возвращает неиспользуемое имя файла в заданной папке $directory по заданному шаблону $template.
 * Результатом всегда является непустая строка.
 * @used-by df_report()
 * @param string $directory
 * @param string $template
 * @param string $ds [optional]
 * @return string
 */
function df_file_name($directory, $template, $ds = '-') {
	// 2016-11-09
	// Отныне $template может содержать файловый путь:
	// в этом случае этот файловый путь убираем из $template и добавляем к $directory.
	$directory = df_path_n($directory);
	$template = df_path_n($template);
	if (df_contains($template, '/')) {
		/** @var string[] $templateA */
		$templateA = explode('/', $template);
		$template = array_pop($templateA);
		$directory = df_cc_path($directory, $templateA);
	}
	/** @var string $result */
	/** @var int $counter */
	$counter = 1;
	/** @var bool $hasOrderingPosition */
	$hasOrderingPosition = df_contains($template, '{ordering}');
	/** @var \Zend_Date $now */
	$now = \Zend_Date::now()->setTimezone('Europe/Moscow');
	/** @var array(string => string) */
	$vars = df_map_k(function($k, $v) use($ds, $now) {return
		df_dts($now, implode($ds, $v))
	;}, ['date' => ['y', 'MM', 'dd'], 'time' => ['HH', 'mm'], 'time-full' => ['HH', 'mm', 'ss']]);
	/**
	 * 2016-11-09
	 * @see \Zend_Date неправильно работает с миллисекундами:
	 * всегда возвращает 0 вместо реального количества миллисекунд.
	 * Так происходит из-за дефекта в методах
	 * @see \Zend_Date::addMilliSecond()
	 * @see \Zend_Date::setMilliSecond()
	 * Там такой код:
	 *		list($milli, $time) = explode(" ", microtime());
	 *		$milli = intval($milli);
	 * https://github.com/OpenMage/magento-mirror/blob/1.9.3.0/lib/Zend/Date.php#L4490-L4491
	 * Этот код ошибочен, потому что после первой операции
	 * $milli содержит дробное значение меньше 1, например: 0.653...
	 * А вторая операция тупо делает из этого значения 0.
	 */
	$vars['time-full-ms'] = implode($ds, [$vars['time-full'],
		sprintf('%02d', round(100 * df_first(explode(' ', microtime()))))
	]);
	while (true) {
		/** @var string $fileName */
		$fileName = df_var($template, ['ordering' => sprintf('%03d', $counter)] + $vars);
		/** @var string $fileFullPath */
		$fileFullPath = $directory . DS . $fileName;
		if (!file_exists($fileFullPath)) {
			/**
			 * Раньше здесь стояло file_put_contents,
			 * и иногда почему-то возникал сбой:
			 * failed to open stream: No such file or directory.
			 * Может быть, такой сбой возникает, если папка не существует?
			 */
			$result = $fileFullPath;
			break;
		}
		else {
			if ($counter > 999) {
				df_error("Счётчик достиг предела ({$counter}).");
			}
			else {
				$counter++;
				/**
				 * Если в шаблоне имени файла
				 * нет переменной «{ordering}» — значит, надо добавить её,
				 * чтобы в следующей интерации имя файла стало уникальным.
				 * Вставляем «{ordering}» непосредственно перед расширением файла.
				 * Например, rm.shipping.log преобразуем в rm.shipping-{ordering}.log
				 */
				if (!$hasOrderingPosition && (2 === $counter)) {
					/** @var string[] $fileNameTemplateExploded */
					$fileNameTemplateExploded = explode('.', $template);
					/** @var int $secondFromLastPartIndex*/
					$secondFromLastPartIndex =  max(0, count($fileNameTemplateExploded) - 2);
					/** @var string $secondFromLastPart */
					$secondFromLastPart = dfa($fileNameTemplateExploded, $secondFromLastPartIndex);
					df_assert_sne($secondFromLastPart);
					$fileNameTemplateExploded[$secondFromLastPartIndex] =
						implode('--', [$secondFromLastPart, '{ordering}'])
					;
					$template = df_assert_ne($template, implode('.', $fileNameTemplateExploded));
				}
			}
		}
	}
	return df_path_n($result);
}

/**
 * @used-by df_report()
 * @param string $path
 * @param mixed $contents
 * @throws DFE
 */
function df_file_put_contents($path, $contents) {
	DfPath::createAndMakeWritable(df_param_sne($path, 0));
	df_assert(false !== file_put_contents($path, df_dump($contents)));
}

/**
 * 2015-12-08
 * @param string $directory
 * @param string $relativeFileName
 * @return string
 */
function df_file_read($directory, $relativeFileName) {
	/** @var DirectoryRead|DirectoryReadInterface $reader */
	$reader = df_fs_r($directory);
	/** @var FileReadInterface|FileRead $file */
	$file = $reader->openFile($relativeFileName, 'r');
	/** @var string $result */
	try {
		$result = $file->readAll();
	}
	finally {
		$file->close();
	}
	return $result;
}

/**
 * 2015-11-29
 * 2015-11-30
 * Иерархия папок создаётся автоматически:
 * @see \Magento\Framework\Filesystem\Directory\Write::openFile()
 * https://github.com/magento/magento2/blob/2.0.0/lib/internal/Magento/Framework/Filesystem/Directory/Write.php#L247
 * @used-by df_sync()
 * @used-by \Df\GoogleFont\Font\Variant::ttfPath()
 * @used-by \Df\GoogleFont\Fonts\Png::create()
 * @used-by \Df\GoogleFont\Fonts\Sprite::draw()
 * @param string|string[] $path
 * @param string $contents
 */
function df_file_write($path, $contents) {
	/** @var string $directory */
	/** @var string $relativeFileName */
	list($directory, $relativeFileName) = is_array($path) ? $path : [dirname($path), basename($path)];
	/** @var DirectoryWrite|DirectoryWriteInterface $writer */
	$writer = df_fs_w($directory);
	/** @var FileWriteInterface|FileWrite $file */
	$file = $writer->openFile($relativeFileName, 'w');
	/**
	 * 2015-11-29
	 * По аналогии с @see \Magento\MediaStorage\Model\File\Storage\Synchronization::synchronize()
	 * https://github.com/magento/magento2/blob/2.0.0/app/code/Magento/MediaStorage/Model/File/Storage/Synchronization.php#L61-L68
	 * Обратите внимание, что к реализации этого метода у меня аж 4 замечания:
	 *
	 * 1) https://mage2.pro/t/274
	 * «\Magento\MediaStorage\Model\File\Storage\Synchronization::synchronize() wrongly leaves a file in the locked state in case of an exception»
	 *
	 * 2) https://mage2.pro/t/271
	 * «\Magento\MediaStorage\Model\File\Storage\Synchronization::synchronize() suppresses its exceptions for a questionably reason»
	 *
	 * 3) https://mage2.pro/t/272
	 * «\Magento\MediaStorage\Model\File\Storage\Synchronization::synchronize() duplicates the code in the try and catch blocks, propose to use a «finally» block»
	 *
	 * 4) https://mage2.pro/t/273
	 * «\Magento\MediaStorage\Model\File\Storage\Synchronization::synchronize() contains a wrong PHPDoc comment for the $file variable»
	 */
	try {
		$file->lock();
		try {
			$file->write($contents);
		}
		finally {
			$file->unlock();
		}
	}
	finally {
		$file->close();
	}
}

/**
 * 2015-11-29
 * @return \Magento\Framework\Filesystem
 */
function df_fs() {return df_o(\Magento\Framework\Filesystem::class);}

/**
 * 2017-04-03
 * Портировал из РСМ. Никем не используется.
 * @param string $path
 */
function df_fs_delete($path) {File::rmdirRecursive(df_param_sne($path, 0));}

/**
 * 2015-11-29
 * Преобразует строку таким образом,
 * чтобы её было безопасно и удобно использовать в качестве имени файла или папки.
 * http://stackoverflow.com/a/2021729
 * 2017-02-09
 * Сегодня заметил, что эта функция удаляет пробелы, но сохраняет символы Unicode:
 * '歐付寶 all/Pay' => '歐付寶-allPay'

 * Пример №1: '歐付寶 all/Pay':
 * @see df_fs_name => 歐付寶-allPay
 * @see df_translit =>  all/Pay
 * @see df_translit_url => all-Pay
 * @see df_translit_url_lc => all-pay
 *
 * Пример №2: '歐付寶 allPay':
 * @see df_fs_name => 歐付寶-allPay
 * @see df_translit =>  allPay
 * @see df_translit_url => allPay
 * @see df_translit_url_lc => allpay
 *
 * @param string $name
 * @param string $spaceSubstitute [optional]
 * @return string
 */
function df_fs_name($name, $spaceSubstitute = '-') {
	$name = str_replace(' ', $spaceSubstitute, $name);
	// Remove anything which isn't a word, whitespace, number
	// or any of the following caracters -_~,;:[]().
	// If you don't need to handle multi-byte characters
	// you can use preg_replace rather than mb_ereg_replace
	// Thanks @Łukasz Rysiak!
	$name = mb_ereg_replace("([^\w\s\d\-_~,;:\[\]\(\).])", '', $name);
	// Remove any runs of periods (thanks falstro!)
	return mb_ereg_replace("([\.]{2,})", '', $name);
}

/**
 * 2015-11-30
 * @used-by df_media_reader()
 * @param string $path
 * @return DirectoryRead|DirectoryReadInterface
 */
function df_fs_r($path) {return df_fs()->getDirectoryRead($path);}

/**
 * 2015-11-29
 * @used-by df_media_writer()
 * @used-by df_sync()
 * @param string $path   Например: DirectoryList::MEDIA
 * @return DirectoryWrite|DirectoryWriteInterface
 */
function df_fs_w($path) {return df_fs()->getDirectoryWrite($path);}

/**
 * 2015-08-14
 * https://mage2.pro/t/57
 * https://mage2.ru/t/92
 *
 * 2015-09-02
 * Метод @uses \Magento\Framework\Module\Dir\Reader::getModuleDir()
 * в качестве разделителя путей использует не DIRECTORY_SEPARATOR, а /
 *
 * 2016-11-17
 * В качестве $m можно передавать:
 * 1) Имя модуля. «A_B»
 * 2) Имя класса. «A\B\C»
 * 3) Объект класса.
 *
 * Результат намеренно не кэшируем,
 * потому что @uses \Magento\Framework\Module\Dir\Reader::getModuleDir() его отлично сам кэширует.
 *
 * @used-by \Df\Core\O::modulePath()
 * @used-by df_test_file()
 * @param string|object $m
 * @param string $type [optional]
 * @return string
 * @throws \InvalidArgumentException
 */
function df_module_dir($m, $type = '') {
	/** @var ModuleDirReader $reader */
	$reader = df_o(ModuleDirReader::class);
	return $reader->getModuleDir($type, df_module_name($m));
}

/**
 * 2017-01-27
 * @used-by \Dfe\AllPay\W\Handler::typeLabelByCode()
 * В качестве $m можно передавать:
 * 1) Имя модуля. «A_B»
 * 2) Имя класса. «A\B\C»
 * 3) Объект класса.
 * @param string|object $m
 * @param string $name
 * @param bool $req [optional]
 * @return array(string => mixed)
 */
function df_module_json($m, $name, $req = true) {return dfcf(function($m, $name, $req = true) {return
	file_exists($f = df_module_path_etc($m, "$name.json"))
		? df_json_decode(file_get_contents($f))
		: (!$req ? [] : df_error("The required file «{$f}» is absent."))
;}, func_get_args());}

/**
 * 2015-11-15
 * 2015-09-02
 * Метод @uses \Magento\Framework\Module\Dir\Reader::getModuleDir()
 * и, соответственно, @uses df_module_dir()
 * в качестве разделителя путей использует не DIRECTORY_SEPARATOR, а /,
 * поэтому и мы поступаем так же.
 *
 * 2016-11-17
 * В качестве $m можно передавать:
 * 1) Имя модуля. «A_B»
 * 2) Имя класса. «A\B\C»
 * 3) Объект класса.
 *
 * @param string|object $m
 * @param string $localPath [optional]
 * @return string
 * @throws \InvalidArgumentException
 */
function df_module_path($m, $localPath = '') {return df_cc_path(df_module_dir($m), $localPath);}

/**
 * 2016-07-19
 * 2015-09-02
 * Метод @uses \Magento\Framework\Module\Dir\Reader::getModuleDir()
 * и, соответственно, @uses df_module_dir()
 * в качестве разделителя путей использует не DIRECTORY_SEPARATOR, а /,
 * поэтому и мы поступаем так же.
 *
 * 2016-11-17
 * В качестве $m можно передавать:
 * 1) Имя модуля. «A_B»
 * 2) Имя класса. «A\B\C»
 * 3) Объект класса.
 *
 * @used-by df_module_json()

 * @param string|object $m
 * @param string $localPath [optional]
 * @return string
 * @throws \InvalidArgumentException
 */
function df_module_path_etc($m, $localPath = '') {return df_cc_path(
	df_module_dir($m, ModuleDir::MODULE_ETC_DIR), $localPath
);}

/**
 * 2015-12-06
 * @used-by df_sync()
 * @param string $directory
 * @param string $path [optional]
 * @return string
 * Результат вызова @uses \Magento\Framework\Filesystem\Directory\Read::getAbsolutePath()
 * завершается на «/»
 */
function df_path_absolute($directory, $path = '') {
	return df_prepend(df_trim_ds_left($path), df_fs_r($directory)->getAbsolutePath());
}

/**
 * Заменяет все сиволы пути на /
 * @param string $path
 * @return string
 */
function df_path_n($path) {return str_replace('\\', '/', $path);}

/**
 * 2016-12-30
 * Заменяет все сиволы пути на BP
 * @param string $path
 * @return string
 */
function df_path_n_real($path) {return strtr($path, ['\\' => DS, '/' => DS]);}

/**
 * 2015-12-06
 * Левый «/» мы убираем.
 * Результат вызова @uses \Magento\Framework\Filesystem\Directory\Read::getAbsolutePath()
 * завершается на «/»
 * @param string $path
 * @param string $base [optional]
 * @return string
 */
function df_path_relative($path, $base = BP) {return
	df_trim_ds_left(df_trim_text_left(
		df_path_n($path), df_trim_ds_left(df_fs_r($base)->getAbsolutePath())
	))
;}

/**
 * 2015-04-01
 * Раньше алгоритм был таким: return preg_replace('#\.[^.]*$#', '', $file)
 * Новый вроде должен работать быстрее?
 * http://stackoverflow.com/a/22537165
 * @used-by Df_Adminhtml_Catalog_Product_GalleryController::uploadActionDf()
 * @used-by
 * @param string $file
 * @return mixed
 */
function df_strip_ext($file) {return pathinfo($file, PATHINFO_FILENAME);}

/**
 * 2016-10-14
 * @param string $path
 * @return string
 */
function df_trim_ds($path) {return df_trim($path, '/\\');}

/**
 * 2015-11-30
 * @param string $path
 * @return string
 */
function df_trim_ds_left($path) {return df_trim_left($path, '/\\');}

/**
 * 2016-10-14
 * @param string $path
 * @return string
 */
function df_trim_ds_right($path) {return df_trim_right($path, '/\\');}