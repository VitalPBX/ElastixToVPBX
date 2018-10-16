<?php
namespace library;

use PDO;
use PDOException;
use PDOStatement;

class simple_pdo{
	/**
	 * @var PDO
	 */
	private $pdo;

	private $affected_rows;
	private $insert_id;
	private $rows;
	private $connected;

	/**
	 * simple_pdo constructor.
	 * @param $dsn
	 * @param null $username
	 * @param null $password
	 */
	private function __construct($dsn, $username = null, $password = null) {
		$this->pdo_connect($dsn,$username,$password);
	}

	private function pdo_connect($dsn, $username, $password){
		$this->connected = true;

		try {
			$this->pdo = new PDO($dsn, $username, $password, [
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_PERSISTENT => true
			]);

		} catch (PDOException $e) {
			$this->connected = false;
		}
	}

	/**
	 * @param $database
	 * @param $username
	 * @param null $password
	 * @param string $host
	 * @param int $port
	 *
	 * @return simple_pdo
	 */
	public static function MySQLConnect($database, $username, $password = null, $host = 'localhost', $port = 3306){
		$dsn = sprintf(
			'mysql:host=%s;port=%s;dbname=%s;charset=utf8',
			$host,
			$port,
			$database
		);

		return new self(
			$dsn,
			$username,
			$password
		);
	}

	public static function SQLiteConnect($database){
		$dsn = "sqlite:{$database}";
		return new self($dsn);
	}

	public function query($query) {
		$parameters = array_slice(func_get_args(), 1);
		if (count($parameters) > 0 && is_array($parameters[0])) {
			$parameters = $parameters[0];
		}

		$this->_execute(
				$query,
				$parameters,
				function (PDOStatement $stmt) {
					return $stmt->fetchAll(PDO::FETCH_CLASS);
				}
		);

		return $this;
	}

	public function is_connected(){
		return $this->connected;
	}

	public function start_transaction(){
		$this->pdo->beginTransaction();
	}

	public function end_transaction(){
		$this->pdo->commit();
	}

	public function get_affected_rows(){
		return $this->affected_rows;
	}

	public function get_rows(){
		return $this->rows;
	}

	public function get_inserted_id(){
		return $this->insert_id;
	}

	private function _execute($sql, $parameters, callable $callback){
		$sql = trim((string)$sql);
		$dbh = $this->pdo;

		try {
			$parameters = $this->process_input($sql, $parameters);
			$stmt = $dbh->prepare($sql);
			$stmt->execute($parameters);
		} catch (PDOException $e) {
			throw new \Exception($e->getMessage());
		}

		if (preg_match('/^select|insert|update|delete|replace|show/i', $sql)) {
			$this->affected_rows = $stmt->rowCount();
		}

		if (preg_match('/^insert/i', $sql)) {
			$this->insert_id = $dbh->lastInsertId();
		}

		if (preg_match('/^select|show/i', $sql)) {
			$this->rows = $callback($stmt);
		}

		$stmt->closeCursor();
	}

	public function process_input(&$sql, $input) {
		$input = array_values($input);
		$output = [];
		// Expand all array sequences, insert placeholders
		preg_match_all('/(\?|---)/', $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		$fill = 0;
		foreach ($matches as $index => $match) {
			list($token, $offset) = $match[1];
			if ($token == '?') {
				if (is_array($input[$index])) {
					array_splice($input, $index, 1, $input[$index]);
				}
				$output[] = $input[$index];
			} else if ($token == '---') {
				if ($input[$index]) {
					$placeholders = implode(',',array_fill(0, count($input[$index]), '?'));
					$sql = substr($sql, 0, $offset + $fill) . $placeholders . substr($sql, $offset + $fill + 3);
					$fill += strlen($placeholders) - 3;
					foreach ($input[$index] as $value) {
						$output[] = $value;
					}
				} else {
					$head = substr($sql, 0, $offset + $fill);
					preg_match('/(not\s+)?in\s+\(\s*$/i', $head, $match, PREG_OFFSET_CAPTURE);
					$replacement = (count($match) == 2) ? 'is not null' : 'is null';
					$tail = substr($sql, strpos($sql, ')', $offset + $fill + 3) + 1);
					$sql = substr($sql, 0, $match[0][1]) . $replacement . $tail;
				}
			}
		}
		return $output;
	}
}