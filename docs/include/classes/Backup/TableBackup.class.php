<?php
/************************************************************************/
/* ATutor																*/
/************************************************************************/
/* Copyright (c) 2002-2004 by Greg Gay, Joel Kronenberg & Heidi Hazelton*/
/* Adaptive Technology Resource Centre / University of Toronto			*/
/* http://atutor.ca														*/
/*																		*/
/* This program is free software. You can redistribute it and/or		*/
/* modify it under the terms of the GNU General Public License			*/
/* as published by the Free Software Foundation.						*/
/************************************************************************/
// $Id$

/**
* TableFactory
* Class for creating AbstractTable Objects
* @access	public
* @author	Joel Kronenberg
* @package	Backup
*/
class TableFactory {
	/**
	* The database handler.
	*
	* @access  private
	* @var resource
	*/
	var $db;

	/**
	* The ATutor version this backup was created with.
	*
	* @access private
	* @var string
	*/
	var $version;

	/**
	* The course ID we're restoring into.
	*
	* @access private
	* @var int
	*/
	var $course_id;

	/**
	* The directory unzip backup is found.
	*
	* @access private
	* @var string
	*/
	var $import_dir;

	/**
	* Constructor.
	* 
	* @param string $version The backup version.
	* @param resource $db The database handler.
	* @param int $course_id The ID of this course.
	* @param string $import_dir The directory where the backup was unzipped to.
	* 
	*/
	function TableFactory ($version, $db, $course_id, $import_dir) {
		$this->version = $version;
		$this->db = $db;
		$this->course_id = $course_id;
		$this->import_dir = $import_dir;
	}

	/**
	* Create and return the specified AbstractTable Object.
	* 
	* @access public
	*
	* @param string $table_name The name of the table to create an Object for.
	*
	* @return AbstractTable Object|NULL if $table_name does not match available Objects.
	*
	* @See AbstractTable
	*
	*/
	function createTable($table_name) {
		static $resource_categories_id_map; // old -> new ID's
		static $content_id_map; // old -> new ID's

		switch ($table_name) {
			case 'news':
				return new NewsTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'forums':
				return new ForumsTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'glossary':
				return new GlossaryTable($this->version, $this->db, $this->course_id, $this->import_dir, $garbage);
				break;

			case 'resource_links':
				return new ResourceLinksTable($this->version, $this->db, $this->course_id, $this->import_dir, $resource_categories_id_map);
				break;

			case 'resource_categories':
				return new ResourceCategoriesTable($this->version, $this->db, $this->course_id, $this->import_dir, $resource_categories_id_map);
				break;

			case 'content':
				return new ContentTable($this->version, $this->db, $this->course_id, $this->import_dir, $content_id_map);
				break;

			case 'related_content':
				debug($content_id_map);
				break;
			default:
				return NULL;
		}
	}
}

/**
* AbstractTable
* Class for restoring backup tables
* @access	public
* @author	Joel Kronenberg
* @package	Backup
*/
class AbstractTable {
	/**
	* The ATutor version this backup was created with.
	*
	* @access protected
	* @var string
	*/
	var $version;

	/**
	* The database handler.
	*
	* @access  private
	* @var resource
	*/
	var $db;

	/**
	* The CSV table file handler.
	*
	* @access  private
	* @var resource
	*/
	var $fp;

	/**
	* The course ID we're restoring into.
	*
	* @access private
	* @var int
	*/
	var $course_id;

	/**
	* The directory unzip backup is found.
	*
	* @access private
7	* @var string
	*/
	var $import_dir;

	/**
	* A hash table associated old ID's (key) with their new ID's (value).
	* Used for the content table where there is a parent ID and a child ID.
	*
	* @access private
	* @var array
	*/
	var $old_id_to_new_id;

	/**
	* A hash table associated old ID's (key) with their new ID's (value).
	* A copy of $old_id_to_new_id but the ID's are keys to a _different_
	* table. Example: The CatID from the resource_categories to CatID
	* in the resource_links table.
	*
	* @access private
	* @var array
	*/
	var $new_parent_ids;


	/**
	* Constructor.
	* 
	* @param string $version The backup version.
	* @param resource $db The database handler.
	* @param int $course_id The ID of this course.
	* @param string $import_dir The directory where the backup was unzipped to.
	* @param array $old_id_to_new_id Reference to either the parent ID's or to store current ID's.
	* 
	*/
	function AbstractTable($version, $db, $course_id, $import_dir, &$old_id_to_new_id) {
		$this->db =& $db;
		$this->course_id = $course_id;
		$this->version = $version;
		$this->import_dir = $import_dir;

		//$this->importDir = 
		if (empty($old_id_to_new_id)) {
			$this->old_id_to_new_id =& $old_id_to_new_id;
		} else {
			$this->new_parent_ids = $old_id_to_new_id;
		}
	}

	// -- public methods below:

	/**
	* Restores the table defined in the CSV file, one row at a time.
	* 
	* @access public
	* @return void
	*
	* @See getRows()
	* @See insertRow()
	*/
	function restore() {
		$this->getRows();

		$this->lockTable();
		if ($this->rows) {
			foreach ($this->rows as $row) {
				$this->insertRow($row);	
			}
		}
		$this->unlockTable();
	}

	// -- protected methods below:

	/**
	* Converts escaped white space characters to their correct representation.
	* 
	* @access protected
	*
	* @param string $input The string to convert.
	*
	* @return string The converted string.
	*
	* @See Backup::quoteCSV()
	*/
	function translateWhitespace($input) {
		$input = str_replace('\n', "\n", $input);
		$input = str_replace('\r', "\r", $input);
		$input = str_replace('\x00', "\0", $input);

		$input = addslashes($input);
		return $input;
	}

	/**
	* Gets the entry/row's new ID based on it's old entry ID.
	* 
	* @param mixed $id The old entry ID, or FALSE if n/a.
	* @access protected
	* @return boolean|int The new entry ID or FALSE if has not been inserted 
	* into the db table yet or FALSE if $id is FALSE;
	*
	* @See setNewID()
	*/
	function getNewID($id) {
		if ($id && isset($this->old_id_to_new_id[$id])) {
			return $this->old_id_to_new_id[$id];
		}
		return FALSE;
	}

	// protected
	// find the index offset
	function findOffset($id) {
		return $this->rows[$id]['index_offset'];
	}

	// -- private methods below:

	/**
	* Converts $row to be ready for inserting into the db.
	* 
	* @param array $row The row to convert.
	* @access private
	* @return array The converted row.
	*
	* @see translateWhitespace()
	*/
	function translateText($row) {
		global $backup_tables;
		$count = 0;
		foreach ($backup_tables[$this->tableName]['fields'] as $field) {
			if ($field[1] == TEXT) {
				$row[$count] = $this->translateWhitespace($row[$count]);
			}
			$count++;
		}
		return $row;
	}

	/**
	* Locks the database table for writing.
	* 
	* @access private
	* @return void
	*
	* @See unlockTable()
	*/
	function lockTable() {
		$lock_sql = 'LOCK TABLES ' . TABLE_PREFIX . $this->tableName. ' WRITE';
		$result   = mysql_query($lock_sql, $this->db);
	}

	/**
	* UnLocks the database table.
	* 
	* @access private
	* @return void
	*
	* @See lockTable()
	*/
	function unlockTable() {
		$lock_sql = 'UNLOCK TABLES';
		$result   = mysql_query($lock_sql, $this->db);
	}

	/**
	* Opens the CSV table file for reading.
	* 
	* @access private
	* @return void
	*
	* @See closeTable()
	*/
	function openTable() {
		$this->fp = fopen($this->import_dir . $this->tableName . '.csv', 'rb');
	}

	/**
	* Closes the CSV table file.
	* 
	* @access private
	* @return void
	*
	* @See openTable()
	*/
	function closeTable() {
		fclose($this->fp);
	}

	/**
	* Reads the CSV table file into array $this->rows.
	* 
	* @access private
	* @return void
	*
	* @See openTable()
	* @See closeTable()
	* @See getOldID()
	*/
	function getRows() {
		$this->openTable();
		$i = 0;

		while ($row = fgetcsv($this->fp, 70000)) {
			if (count($row) < 2) {
				continue;
			}
			$row = $this->translateText($row);
			$row['index_offset'] = $i;
			if ($this->getOldID($row) === FALSE) {
				$this->rows[] = $row;
			} else {
				$this->rows[$this->getOldID($row)] = $row;
			}

			$i++;
		}
		$this->closeTable();
	}


	/**
	* Inserts a single row into the database table.
	* 
	* @param array $row The row to insert into the table.
	* @access private
	* @return void
	*
	* @See convert()
	* @See getOldID()
	* @See getNewID()
	* @See getParentID()
	* @See generateSQL()
	*/
	function insertRow($row) {
		$row = $this->convert($row);
		$old_id = $this->getOldID($row);
		$new_id = $this->getNewID($old_id);
		if (!$new_id) {
			$parent_id = $this->getParentID($row);

			if ($parent_id && !$this->getNewID($parent_id)) {
				$this->insertRow($this->rows[$parent_id]);
			}
			$sql = $this->generateSQL($row); 
			debug($sql);
			mysql_query($sql, $this->db);
			debug(mysql_error($this->db));

			$new_id = mysql_insert_id($this->db);

			$this->setNewID($old_id, $new_id);
		} // else: already inserted
	}

	/**
	* Sets the association between the CSV row ID and the new ID
	* as inserted into the database table.
	* 
	* @param int $old_id The old entry ID.
	* @param int $new_id The new entry ID after inserted into the db.
	* @access private
	* @return void
	*
	* @See getNewID()
	*/
	function setNewID($old_id, $new_id) {
		$this->old_id_to_new_id[$old_id] = $new_id;
	}


	// -- abstract methods below:
	/**
	* Gets the entry/row ID as it appears in the CSV file, or FALSE if n/a.
	* 
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return boolean|int The old ID or FALSE if not applicable.
	*
	*/
	function getOldID($row)    { /* abstract */ }

	/**
	* Gets the entry/row parent ID as it appears in the CSV file, or FALSE if n/a.
	* 
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return boolean|int The parent ID or FALSE if not applicable.
	*
	*/
	function getParentID($row) { /* abstract */ }

	/**
	* Convert the entry/row to the current ATutor version.
	* 
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return array The converted row.
	*
	*/
	function convert($row)     { /* abstract */ }

	/**
	* Generate the SQL for this table.
	* 
	* Precondition: $row has passed through convert() and 
	* translateText().
	*
	* @param array $row The old entry row from the CSV file.
	* @access private
	* @return string The SQL query.
	*
	* @see insertRow()
	*/
	function generateSQL($row) { /* abstract */ }

}
//---------------------------------------------------------------------

/**
* ForumsTable
* Extends AbstractTable and provides table specific methods and members.
* @access	public
* @author	Joel Kronenberg
* @author	Heidi Hazelton
* @package	Backup
*/
class ForumsTable extends AbstractTable {
	/**
	* The ATutor database table name (w/o prefix).
	* Also the CSV file name (w/o extension).
	*
	* @access private
	* @var const string
	*/
	var $tableName = 'forums';

	// -- private methods below:

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	function convert($row) {
		// There are no table changes made to the `forums` table.
		// Return the row unchanged.

		return $row;
	}

	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'forums VALUES ';
		$sql .= '(0,' . $this->course_id . ',';

		$sql .= "'".$row[0]."',"; // title
		$sql .= "'".$row[1]."',"; // description
		$sql .= "'".$row[2]."',"; // num_topics
		$sql .= "'".$row[3]."',"; // num_posts
		$sql .= $row[4]. '),';	  // last_post

		return $sql;
	}
}
//---------------------------------------------------------------------
/**
* GlossaryTable
* Extends AbstractTable and provides table specific methods and members.
* @access	public
* @author	Joel Kronenberg
* @author	Heidi Hazelton
* @package	Backup
*/
class GlossaryTable extends AbstractTable {
	var $tableName = 'glossary';

	var $nextIndex;
	var $startIndex;

	function lockTable() {
		parent::lockTable();

		$sql	  = 'SELECT MAX(word_id) AS word_id FROM '.TABLE_PREFIX.'glossary';
		$result   = mysql_query($sql, $this->db);
		$next_index = mysql_fetch_assoc($result);
		$this->next_index = $this->start_index = $next_index['word_id'] + 1;
	}

	function getOldID($row) {
		return $row[0];
		//return FALSE;
	}

	function getParentID($row) {
		//return $row[3];
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}


	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'glossary VALUES ';
		$sql .= '('.$this->next_index.','; // word_id  
		$sql .= $this->course_id . ',';	   // course_id 
		$sql .= "'".$row[1]."',";		   // word
		$sql .= "'".$row[2]."',";		   // definition
		if ($row[3] == 0) {
			$sql .= 0;
		} else {
			$offset = $this->findOffset($row[3]);
			$sql .= $offset + $this->start_index; // related word
		}
		$sql .=  ')';

		$this->next_index++;
		return $sql;
	}
}
//---------------------------------------------------------------------
/**
* ResourceCategoriesTable
* Extends AbstractTable and provides table specific methods and members.
* @access	public
* @author	Joel Kronenberg
* @author	Heidi Hazelton
* @package	Backup
*/
class ResourceCategoriesTable extends AbstractTable {
	var $tableName = 'resource_categories';

	function getParentID($row) {
		return $row[2];
	}

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'resource_categories VALUES ';
		$sql .= '(0,';
		$sql .= $this->course_id .',';

		// CatName
		$sql .= "'".$row[1]."',";

		// CatParent
		if ($row[2] == 0) {
			$sql .= 'NULL';
		} else {
			$sql .= $this->getNewID($row[2]); // need the real way of getting the cat parent ID
		}
		$sql .= ')';

		return $sql;
	}
}

//---------------------------------------------------------------------
class ResourceLinksTable extends AbstractTable {
	var $tableName = 'resource_links';

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		// handle the white space issue as well
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'resource_links VALUES ';
		$sql .= '(0, ';
		$sql .= $this->new_parent_ids[$row[0]] . ',';

		$sql .= "'".$row[1]."',"; // URL
		$sql .= "'".$row[2]."',"; // LinkName
		$sql .= "'".$row[3]."',"; // Description
		$sql .= $row[4].',';      // Approved
		$sql .= "'".$row[5]."',"; // SubmitName
		$sql .= "'".$row[6]."',"; // SubmitEmail
		$sql .= "'".$row[7]."',"; // SubmitDate
		$sql .= $row[8]. '),';

		return $sql;
	}
}
//---------------------------------------------------------------------
class NewsTable extends AbstractTable {
	var $tableName = 'news';

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'news VALUES ';
		$sql .= '(0,'.$this->course_id.', '. $_SESSION['member_id'].', ';
		$sql .= "'".$row[0]."',"; // date
		$sql .= "'".$row[1]."',"; // formatting
		$sql .= "'".$row[2]."',"; // title
		$sql .= "'".$row[3]."')"; // body

		return $sql;
	}
}
//---------------------------------------------------------------------
class TestsTable extends AbstractTable {
	var $tableName = 'tests';

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		// handle the white space issue as well
		if (version_compare($version, '1.4', '<')) {
			$row[8] = 0;
			$row[9] = 0;
			$row[10] = 0;
			$row[11] = 0;
		} 
		
		if (version_compare($version, '1.4.2', '<')) {
			$row[12] = 0;
			$row[13] = 0;
		}
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row

		$sql		= 'SELECT MAX(test_id) AS max_test_id FROM '.TABLE_PREFIX.'tests';
		$result		= mysql_query($sql, $db);
		$next_index = mysql_fetch_assoc($result);
		$next_index = $next_index['max_test_id'] + 1;

		$sql = '';
		$index_offset = '';
		if ($sql == '') {
			$index_offset = $next_index - $row[0];
			$sql = 'INSERT INTO '.TABLE_PREFIX.'tests VALUES ';
		}
		$sql .= '(';
		$sql .= ($row[0] + $index_offset) . ',';
		$sql .= $this->course_id.',';

		$sql .= "'".$row[1]."',";	//title
		$sql .= "'".$row[2]."',";	//format
		$sql .= "'".$row[3]."',";	//start_date
		$sql .= "'".$row[4]."',";	//end_date
		$sql .= "'".$row[5]."',";	//randomize_order
		$sql .= "'".$row[6]."',";	//num_questions
		$sql .= "'".$row[7]."',";	//instructions
		$sql .= ',' . ($translated_content_ids[$row[8]] ? $translated_content_ids[$row[8]] : 0). ','; //content_id
		$sql .= $row[9] . ',';		//automark
		$sql .= $row[10] . ',';		//random
		$sql .= $row[11] . ',';		//difficulty
		$sql .= $row[12] . ',';		//num_takes
		$sql .= $row[13] ;			//anonymous
		$sql .= ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class TestsQuestionsTable extends AbstractTable {
	var $tableName = 'tests_questions';

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		if (version_compare($version, '1.4', '<')) {
			$row[28] = 0;
		}	
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row

		$sql		= 'SELECT MAX(test_id) AS max_test_id FROM '.TABLE_PREFIX.'tests';
		$result		= mysql_query($sql, $db);
		$next_index = mysql_fetch_assoc($result);
		$next_index = $next_index['max_test_id'] + 1;

		$sql = '';
		$index_offset = $next_index - $row[0];

		$sql = 'INSERT INTO '.TABLE_PREFIX.'tests_questions VALUES ';
		$sql .= '(';
		$sql .= ($row[0] + $index_offset) . ',';
		$sql .= $this->course_id.',';

		for ($i=1; $i<=28; $i++) {
			$sql .= "'".$row[$i]."',";
		}

		$sql  = substr($sql, 0, -1);
		$sql .= ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class PollsTable extends AbstractTable {
	var $tableName = 'polls';

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'polls VALUES ';
		$sql .= '(0,';
		$sql .= $this->course_id.',';

		for ($i=0; $i<=8; $i++) {
			$sql .= "'".$row[$i]."',";
		}

		$sql  = substr($sql, 0, -1);
		$sql .= ')';

		return $sql;
	}
}
//---------------------------------------------------------------------
class ContentTable extends AbstractTable {
	var $tableName = 'content';

	function getParentID($row) {
		return $row[1];
	}

	function getOldID($row) {
		return $row[0];
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		$sql = 'INSERT INTO '.TABLE_PREFIX.'content VALUES ';
		$sql .= '(0,';	// content_id
		$sql .= $this->course_id .',';
		if ($row[1] == 0) { // content_parent_id
			$sql .= 0;
		} else {
			$sql .= $this->getNewID($row[1]);
		}
		$sql .= ',';

		//if ($this->content_pages[$content_id][1] == 0) { // ordering
		//	$sql .= $this->content_pages[$content_id][2] + $this->order_offset;
		//} else {
	//		$sql .= $this->content_pages[$content_id][2];
	//	}
		$sql .= '1,';

		$sql .= "'".$row[3]."',"; // last_modified
		$sql .= $row[4] . ','; // revision
		$sql .= $row[5] . ','; // formatting
		$sql .= "'".$row[6]."',"; // release_date
		$sql .= "'".$row[7]."',"; // keywords
		$sql .= "'".$row[8]."',"; // content_path
		$sql .= "'".$row[9]."',"; // title
		$sql .= "'".$row[10]."',0)"; // text

		return $sql;
	}
}

//---------------------------------------------------------------------
class CourseStatsTable extends AbstractTable {
	var $tableName = 'course_stats';

	function getOldID($row) {
		return FALSE;
	}

	function getParentID($row) {
		return FALSE;
	}

	// private
	function convert($row) {
		return $row;
	}

	// private
	function generateSQL($row) {
		// insert row
		$sql = 'INSERT INTO '.TABLE_PREFIX.'course_stats VALUES ';
		$sql .= '('.$this->course_id."',";
		$sql .= "'".$row[0]."',"; //login_date
		$sql .= "'".$row[1]."',"; //guests
		$sql .= "'".$row[2]."',"; //members
		$sql .= ')';

		return $sql;
	}
}

?>