<?php
/*
Класс обработки сложных запросов выборки данных из одной или нескольких таблиц.
Возвращает результат -  одно/двумерный массив
Результаты работы некоторых методов кэшируются внутри класса.
Кэширование можно отключить напрямую в классе, изменив значение флага кэшировния, или из вне класса с помощью метода setCacheMetodsResult().
	Ferrous
*/
class Selector
{
	var	$db; //обьект базы данных
	
	var $table_list			= array();	//список подключенных таблиц
	var $column_list		= array();	//список возвращаемых полей
	var $where_list			= array();	//список критериев
	var $order_list			= array();	//список полей для упорядочивания
	var $group_list			= array();	//список полей для группировки
	var $on_list			= array();	//список критериев для объединения JOIN
	var $join_list			= array();	//список объектов для объединения JOIN
	var $union_list			= array();	//список объектов для объединения UNION
	
	var $check				= array();	//список флагов обработки данных методом $this->checkArray()
	
	var $sql_cache			= false;	//флаг кэширования SQL запросов
	var $cache_metods_result= true;		//флаг кэширования результатов работы методов
	var $union_isolation	= false;		//флаг изоляции основного запроса в объединении UNION
	
	//постраничный вывод
	var $index				= 'page';	//имя переменной, в которой передается номер страницы методом GET
	var $page_memory		= false;	//флаг памяти номера страницы (для использования этого режима должна быть открыта сессия)
	var $key				= null;	//имя ключа массива строки результатата, значение которого будет использовано в качестве номера строки (если не задано - номер генерируется автоматически)
	var $value				= null;	//имя ключа массива строки результатата, значение которого будет использовано в качестве значения строки (если не задано - значением строки будет одномерный массив)
	var $page_url			= array('previous'=>null, 'next'=>null, 'first'=>null, 'final'=>null, 'all'=>null, 'first_segment'=>null, 'final_segment'=>null); //URL предыдущей, следуюшей, первой и последней страниц, отключения разбивки на страницы
	var $begin_row;			//начальная строка результата
	var $begin_page;		//начальная страница результата
	var $counter;			//общее число строк в результате
	var $num_rows;			//число строк в результате на одной странице
	
	//кэш результатов работы методов
	private $sql;			//базовый SQL запрос
	private $page;			//номер текущей страницы
	private $page_menu;		//строка-меню выбора страниц
	private $num_pages;		//число страниц
	
	//параметры меню выбора страниц (не имеют специальных методов установки)
	var $page_menu_a_options	= null; 				//опции сссылки
	var $page_menu_separator	= ', '; 				//разделитель ссылок
	var $page_menu_selected		= '';					//шаблон для номера текущей страницы
	var $page_menu_selected_style	= array('<b>', '</b>');					//шаблон для номера текущей страницы
	var $page_menu_prefix		= array('', '');		//шаблон для номера текущей страницы
	
	var $page_menu_direction		= false;					//включить навигации по меню
	var $page_menu_direction_disabled		= '';				//шаблон контейнера неактивной/выключеной кнопки навигации
	var $page_menu_direction_disabled_style	= array();		//шаблон неактивной/выключеной кнопки навигации
	var $page_menu_direction_prefix	= array('<', '>');		//шаблон для кнопок навигации
	
	/*
	коснструктор
	создает ссылку на объект базы данных
	$table_name - имя таблицы
	$check_html - флаг обработки html методом $this->checkArray()
	$check_empty - флаг обработки пустых строк методом $this->checkArray()
	*/
	function __construct($table_name=null, $check_html=true, $check_empty=false)
	{
		if($table_name)
			$this->table_list[] = $table_name;
		
		$this->check['html'] = $check_html;
		$this->check['empty'] = $check_empty;
		
		global $db;
		
		$this->setDb($db);
	}
	
	/*
	метод установки базы данных
	$db_object - объект базы данных
		Если не задан, будет использован обьект базы данных по умолчанию
	*/
	function setDb($db_object)
	{
		if(!is_object($db_object))
			exit('Error: '.__METHOD__.' - passed not object');
		
		$this->db = $db_object;
	}
	
	/*
	метод добавления таблиц
	$table_name - имя таблицы
	*/
	function setTable($table_name)
	{
		$this->table_list[] = $table_name;
	}
	
	/*
	метод добавления возвращаемых полей
	$column_name - имя поля
	*/
	function setColumn($column_name)
	{
		$this->column_list[] = $column_name;
	}
	
	/*
	метод добавления критериев
	$where - критерий
	$value - значение критерия
		Не обязательный параметр.
		Если задан, то
			если строка - взять в одинарные кавычки.
	*/
	function setWhere($where, $value=null)
	{
		if($value!==null)
		{
			$value = $this->db->preData($value);
			
			if(is_array($value))
				$value = '('.implode($value, ', ').')';
		}
		
		$this->where_list[] = $where.$value;
	}
	
	/*
	метод установки поля упорядочивания
	$column - имя поля, по которому нужно упорядочить
	$desc - направление упорядочивания
	*/
	function setOrder($column)
	{
		$this->order_list[] = $column;
	}
	
	/*
	метод установки поля группировки
	$column - имя поля, по которому нужно группировать
	$desc - направление упорядочивания
	*/
	function setGroup($column)
	{
		$this->group_list[] = $column;
	}
	
	/*
	метод установки ограничения возвращаемых строк
	$begin - номер начальной строки
	$rows - число строк
		Если не задан, то возвращается количество строк, указанное в параметре $begin
	*/
	function setLimit($begin, $rows=null)
	{
		$this->begin_row = $begin;
		$this->num_rows = $rows;
	}
	
	/*
	метод установки флага кэширования
	*/
	function setCacheMetodsResult($value)
	{
		$this->cache_metods_result = $value;
	}

	/*
	метод установки флага кэширования SQL запросов
	*/
	function setCache($value)
	{
		$this->sql_cache = $value;
	}
	
	/*
	метод установки имени переменной с номером страницы
	*/
	function setIndex($index)
	{
		$this->index = $index;
	}
	
	/*
	метод включения/выключения памяти номера страницы
	*/
	function pageMemory($memory=true)
	{
		$this->page_memory = $memory;
	}
	
	/*
	метод установки ключа номера строки
	*/
	function setKey($key)
	{
		$this->key = $key;
	}
	
	/*
	метод установки ключа значения строки
	*/
	function setValue($value)
	{
		$this->value = $value;
	}
	
	/*
	метод установки числа строк
	*/
	function setNumRows($num_rows)
	{
		$this->num_rows = $num_rows;
	}
	
	/*
	метод установки начальной страницы результата
	*/
	function setBeginPage($begin_page)
	{
		$this->begin_page = $begin_page;
	}
	
	/*
	методо установки флагов обработки данных функцией $this->checkArray()
	*/
	function setCheck($check)
	{
		$this->check = $check;
	}
	
	/*
	метод сборки SQL запроса
	возвращает строку SQL запроса
	Если флаг кэширования результатов работы методов поднят, то результат выполнения этого метода кэшируется
	*/
	function getSql()
	{
		if(empty($this->sql) or empty($this->cache_metods_result))
		{
			$sql = '';
			
			if($this->union_list and $this->union_isolation)
				$sql .= '(';
			
			$sql .= 'select';
			
			if($this->sql_cache)
				$sql .= ' SQL_CACHE';
			
			if($this->num_rows)
				$sql .= ' SQL_CALC_FOUND_ROWS';
			
			if(!empty($this->column_list))
				$sql .= ' '.implode($this->column_list, ', ');
			else
				$sql .= ' *';
			
			if(!empty($this->table_list))
				$sql .= ' from '.implode($this->table_list, ', ');
			
			if(!empty($this->join_list))
			{
				foreach($this->join_list as $join)
				{
					if($join['type'])
						$sql .= ' '.$join['type'];
					
					$sql .= ' JOIN';
					
					$sql .= ' '.implode($join['selector']->table_list, ', ');
					
					$sql .= ' on '.implode($join['selector']->on_list, ' and ');
				}
			}
			
			if(!empty($this->where_list))
				$sql .= ' where '.implode($this->where_list, ' and ');
			
			if(!empty($this->group_list))
				$sql .= ' group by '.implode($this->group_list, ', ');
			
			if($this->union_list and !$this->union_isolation)
				$sql .= $this->getUnionSql();
			
			if(!empty($this->order_list))
				$sql .= ' order by '.implode($this->order_list, ', ');
			
			if($this->begin_row!==null)
			{
				$sql .= ' limit '.$this->begin_row;
				if($this->num_rows!==null)
					$sql .= ', '.$this->num_rows;
			}
			
			if($this->union_list and $this->union_isolation)
			{
				$sql .= ')';
				
				$sql .= $this->getUnionSql();
			}
			
			$this->sql = $sql;
		}
		else
			$sql = $this->sql;
		
		return $sql;
	}
	
	private function getUnionSql()
	{
		$sql = '';
		
		foreach($this->union_list as $union)
		{
			$sql .= ' union ';
			
			if($union['all'])
				$sql .= 'all ';
			
			$isolation = $this->union_isolation;
			
			if($union['selector']->order_list or $union['selector']->begin_row!==null)
				$isolation = true;
			
			if($isolation)
				$sql .= '(';
			
			$sql .= $union['selector']->getSql();
			
			if($isolation)
				$sql .= ')';
		}
		
		return $sql;
	}
	
	/*
	метод поиска html тэгов в строке
	Если в строке присутсвтуют тэги - вернуть строку без изменений
	Иначе применить к строке функцию nl2br()
	$str - строка
	*/
	function searchHtml($str)
	{
		if((strstr($str, '<') or strstr($str, '</')) and (strstr($str, '>') or strstr($str, '/>')))
			return $str;
		else
			return nl2br($str);
	}
	
	/*
	рекурсивный метод обработки одно/многомерных массивов или отдельных значений
	Если элемент массива не имеет значения - поместить в него символ "неразрывный пробел"
	Если элемент массива - массив - применить к нему $this->checkArray().
	Применяет метод searchHtml() к непустым элементам.
	Возвращает массив, не содержащий пустых элементов.
	$arr - массив по ссылке
	$html - флаг проверки HTML
	$empty - флаг вставки неразрывных пробелов
	*/
	function checkArray(&$arr, $html=true, $empty=false)
	{
		if(!$html and !$empty)
			return;
		
		if(is_array($arr))
			foreach($arr as &$value)
				$this->checkArray($value, $html, $empty);
		elseif($empty and $arr=='')
			$arr = '&nbsp;';
		elseif($html and !is_numeric($arr) and $arr!='')
			$arr = $this->searchHtml($arr);
	}
	
	/*
	метод возвращает результат выборки
	$format - флаг принудительного изменения формата возвращаемого результата (не обязательный параметр, по умолчанию поднят).
		Если флаг не определен - формат результата определяется автоматически
		Если флаг опущен - результат возвращается в виде одномерного массива
		Иначе в виде двумерного массива
	$field - имя поля, значение которого нужно вернуть
		Если флаг формата опущен и имя поля задано - возвращается уже не одномерный массив, а единственное значение заданного поля
	*/
	function getResult($format=true, $field=null, $use_memcache = true)
	{
		$sql = $this->getSql();

		if (USE_MEMCACHE && $use_memcache)
		{
			$result = $this->db->getDataFromMemcache(md5($sql));
			if ($result !== false)
			{
				$this->db->count_sql_memcache++;

				return $result;
			}
		}

		$res = $this->db->Query($sql);
		if(!$res)
			return false;
		
		if($format===null)
		{
			if($this->db->getNum($res)>1)
				$result = $this->db->DataFull($res, $this->key, $this->value);
			else
				$result = $this->db->Data($res);
		}
		elseif($format)
			$result = $this->db->DataFull($res, $this->key, $this->value);
		else
			$result = $this->db->Data($res, $field);
		
		if($format and $field)
			return $result;
		
		$this->checkArray($result, $this->check['html'], $this->check['empty']);
		
		$this->db->setDataToMemcache(md5($sql), $result);
		
		return $result;
	}
	
	/*
	возвращает результат запроса в виде двумерного массива с числовыми ключами (используется для построения ниспадающих списков)
	*/
	function getResultOptions()
	{
		$sql = $this->getSql();
		$res = $this->db->Query($sql);
		
		$options = array();
		
		$result = $this->db->DataFull($res, $this->column_list[0], $this->column_list[1]);
		
		if($result)
			$options = $result;
		
		return $options;
	}
	
	/*
	метод возвращает номер текущей страницы
	Если номер страницы передан методом GET - принять это значение за номер страницы
	Иначе если задан атрибут начальная страница - принять это значение за номер страницы
	Иначе принять номер страницы равным 1
	Если флаг кэширования результатов работы методов поднят, то результат выполнения этого метода кэшируется
	*/
	function getPage()
	{
		if(empty($this->page) or empty($this->cache_metods_result))
		{
			$index = $this->index;
			
			if($this->page_memory)
				$hash = md5($this->getSql());
			
			if(isset($_GET[$index]) and $_GET[$index])
				$page_current = $_GET[$index];
			elseif($this->page_memory and isset($_SESSION['SELECTOR_PAGE_MEMORY'][$hash]) and !empty($_SESSION['SELECTOR_PAGE_MEMORY'][$hash]))
				$page_current = $_SESSION['SELECTOR_PAGE_MEMORY'][$hash];
			else
				$page_current = null;
			
			if($page_current)
			{
				if($page_current=='all')
					$page = 'all';
				elseif(is_numeric($page_current) and $page_current>0)
					$page = (int)$page_current;
				else
					$page = 1;
			}
			elseif(!empty($this->begin_page))
				$page = $this->begin_page;
			else
				$page = 1;
			
			if($this->page_memory)
				$_SESSION['SELECTOR_PAGE_MEMORY'][$hash] = $page;
			
			$this->page = $page;
		}
		else
			$page = $this->page;
		
		return $page;
	}
	
	/*
	метод возвращает число страниц в результате
	$num_rows - число строк на странице
		Не обязательный параметр. по умолчанию ничему не равен
		Если он задан, то число строк сохраняется в атрибуте $this->num_rows
		и при последущем вызове методов с таким же необязательным параметром это значение будет взято из атрибута
		Иначе если атрибут $this->num_rows уже имеет значение, то число строк будет взято из этого атрибута
	*/
	function getNumPages($num_rows=null)
	{
		if(empty($this->num_pages) or empty($this->cache_metods_result))
		{
			if(!empty($num_rows))	
				$this->setNumRows($num_rows);
			
			$sql = 'SELECT FOUND_ROWS()';
			
			$res = $this->db->Query($sql);
			
			$counter = $this->db->Data($res, 'FOUND_ROWS()');
			
			$this->counter = $counter;
			
			$num_pages = ceil($counter/$this->num_rows);
			
			$this->num_pages = $num_pages;
		}
		else
			$num_pages = $this->num_pages;
		
		return $num_pages;
	}
	
	/*
	метод осуществляет результат выборки, осуществляя разбивку данных на страницы
	возвращает результат в виде двумерного массива
	$num_rows - число строк на странице
		Не обязательный параметр. по умолчанию ничему не равен
		Если он задан, то число строк сохраняется в атрибуте $this->num_rows
		и при последущем вызове методов с таким же необязательным параметром это значение будет взято из атрибута
		Иначе если атрибут $this->num_rows уже имеет значение, то число строк будет взято из этого атрибута
	*/
	function getResultPage($num_rows=null)
	{
		if(!empty($num_rows))	
			$this->setNumRows($num_rows);
		else
			return $this->getResult();
		
		$sql = $this->getSql();
		$page = $this->getPage();
		
		if(is_numeric($page))
		{
			if($page)
				$begin_row = $this->num_rows*($page-1);
			else
				$begin_row = 0;
			
			$sql .= ' limit '.$begin_row.', '.$this->num_rows;
		}
		
		$res = $this->db->Query($sql);
		if($res)
		{
			$num_pages = $this->getNumPages();
			$num_rows = $this->db->getNum($res);
			
			if($num_pages and !$num_rows)
			{
				$this->page = $num_pages;
				
				return $this->getResultPage();
			}
			
			$result = $this->db->DataFull($res, $this->key, $this->value);
			
			$this->checkArray($result, $this->check['html'], $this->check['empty']);
			
			return $result;
		}
		else
			return false;
	}
	
	/*
	метод возвращает меню выбора страниц в виде строки ссылок
	Если флаг кэширования результатов работы методов поднят, то результат выполнения этого метода кэшируется
	$num_rows - число строк на странице
		Не обязательный параметр. по умолчанию ничему не равен
		Если он задан, то число строк сохраняется в атрибуте $this->num_rows
		и при последущем вызове методов с таким же необязательным параметром это значение будет взято из атрибута
		Иначе если атрибут $this->num_rows уже имеет значение, то число строк будет взято из этого атрибута
	$limit - ограничение числа ссылок
		Если задан, меню будет разбито на диапазоны с добавлением ссылки "..." в начале и в конце
	*/
	function getPageMenu($num_rows=null, $limit=null)
	{
		if(empty($this->page_menu) or empty($this->cache_metods_result))
		{
			if(!empty($num_rows))	
				$this->setNumRows($num_rows);
			
			$page = $this->getPage();
			$index = $this->index;
			$num_pages = $this->getNumPages();
			$page_menu = null;
			
			if($num_pages>1)
			{
				$pref = null;
				$url_get = explode('?', $_SERVER['REQUEST_URI']);
				if(isset($url_get[1]))
				{
					$url_list = preg_split('/'.$index.'=([[:alnum:]])*/', $url_get[1]);
					if(!isset($url_list[1]))
					{
						$url_list[1] = null;
						$pref = '&';
					}
				}
				else
					$url_list = array(null, null);
				
				$url_list[0] = '?'.$url_list[0];
				
				$this->page_url['first'] = $url_list[0].$pref.$index.'=1'.$url_list[1];
				$this->page_url['final'] = $url_list[0].$pref.$index.'='.$num_pages.$url_list[1];
				$this->page_url['all'] = $url_list[0].$pref.$index.'=all'.$url_list[1];
				
				if(is_numeric($page))
				{
					if($page>1)
						$this->page_url['previous'] = $url_list[0].$pref.$index.'='.($page-1).$url_list[1];
					if($page<$num_pages)
						$this->page_url['next'] = $url_list[0].$pref.$index.'='.($page+1).$url_list[1];
					
					$begin_i = 1;
					$end_i = $num_pages;
					$diapason_flag = false;
					
					if($limit and $limit>1)
					{
						$diapasons = $num_pages/$limit;
						$diapasons = ceil($diapasons);
						
						if($diapasons>1)
						{
							$diapason_flag = true;
							
							for($d=1; $d<=$diapasons; $d++)
							{
								$end_i = $begin_i+$limit-1;
								
								if($page>=$begin_i and $page<=$end_i)
									break;
								else
									$begin_i += $limit;
							}
							
							if($end_i>$num_pages)
								$end_i = $num_pages;
						}
					}
					
					if($diapason_flag and $begin_i>1)
					{
						$url = $url_list[0].$pref.$index.'='.($begin_i-1).$url_list[1];
						
						$page_menu .= $this->page_menu_prefix[0].'<a href="'.$url.'" '.$this->page_menu_a_options.'>...</a>'.$this->page_menu_prefix[1];
						
						$this->page_url['first_segment'] = false;
					}
					else
						$this->page_url['first_segment'] = true;
					
					for($i=$begin_i; $i<=$end_i; $i++)
					{
						$url = $url_list[0].$pref.$index.'='.$i.$url_list[1];
							
						if($i!=$page)
							$page_menu .= $this->page_menu_prefix[0].'<a href="'.$url.'" '.$this->page_menu_a_options.'>'.$i.'</a>'.$this->page_menu_prefix[1];
						else
						{
							if($this->page_menu_selected != '')
								$page_menu .= $this->page_menu_selected.'<a href="'.$url.'" '.$this->page_menu_a_options.'>'.$i.'</a>'.$this->page_menu_prefix[1];
							else
								$page_menu .= $this->page_menu_prefix[0].$this->page_menu_selected_style[0].$i.$this->page_menu_selected_style[1].$this->page_menu_prefix[1];
						}
						if($i!=$end_i)
							$page_menu .= $this->page_menu_separator;
					}
					
					if( $this->page_menu_direction )
					{
						if( $this->page_url['previous'] )
							$previous_page = $this->page_menu_prefix[0].'<a href="'.$this->page_url['previous'].'" '.$this->page_menu_a_options.'>'.$this->page_menu_direction_prefix[0].'</a>'.$this->page_menu_prefix[1];
						elseif( $this->page_menu_direction_disabled )
							$previous_page = $this->page_menu_direction_disabled.'<a href="##" '.$this->page_menu_a_options.'>'.$this->page_menu_direction_prefix[0].'</a>'.$this->page_menu_prefix[1];
						elseif( !empty($this->page_menu_direction_disabled_style) )
							$previous_page = $this->page_menu_prefix[0].$this->page_menu_direction_disabled_style[0].$this->page_menu_direction_prefix[0].$this->page_menu_direction_disabled_style[1].$this->page_menu_prefix[1];
						
						if( isset($previous_page) )
							$page_menu = $previous_page.$page_menu;
						
						if( $this->page_url['next'] )
							$next_page = $this->page_menu_prefix[0].'<a href="'.$this->page_url['next'].'" '.$this->page_menu_a_options.'>'.$this->page_menu_direction_prefix[1].'</a>'.$this->page_menu_prefix[1];
						elseif( $this->page_menu_direction_disabled )
							$next_page = $this->page_menu_direction_disabled.'<a href="##" '.$this->page_menu_a_options.'>'.$this->page_menu_direction_prefix[1].'</a>'.$this->page_menu_prefix[1];
						elseif( !empty($this->page_menu_direction_disabled_style) )
							$next_page = $this->page_menu_prefix[0].$this->page_menu_direction_disabled_style[0].$this->page_menu_direction_prefix[1].$this->page_menu_direction_disabled_style[1].$this->page_menu_prefix[1];
						
						if( isset($next_page) )
							$page_menu = $page_menu.$next_page;
					}
					
					if($diapason_flag and $end_i<$num_pages)
					{
						$url = $url_list[0].$pref.$index.'='.($end_i+1).$url_list[1];
						
						$page_menu .= $this->page_menu_prefix[0].'<a href="'.$url.'" '.$this->page_menu_a_options.'>...</a>'.$this->page_menu_prefix[1];
					
						$this->page_url['final_segment'] = false;
					}
					else
						$this->page_url['final_segment'] = true;
				}
			}
			$this->page_menu = $page_menu;
		}
		else
			$page_menu = $this->page_menu;
		
		return $page_menu;
	}
	
	/*
	создает условие для осуществления текстового поиска
	все методы возврата результата вернут результат поиска
	$word_list - строка поиска или массив строк поиска
	$column_list - имя столбца или массив имен столбцов, в которых производить поиск
	$or - флаг режима ИЛИ
		по умолчанию опущен
		если поднят - поиск производится в режиме ИЛИ
		иначе в режиме И
	*/
	function search($word_list, $column_list, $or=false)
	{
		if($word_list===null or $word_list=='')
			return;
		
		if(!$column_list)
			return;
		
		if(!is_array($word_list))
		{
			$word_list = preg_replace('/ +/', ' ', $word_list);
			$word_list = trim($word_list);
			if($word_list=='')
				return;
			
			$word_list = explode(' ', $word_list);
		}
		
		if(!is_array($column_list))
			$column_list = array($column_list);
		
		$where_or = array();
		
		if($or)
			$mode = ' OR ';
		else
			$mode = ' AND ';
		
		foreach($column_list as $column)
		{
			$where_and = array();
			
			foreach($word_list as $word)
			{
				$word = $this->db->preData('%'.$word.'%');
				
				$where_and[] = $column.' LIKE '.$word;
			}
			
			$where_or[] = '('.implode($mode, $where_and).')';
		}
		
		$where = implode(' OR ', $where_or);
		
		$this->setWhere('('.$where.')');
	}
	
	/*
	добавляет подзапрос для объединения UNION
	$table_name - имя таблицы или список имен таблиц через запятую для подзапроса (не обязательно)
	$all - флаг объединения UNION ALL
	возвращает объект дочернего селектора подзапроса
	*/
	function setUnion($table_name=null, $all=false)
	{
		$selector = new self($table_name);
		
		$this->union_list[] = array('selector'=>$selector, 'all'=>$all);
		
		return $selector;
	}
	
	/*
	устанавливает флаг принудительной изоляции всех подзапросов при объединении UNION
	если поднят
		элементы ORDER BY и LIMIT основного запроса будут влиять только на него
		все подзапросы и основной запрос будут изолированы друг от друга
		основной запрос становится одним из подзапросов
	иначе
		элементы ORDER BY и LIMIT основного запроса будут влиять на весь составной запрос
		если в подзапросах есть элементы ORDER BY или LIMIT подзапросы будут изолированны
		иначе ниодин подзапрос не будет изолирован
	
	$isolation - флаг принудительной изоляции (по умолчанию поднят)
	*/
	function unionIsolation($isolation=true)
	{
		$this->union_isolation = $isolation;
	}
	
	/*
	добавляет объединение JOIN
	$table_name - имя таблицы или список имен таблиц через запятую для объединения (не обязательно)
	$type - тип объединения {INNER | {LEFT | RIGHT | FULL} OUTER | CROSS} (не обязательно)
	возвращает объект дочернего селектора объединения
	*/
	function setJoin($table_name=null, $type=null)
	{
		$selector = new self($table_name);
		
		$this->join_list[] = array('selector'=>$selector, 'type'=>$type);
		
		return $selector;
	}
	
	/*
	метод добавления критериев объединения JOIN
	используется для дочерних селекторов, возвращаемых методом setJoin()
	$on - критерий
	$value - значение критерия
		Не обязательный параметр.
		Если задан, то
			если строка - взять в одинарные кавычки
	*/
	function setOn($on, $value=null)
	{
		if($value!==null)
		{
			$value = $this->db->preData($value);
			
			if(is_array($value))
				$value = '('.implode($value, ', ').')';
		}
		
		$this->on_list[] = $on.$value;
	}
}
?>