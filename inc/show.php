<?
/*
	Copyright ©2008-2012,2016,2021  Simon Arlott

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU Affero General Public License v3
	as published by the Free Software Foundation.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with this program. If not, see <http://www.gnu.org/licenses/>.
*/
function qdb_get_show($id) {
	global $db, $user;

	try {
		$stmt = $db->prepare("SELECT *, (SELECT users.name FROM users WHERE quotes.users_id=users.id) AS users_name FROM rated_quotes quotes WHERE id=:quoteid");
		$stmt->bindParam(":quoteid", $id);

		$stmt->execute();
		$quote = $stmt->fetch(PDO::FETCH_OBJ);
		$stmt->closeCursor();

		if ($quote === FALSE) {
			qdb_err("Quote #".qdb_htmlentities($id)." does not exist.");
		} else if ($quote->hide && ($user === FALSE || !$user->admin)) {
			qdb_err("Quote #".qdb_htmlentities($id)." is hidden.");
		} else {
			$stmt = $db->prepare("SELECT tags.*,"
				." (SELECT users.name FROM users WHERE quotes_tags.users_id=users.id) AS users_name FROM tags"
				." JOIN quotes_tags ON tags.id=quotes_tags.tags_id"
				." WHERE quotes_tags.quotes_id=:quoteid ORDER BY tags.name ASC");
			$stmt->bindParam(":quoteid", $id);

			$stmt->execute();
			$tags = $stmt->fetchAll(PDO::FETCH_OBJ);
			$stmt->closeCursor();

			qdb_show($quote, $tags, TRUE);
		}
	} catch (PDOException $e) {
		qdb_die($e);
	}

	qdb_messages();
}

function qdb_getall_show($where = "", $where_bind = array(), $order = "", $limit = 0) {
	global $db, $config;

	?><dl class="tags"><?

	function quicksort($seq) {
		if(!count($seq)) return $seq;
		$k = $seq[0];
		$x = $y = array();
		for($i=1; $i<count($seq); $i++) {
			if($seq[$i]->name <= $k->name) { $x[] = $seq[$i]; } else { $y[] = $seq[$i]; }
		}
		return array_merge(quicksort($x), array($k), quicksort($y));
	}

	try {
		$sql = "SELECT tags.id, tags.name, tags.ip, (SELECT users.name FROM users WHERE tags.users_id=users.id) AS users_name, COUNT(quotes_tags) AS count FROM tags"
			." JOIN quotes_tags ON tags.id=quotes_tags.tags_id"
			." JOIN rated_quotes quotes ON quotes_tags.quotes_id=quotes.id";
		if ($where != "") { $sql .= " WHERE ($where)"; }
		$tags_list = qdb_tags_list();
		if (count($tags_list) > 0) {
			if ($where == "") { $sql .= " WHERE"; } else { $sql .= " AND"; }
			$sql .= " quotes_tags.quotes_id IN (SELECT quotes_id FROM quotes_tags"
				." WHERE tags_id IN (".implode(",", $tags_list).") GROUP BY quotes_id"
				." HAVING COUNT(quotes_id) = ".count($tags_list).")";
		}
		$sql .= " GROUP BY tags.id, tags.name, tags.ip, users_name";
		if (count($tags_list) > 0) {
			$sql .= " HAVING tags.id NOT IN (".implode(",", $tags_list).")";
		}
		$sql ." ORDER BY count DESC LIMIT ".$config['tags_cloudsize'];
		$stmt = $db->prepare($sql);
		foreach ($where_bind as $name => $value) {
			$stmt->bindParam($name, $value);
		}

		$stmt->execute();
		$tags = $stmt->fetchAll(PDO::FETCH_OBJ);
		if ($tags !== FALSE) {
			$tags = quicksort($tags);

			$scale = array();
			foreach ($tags as $tag) {
				if (!isset($scale[$tag->count])) { $scale[$tag->count]=0; }
				$scale[$tag->count]++;
			}
			krsort($scale);

			$max = NULL;
			foreach ($scale as $key => $count) {
				if (!isset($max)) { $max = $key; }

				$scale[$key] = round(0.75 + ($key / $max), 2);
			}

			foreach ($tags as $tag) {
				?><dt><a href="?<?=qdb_qs()?>tags=<?=qdb_tags_qs_add($tag->id)?>" style="font-size: <?=$scale[$tag->count]?>em;"<?
					?> title="add '<?=qdb_htmlentities($tag->name)?>' to tag filter<?=qdb_tag_creator($tag)?>"><?=qdb_htmlentities($tag->name)?></a></dt><?
				?><dd><?=qdb_htmlentities($tag->count)?></dd><?
			}
		}

		$stmt->closeCursor();
	} catch (PDOException $e) {
		qdb_die($e);
	}
	?></dl><br><?

	qdb_tags_filter();

	try {
		$sql = "SELECT *, (SELECT users.name FROM users WHERE quotes.users_id=users.id) AS users_name FROM rated_quotes quotes";
		if ($where != "") { $sql .= " WHERE ($where)"; }
		$tags_list = qdb_tags_list();
		if (count($tags_list) > 0) {
			if ($where == "") { $sql .= " WHERE"; } else { $sql .= " AND"; }
			$sql .= " id IN (SELECT quotes_id FROM quotes_tags"
				." WHERE tags_id IN (".implode(",", $tags_list).") GROUP BY quotes_id"
				." HAVING COUNT(quotes_id) = ".count($tags_list).")";
		}
		if ($order != "") { $sql .= " ORDER BY $order"; }
		$sql .= " LIMIT ".($limit > 0 ? $limit : $config['perpage']);

		$stmt = $db->prepare($sql);
		foreach ($where_bind as $name => $value) {
			$stmt->bindParam($name, $value);
		}
		$stmt2 = $db->prepare("SELECT tags.id, tags.name, quotes_tags.ip,"
			." (SELECT users.name FROM users WHERE quotes_tags.users_id=users.id) AS users_name FROM tags"
			." JOIN quotes_tags ON tags.id=quotes_tags.tags_id"
			." WHERE quotes_tags.quotes_id=:quoteid ORDER BY tags.name ASC");

		$stmt->execute();
		while ($quote = $stmt->fetch(PDO::FETCH_OBJ)) {
			$stmt2->bindParam(":quoteid", $quote->id);
			$stmt2->execute();
			$tags = $stmt2->fetchAll(PDO::FETCH_OBJ);
			$stmt2->closeCursor();

			qdb_show($quote, $tags);
		}
		$stmt->closeCursor();
	} catch (PDOException $e) {
		qdb_die($e);
	}
}

function qdb_get_tag($name) {
	global $db, $tagcache;

	if ($name == NULL) { return ""; }

	if (isset($tagcache[$name])) {
		return $tagcache[$name]->id;
	}

	try {
		$stmt = $db->prepare("SELECT * FROM tags WHERE name=:name");
		$stmt->bindParam(":name", $name);

		$stmt->execute();
		$tag = $stmt->fetch(PDO::FETCH_OBJ);
		$stmt->closeCursor();

		if ($tag !== FALSE) {
			$tagcache[$name] = $tag;
			return $tag->id;
		}
	} catch (PDOException $e) {
		qdb_die($e);
	}

	return NULL;
}

function qdb_del_tag($name) {
	global $tagcache;

	if ($name == NULL) { return ""; }

	if (isset($tagcache[$name])) {
		unset($tagcache[$name]);
	}
}

function qdb_show($quote, $tags, $single = FALSE) {
	global $user, $config;

	if (defined("QDB_ASYNC")) {
		echo '<quote><![CDATA[';
	} else {
	?><div class="quote"><?
	}
		?><p class="quote"><?
			?><div class="header"><?
				?><a href="./?<?=$quote->id?>" title="quote <?=$quote->id?>"><strong class="id">#<?=$quote->id?></strong></a>: <?
				?><a class="op rateup" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "rate" => "1"))?>" title="rate #<?=$quote->id?> up">+</a><?
				?> <em class="rating"><?=$quote->rating?></em> <?
				?><a class="op ratedown" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "rate" => "-1"))?>" title="rate #<?=$quote->id?> down">-</a><?

	if ($user !== FALSE && $user->admin) {
				?> <a class="op edit" onclick="return modequote_edit(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "edit" => "1"))?>" title="edit #<?=$quote->id?>">&#x00B6;</a><?

		if ($quote->flag) {
				?> <a class="op unflag" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "flag" => "0"))?>" title="unflag #<?=$quote->id?>">&#x2691;</a><?
		} else {
				?> <a class="op flag" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "flag" => "1"))?>" title="flag #<?=$quote->id?>">&#x2690;</a><?
		}

		if ($quote->hide) {
				?> <a class="op show" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "hide" => "0"))?>" title="show #<?=$quote->id?>">&#x2713;</a><?
		} else {
				?> <a class="op hide" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "hide" => "1"))?>" title="hide #<?=$quote->id?>">&#x2026;</a><?
		}

				?> <a class="op del" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "del" => "1"))?>" title="delete #<?=$quote->id?>">&#x2717;</a><?
	} else {
				?> <a class="op flag" onclick="return modquote_op(this)" href="modquote?<?=qdb_secure(array("id" => $quote->id, "flag" => "1"))?>" title="flag #<?=$quote->id?>">&#x2690;</a><?
	}

	if ($user !== FALSE && $user->admin) {
				?> <span class="userandip"><?

		if ($quote->users_name != NULL) {
					?><span class="user"><?=qdb_htmlentities($quote->users_name)?></span>/<?
		}

					?><span class="ip"><?=$quote->ip?></span><?
				?></span><?
	}
			?></div><?
			?><div class="text<?=$single ? "singu" : "multi"?>"><tt><?

	echo
		str_replace("\n", (defined("QDB_ASYNC") ? "<br/>" : "<br>"),
			preg_replace('/(^|\n) \*/', '$1&nbsp;*',
				qdb_htmlentities($quote->quote)
			)
		);

			?></tt></div><?
	if ($tags !== FALSE) {
			?><ul class="tags"><?
		foreach ($tags as $tag) {
			if ($single) {
					?><li><a href="browse?tags=<?=$tag->id?>"<?
					?> title="view quotes with tag '<?=qdb_htmlentities($tag->name)?>'<?=qdb_tag_creator($tag)?>"><?=qdb_htmlentities($tag->name)?></a></li><?
			} else {
					?><li><a href="?<?=qdb_qs()?>tags=<?=qdb_tags_qs_add($tag->id)?>"<?
					?> title="add '<?=qdb_htmlentities($tag->name)?>' to tag filter<?=qdb_tag_creator($tag)?>"><?=qdb_htmlentities($tag->name)?></a></li><?
			}
		}
			?></ul><?
	}
	if (!$config['tags_useronly'] || $user !== FALSE) {
			?><form class="quote" method="post" action="modquote" onsubmit="return modquote_tags(this)"><?
				?><input type="hidden" name="id" value="<?=$quote->id?>"><?
				?><input type="hidden" name="var_asyncdisabled" value="0"><?
				if (defined("QDB_ASYNC")) { echo '</input>'; }
				?><input type="text" name="tagset"<?
				?> title="tags to Add to<?=($user !== FALSE && $user->admin) ? "/Remove from" : ""?> #<?=$quote->id?>"><?
				if (defined("QDB_ASYNC")) { echo '</input>'; }
				?><input type="submit" name="action_"<?
				?> value="Add<?=($user !== FALSE && $user->admin) ? "/Remove" : ""?> Tags"<?
				?> title="modify #<?=$quote->id?>'s tags"><?
				if (defined("QDB_ASYNC")) { echo '</input>'; }
			?></form><?
	}

	if (defined("QDB_ASYNC")) {
		echo ']]></quote>';
	} else {
	?></div><?
	}
}

function qdb_tags_list() {
	$tags = array();
	if (isset($_GET["tags"])) {
		foreach (qdb_tag_explode($_GET["tags"]) as $tag) {
			if (qdb_digit($tag)) { $tags[] = $tag; }
		}
	}
	return $tags;
}

function qdb_tags_qs_add($tagid) {
	$tags = array();
	if (isset($_GET["tags"])) {
		foreach (qdb_tag_explode($_GET["tags"]) as $tag) {
			if (qdb_digit($tag)) { $tags[] = $tag; }
		}
	}
	if (!in_array($tagid, $tags)) { $tags[] = $tagid; }
	sort($tags);
	return implode("+", $tags);
}

function qdb_tags_qs_del($tagid) {
	$tags = array();
	if (isset($_GET["tags"])) {
		foreach (qdb_tag_explode($_GET["tags"]) as $tag) {
			if (qdb_digit($tag)&& $tag != $tagid) { $tags[] = $tag; }
		}
	}
	sort($tags);
	return implode("+", $tags);
}

function qdb_tag_creator($tag) {
	global $user;

	if ($user === FALSE || !$user->admin) { return ""; }
	return " [".($tag->users_name != NULL ? qdb_htmlentities($tag->users_name)."/" : "").$tag->ip."]";
}

function qdb_tags_filter() {
	global $db;

	$tags_list = qdb_tags_list();
	if (count($tags_list) > 0) {
		?><ul class="tags"><?
		try {
			$stmt = $db->prepare("SELECT * FROM tags WHERE id IN (".implode(",", $tags_list).") ORDER BY name");
			$stmt->execute();
			while ($tag = $stmt->fetch(PDO::FETCH_OBJ)) {
				?><li><a href="?<?=qdb_qs()?>tags=<?=qdb_tags_qs_del($tag->id)?>"<?
					?> title="remove '<?=qdb_htmlentities($tag->name)?>' from tag filter<?=qdb_tag_creator($tag)?>">!<?=qdb_htmlentities($tag->name)?></a></li><?
			}
			$stmt->closeCursor();
		} catch (PDOException $e) {
			qdb_die($e);
		}
		?></ul><hr><?
	}
}
?>
