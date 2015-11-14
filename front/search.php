<?php
/******************************************************************************
 *
 * Subrion - open source content management system
 * Copyright (C) 2015 Intelliants, LLC <http://www.intelliants.com>
 *
 * This file is part of Subrion.
 *
 * Subrion is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Subrion is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Subrion. If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link http://www.subrion.org/
 *
 ******************************************************************************/

if (iaView::REQUEST_HTML != $iaView->getRequestType())
{
	return ;
}

$iaDb->setTable('search');

$iaView->display('search');

$iaField = $iaCore->factory('field');

$results = null;

function searchableFields($iaDb, $aItems, $types = null)
{
	$result = array();
	$stmt = "`adminonly` = 0 AND `searchable` = 1 AND `item` IN('" . implode("', '", $aItems) . "') ";

	if (is_array($types))
	{
		$stmt .= sprintf(" AND `type` IN ('%s') ", implode("', '", $types));
	}

	$fields = $iaDb->all(array('name', 'item', 'values', 'type', 'show_as'), $stmt, null, null, iaField::getTable());

	foreach ($fields as $f)
	{
		if ('combo' == $f['type'] || 'radio' == $f['type'] || 'checkbox' == $f['type'])
		{
			$f['type'] = $f['show_as'];
			$values = array();
			foreach (explode(',', $f['values']) as $key)
			{
				$values[$key] = iaLanguage::get("field_{$f['name']}_{$key}");
			}
			$f['values'] = $values;
		}

		$result[$f['item']][$f['name']] = $f;
	}

	return $result;
}

function searchMatch($searchFields = array(), $imp = ' OR ')
{
	$match = array();
	if ($searchFields && is_array($searchFields))
	{
		foreach ($searchFields as $fname => $data)
		{
			if (!isset($data['val']))
			{
				$data['val'] = '';
			}

			if ('LIKE' == $data['cond'])
			{
				$data['val'] = "%{$data['val']}%";
			}
			// for multiple values, like combo or checkboxes
			if (is_array($data['val']))
			{
				if ('!=' == $data['cond'])
				{
					$data['cond'] = count($data['val']) > 1 ? 'NOT IN' : '!=';
				}
				else
				{
					$data['cond'] = count($data['val']) > 1 ? 'IN' : '=';
				}
				$data['val'] = count($data['val']) > 1 ? '(' . implode(',', $data['val']) . ')' : array_shift($data['val']);
			}
			elseif ('NOTEMPTY' == $data['cond'])
			{
				$data['cond'] = '!=';
				$data['val'] = "''";
			}
			elseif (preg_match('/^(\d+)\s*-\s*(\d+)$/', $data['val'], $range))
			{
				// search in range
				$data['cond'] = sprintf('BETWEEN %d AND %d', $range[1], $range[2]);
				$data['val'] = '';
			}
			else
			{
				$data['val'] = "'" . iaSanitize::sql($data['val']) . "'";
			}

			$match[] = " `{$fname}` {$data['cond']} {$data['val']} ";
		}
	}

	return implode($imp, $match);
}


function searchThroughBlocks($query)
{
	$iaCore = iaCore::instance();
	$iaDb = &$iaCore->iaDb;

	$sql = 'SELECT '
			. 'b.`name`, b.`external`, b.`filename`, b.`title`, '
			. 'b.`extras`, b.`sticky`, b.`contents`, b.`type`, b.`header`, '
			. 'o.`page_name` `page` '
		. 'FROM `:prefix:table_blocks` b '
		//. 'LEFT JOIN `:prefix:table_language` l ON () '
		. "LEFT JOIN `:prefix:table_objects` o ON (o.`object` = b.`id` AND o.`object_type` = 'blocks' AND o.`access` = 1) "
		. "WHERE b.`type` IN('plain','smarty','html') "
			. "AND b.`status` = ':status' "
			. "AND b.`extras` IN (':extras') "
			. "AND (CONCAT(b.`contents`,IF(b.`header` = 1, b.`title`, '')) LIKE ':query' OR b.`external` = 1) "
			. 'AND o.`page_name` IS NOT NULL '
		. 'GROUP BY b.`id`';

	$sql = iaDb::printf($sql, array(
		'prefix' => $iaDb->prefix,
		'table_blocks' => 'blocks',
		'table_objects' => 'objects_pages',
		//'table_language' => 'language',
		'status' => iaCore::STATUS_ACTIVE,
		'query' => '%' . iaSanitize::sql($query) . '%',
		'extras' => implode("','", $iaCore->get('extras'))
	));


	$blocks = array();

	if ($rows = $iaDb->getAll($sql))
	{
		$extras = $iaDb->keyvalue(array('name', 'type'), iaDb::convertIds(iaCore::STATUS_ACTIVE, 'status'), 'extras');

		foreach ($rows as $row)
		{
			$pageName = empty($row['page']) ? $iaCore->get('home_page') : $row['page'];

			if (empty($pageName))
			{
				continue;
			}

			if ($row['external'])
			{
				switch ($extras[$row['extras']])
				{
					case 'package':
						$fileName = explode(':', $row['filename']);
						array_shift($fileName);
						$tpl = IA_HOME . 'packages/' . $row['extras'] . '/templates/common/' . $fileName[0];
						break;
					case 'plugin':
						$fileName = explode(':', $row['filename']);
						$fileName = end($fileName);
						$tpl = IA_HOME . 'plugins/' . $row['extras'] . '/templates/front/' . $fileName;
						break;
					default:
						$tpl = IA_HOME . 'templates/' . $row['extras'] . IA_DS;
				}

				$content = @file_get_contents($tpl);

				if (false === $content)
				{
					continue;
				}

				$content = stripSmartyTags(iaSanitize::tags($content));

				if (false === stripos($content, $query))
				{
					continue;
				}
			}
			else
			{
				switch ($row['type'])
				{
					case 'smarty':
						$content = stripSmartyTags(iaSanitize::tags($row['contents']));
						break;
					case 'html':
						$content = iaSanitize::tags($row['contents']);
						break;
					default:
						$content = $row['contents'];
				}
			}

			isset($blocks[$pageName]) || $blocks[$pageName] = array();

			$blocks[$pageName][] = array(
				'title' => $row['header'] ? $row['title'] : null,
				'content' => extractSnippet($content, $query)
			);
		}
	}

	return $blocks;
}

function searchByPages($query, &$results)
{
	$iaCore = iaCore::instance();
	$iaDb = &$iaCore->iaDb;
	$iaSmarty = &$iaCore->iaView->iaSmarty;
	$iaPage = $iaCore->factory('page', iaCore::FRONT);

	$stmt = '`value` LIKE :query AND `category` = :category AND `code` = :language ORDER BY `key`';
	$iaDb->bind($stmt, array(
		'query' => '%' . iaSanitize::sql($query) . '%',
		'category' => iaLanguage::CATEGORY_PAGE,
		'language' => $iaCore->iaView->language
	));

	$pages = array();

	if ($rows = $iaDb->all(array('key', 'value'), $stmt, null, null, iaLanguage::getTable()))
	{
		foreach ($rows as $row)
		{
			$pageName = str_replace(array('page_title_', 'page_content_'), '', $row['key']);
			$key = (false === stripos($row['key'], 'page_content_')) ? 'title' : 'content';
			$value = iaSanitize::tags($row['value']);

			isset($pages[$pageName]) || $pages[$pageName] = array();

			if ('content' == $key)
			{
				$value = extractSnippet($value, $query);
				if (empty($pages[$pageName]['title']))
				{
					$pages[$pageName]['title'] = iaLanguage::get('page_title_' . $pageName);
				}
			}

			$pages[$pageName]['url'] = $iaPage->getUrlByName($pageName, false);
			$pages[$pageName][$key] = $value;
		}
	}

	// blocks content will be printed out as a pages content
	if ($blocks = searchThroughBlocks($query))
	{
		foreach ($blocks as $pageName => $blocksData)
		{
			if (isset($pages[$pageName]))
			{
				$pages[$pageName]['extraItems'] = $blocksData;
			}
			else
			{
				$pages[$pageName] = array(
					'url' => $iaPage->getUrlByName($pageName),
					'title' => iaLanguage::get('page_title_' . $pageName),
					'content' => '',
					'extraItems' => $blocksData
				);
			}
		}
	}

	if ($pages)
	{
		$iaSmarty->assign('pages', $pages);

		$results['num']+= count($pages);
		$results['html']['pages'] = $iaSmarty->fetch('search-list-pages.tpl');
	}
}

function extractSnippet($text, $query)
{
	$result = $text;

	if (strlen($text) > 500)
	{
		$start = stripos($result, $query);
		$result = '…' . substr($result, -30 + $start, 250);
		if (strlen($text) > strlen($result)) $result.= '…';
	}

	return $result;
}

function stripSmartyTags($content)
{
	return preg_replace('#\{.+?\}#im', '', $content);
}



$fields = array();
$search = false; //search parameters
$adv = ('advsearch' == $iaView->name()); // advanced search flag
$template = '';
$limit = 15;

// you can fill additional custom WHERE clause for query in hook
$customWhere = '';

$conditions = array();
$conditions['number'] = array(
	'=' => iaLanguage::get('search_equal'),
	'!=' => iaLanguage::get('search_not_equal'),
	'>' => iaLanguage::get('search_greater'),
	'>=' => iaLanguage::get('search_greater_equal'),
	'<' => iaLanguage::get('search_lower'),
	'<=' => iaLanguage::get('search_lower_equal'),
	'LIKE' => iaLanguage::get('search_like'),
	'NOT LIKE' => iaLanguage::get('search_not_like')
);
$conditions['text'] = array(
	'LIKE' => iaLanguage::get('search_like'),
	'NOT LIKE' => iaLanguage::get('search_not_like'),
	'=' => iaLanguage::get('search_equal'),
	'!=' => iaLanguage::get('search_not_equal'),
);
$conditions['textarea'] = &$conditions['text'];
$conditions['combo'] = array(
	'=' => iaLanguage::get('search_equal'),
	'!=' => iaLanguage::get('search_not_equal')
);
$conditions['radio'] = &$conditions['combo'];
$conditions['checkbox'] = &$conditions['combo'];
$conditions['pictures'] = array(
	'' => iaLanguage::get('search_not_set'),
	'NOTEMPTY' => iaLanguage::get('search_not_empty')
);
$conditions['image'] = $conditions['pictures'];
$conditions['storage'] = $conditions['pictures'];

$items = array();

// get items
if ($iaCore->get('members_enabled'))
{
	$items['members'] = array('extras' => iaCore::CORE, 'type' => iaCore::CORE);
}
$res = $iaDb->all(array('name', 'type', 'items'), "`status` = 'active' AND `items` != '' AND `name` != 'core'", null, null, 'extras');
foreach ($res as $r)
{
	foreach (unserialize($r['items']) as $i)
	{
		$items[$i['item']] = array(
			'extras' => $r['name'],
			'type' => $r['type']
		);
	}
}

$fields = searchableFields($iaDb, array_keys($items), array_keys($conditions));
$old_items = $items;

foreach (array_keys($fields) as $item)
{
	$items[$item] = $old_items[$item];
}

if (isset($_POST['q']) || isset($_GET['id']))
{
	$searchId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

	if (isset($_POST['q']))
	{
		$search = array(
			'ip' => $iaCore->factory('util')->getIp(),
			'query' => $_POST['q'],
			'member_id' => iaUsers::hasIdentity() ? iaUsers::getIdentity()->id : 0,
			'terms' => array('items' => false)
		);

		if (!empty($_POST['items']))
		{
			foreach ($_POST['items'] as $item)
			{
				if (array_key_exists($item, $items))
				{
					$search['terms']['items'][$item] = array();
					// additional fields
					if ($fields && !empty($_POST['f'][$item]))
					{
						foreach ($_POST['f'][$item] as $field => $val)
						{
							$about_field = $fields[$item][$field];
							if ($val || (isset($_POST['cond'][$item][$field]) && $_POST['cond'][$item][$field] == 'NOTEMPTY'))
							{
								$cond = isset($_POST['cond'][$item][$field]) ? $_POST['cond'][$item][$field] : '=';
								$cond = isset($conditions[$about_field['type']][$cond]) ? $cond : '=';

								$search['terms']['items'][$item][$field] = array(
									'val' => $val,
									'cond' => $cond
								);
							}
						}
					}
				}
			}
		}
		elseif (!$adv)
		{
			foreach ($items as $item => $row)
			{
				$search['terms']['items'][$item] = array();
				foreach ($fields[$item] as $field => $about_field)
				{
					$search['terms']['items'][$item][$field] = array('val' => $search['query'], 'cond' => 'LIKE');
				}
			}
		}
		$search['terms'] = serialize($search['terms']);
	}
	elseif ($searchId)
	{
		$search = $iaDb->row(iaDb::ALL_COLUMNS_SELECTION, iaDb::convertIds($searchId));
	}
}

$iaCore->startHook('phpSearchAfterGetQuery');

if ($search)
{
	$search['terms'] = unserialize($search['terms']);
	$page = isset($_GET['page']) ? max((int)$_GET['page'], 1) : 1;
	$template = ($adv ? 'adv' : '') . "search/?id={$searchId}&amp;page={page}";
	$start = ($page - 1) * $limit;


	// here are search results stored as HTML
	$results = array('html' => array(), 'num' => 0, 'all' => 0);
	// search in items

	/* Core search: members + pages */
	if (trim($search['query']) || $adv)
	{
		$searchFields = array();

		if ($iaCore->get('members_enabled'))
		{
			$iaUsers = $iaCore->factory('users');

			$searchFields = array(
				$iaUsers->getItemName() => array(
					'name' => $iaUsers->getItemName(),
					'where' => '',
					'fields' => iaUsers::getTable(),
					'items' => array()
				)
			);
		}

		if ($search['query'])
		{
			searchByPages($search['query'], $results);

			if ($iaCore->get('members_enabled'))
			{
				$searchFields['members']['items'] = array(
					'username' => array(
						'val' => '%' . $search['query'] . '%',
						'cond' => 'LIKE'
					),
					'fullname' => array(
						'val' => '%' . $search['query'] . '%',
						'cond' => 'LIKE'
					)
				);
			}
			if (!$adv && !empty($search['terms']['items']))
			{
				foreach ($search['terms']['items'] as $i => $flds)
				{
					if ($i == 'members' && $iaCore->get('members_enabled'))
					{
						if (!isset($searchFields[$i]))
						{
							$searchFields[$i] = array(
								'name' => $i,
								'where' => '',
								'items' => array(),
							);
						}
						$searchFields[$i]['items'] = $flds;
						$searchFields[$i]['where'] = " AND `status` = 'active'";
					}
				}
			}
		}
		foreach ($searchFields as $v)
		{
			$fieldsList = array();

			if (!isset($v['db']))
			{
				$v['db'] = $v['name'];
			}
			if (!isset($v['type']))
			{
				$v['type'] = $v['name'];
			}
			if (!isset($v['fields']))
			{
				$v['fields'] = 'advsearch';
			}

			if (isset($search['terms']['items'][$v['name']]))
			{
				foreach ($search['terms']['items'][$v['name']] as $key => $val)
				{
					$v['items'][$key] = $val;
				}
			}
			if (count($v['items']) > 0)
			{
				$rows = $iaDb->all(iaDb::ALL_COLUMNS_SELECTION, '(' . searchMatch($v['items']) . ') ' . $v['where'], 0, 10, $v['db']);

				if ($v['name'] != 'pages')
				{
					$fieldsList = iaField::getAcoFieldsList($v['fields'], $v['type'], null, true);
				}

				if ($rows)
				{
					$iaView->iaSmarty->assign('all_items', $rows);
					$iaView->iaSmarty->assign('all_item_fields', $fieldsList);
					$iaView->iaSmarty->assign('all_item_type', $v['type']);
					$iaView->iaSmarty->assign('member', iaUsers::hasIdentity() ? iaUsers::getIdentity(true) : array());

					$results['num'] += 1;
					$results['html'][$v['name']] = $iaView->iaSmarty->fetch('all-items-page.tpl');
				}
			}
		}
	}

	/* Package and plugin: read search.inc.php */
	if (!empty($search['terms']['items']))
	{
		foreach ($search['terms']['items'] as $i => $flds)
		{
			// in case there is no such item, skip to next iteration
			if (!array_key_exists($i, $items))
			{
				continue;
			}

			if (iaCore::CORE != $items[$i]['type'])
			{
				$search_file = ('package' == $items[$i]['type'] ? 'packages/' : 'plugins/') . $items[$i]['extras'] . '/includes/search.inc.php';
				// we echo HTML code in search.inc.php file
				if (is_file(IA_HOME . $search_file))
				{
					$search_func = $i . '_search';
					if (!function_exists($search_func))
					{
						include_once IA_HOME . $search_file;
					}
					if (function_exists($search_func))
					{
						if ($array = $search_func($search['query'], $flds, $start, $limit, $results['all'], $customWhere, ($adv ? 'AND' : 'OR')))
						{
							$results['num'] += count($array);
							$results['html'][$i] = implode('', $array);
						}
					}
				}
				$start = $start > 0 ? ($start + $results['num']) - $results['all'] : $start;
				//TODO: we need to know if this code is still actual
//				$limit = $results['num'] > $limit ? $limit - $results['num'] : 0;
			}
		}
	}

	if ($results['all'])
	{
		if (isset($_POST['q']))
		{
			$search['terms'] = serialize($search['terms']);
			$searchId = $iaDb->insert($search, array('time' => 'UNIX_TIMESTAMP()'));
			$url = IA_URL . ($adv ? 'adv' : '') . 'search/?id=' . $searchId;

			iaUtil::go_to($url);
		}
	}

	// searched terms for additional fields
	if ($fields && $search['terms']['items'])
	{
		foreach ($search['terms']['items'] as $i => $f)
		{
			foreach ($f as $fname => $fval)
			{
				$fields[$i][$fname]['val'] = iaSanitize::html($fval['val']);
				$fields[$i][$fname]['cond'] = $fval['cond'];
			}
		}
	}
}

$iaDb->resetTable();

$iaView->assign('items', $adv ? array_keys($items) : array());
$iaView->assign('adv', $adv);
$iaView->assign('fields', $fields);
$iaView->assign('results', $results['html']);

$iaView->assign('atemplate', $template);
$iaView->assign('atotal', $results['all']);
$iaView->assign('limit', $limit);

$iaView->assign('search', $search);
$iaView->assign('conditions', $conditions);
