<?php
abstract class dbeng_pdo extends dbeng_abs {
	
	/**
     * Om niet alle queries te hoeven herschrijven heb ik hier een kleine parser
     * ingebouwd die de queries herschrijft naar PDO formaat.
     *
     * De functie bindt ook alle parameters aan het statement met daarbij
     * behorende PDO::PARAM_*
     *
     * @param string $s
     * @param array $p 'query param name' => value
     * @param bool $bind bepaald of de params hier al gekoppeld moeten worden
     * @return PDOStatement
     */
	public function prepareSql($s, $p, $bind = true) {
		if (empty($p)) {
            return $this->_conn->prepare($s);
        } # if

		$stmt = $this->_conn->prepare($s);
        if (!$stmt instanceof PDOStatement) {
        	throw new Exception(print_r($stmt, true));
        }

        foreach ($p as $key => $val) {
            if (is_int($p[$key])) {
                $stmt->bindParam($key, intval($p[$key]), PDO::PARAM_INT);
            } else {
                $stmt->bindParam($key, $p[$key], PDO::PARAM_STR);
            }
        }

        return $stmt;
	}
	public function rawExec($s) {
		SpotTiming::start(__FUNCTION__);
		$stmt = $this->_conn->query($s);
		SpotTiming::stop(__FUNCTION__,array($s));
		
		return $stmt;
	}
	
	/**
     * Deze functie voert het statement uit en plaatst het aantal rijen in
     * een var.
     *
     * @param string $s
     * @param array $p
     * @return PDOStatement
     */
    public function exec($s, $p = array()) {
		SpotTiming::start(__FUNCTION__);
        $stmt = $this->prepareSql($s, $p);
        $stmt->execute();
        $this->_rows_changed = $stmt->rowCount();
		SpotTiming::stop(__FUNCTION__, array($s, $p));
 
    	return $stmt;
    }

	/*
	 * INSERT or UPDATE statement, geef niets terug
	 */
	function modify($s, $p = array()) {
		SpotTiming::start(__FUNCTION__);
		
		$res = $this->exec($s, $p);
        $res->closeCursor();
		unset($res);
		
		SpotTiming::stop(__FUNCTION__, array($s,$p));
	} # modify
	
	/* 
	 * Begins an transaction
	 */
	function beginTransaction() {
		$this->_conn->beginTransaction();
	} # beginTransaction
	
	/* 
	 * Commits an transaction
	 */
	function commit() {
		$this->_conn->commit();
	} # commit
	
	/* 
	 * Rolls back an transaction
	 */
	function rollback() {
		$this->_conn->rollback();
	} # rollback
	
    function rows() {
		return $this->_rows_changed;
	} # rows()

	function lastInsertId($tableName) {
		return $this->_conn->lastInsertId($tableName . "_id_seq");
	} # lastInsertId
	
	 /**
     * Fetch alleen het eerste resultaat
     * @param array $s
     * @param array $p
     * @return array
     */
	function singleQuery($s, $p = array()) {
		SpotTiming::start(__FUNCTION__);
		$stmt = $this->exec($s, $p);
        $row = $stmt->fetch();
        $stmt->closeCursor();
		unset($stmt);
		SpotTiming::stop(__FUNCTION__, array($s,$p));
        
		return $row[0];
	} # singleQuery
	
	/**
     * Fetch alle resultaten
     * @param string $s
     * @param array $p
     * @return array
     */
	function arrayQuery($s, $p = array()) {
		SpotTiming::start(__FUNCTION__);
		$stmt = $this->exec($s, $p);
		$tmpArray = $stmt->fetchAll();
		
        $stmt->closeCursor();
		unset($stmt);
		SpotTiming::stop(__FUNCTION__, array($s,$p));

		return $tmpArray;
	} # arrayQuery

} # class
