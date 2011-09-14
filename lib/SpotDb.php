<?php
define('SPOTDB_SCHEMA_VERSION', '0.36');

class SpotDb {
	private $_dbsettings = null;
	private $_conn = null;

	/*
	 * Constants used for updating the SpotStateList
	 */
	const spotstate_Down	= 0;
	const spotstate_Watch	= 1;
	const spotstate_Seen	= 2;

	function __construct($db) {
		$this->_dbsettings = $db;
	} # __ctor

	/*
	 * Open connectie naar de database (basically factory), de 'engine' wordt uit de 
	 * settings gehaald die mee worden gegeven in de ctor.
	 */
	function connect() {
		SpotTiming::start(__FUNCTION__);

		switch ($this->_dbsettings['engine']) {
			case 'mysql'	: $this->_conn = new dbeng_mysql($this->_dbsettings['host'],
												$this->_dbsettings['user'],
												$this->_dbsettings['pass'],
												$this->_dbsettings['dbname']); 
							  break;

			case 'pdo_mysql': $this->_conn = new dbeng_pdo_mysql($this->_dbsettings['host'],
												$this->_dbsettings['user'],
												$this->_dbsettings['pass'],
												$this->_dbsettings['dbname']);
							  break;
							  
			case 'pdo_pgsql' : $this->_conn = new dbeng_pdo_pgsql($this->_dbsettings['host'],
												$this->_dbsettings['user'],
												$this->_dbsettings['pass'],
												$this->_dbsettings['dbname']);
							  break;
							
			case 'pdo_sqlite': $this->_conn = new dbeng_pdo_sqlite($this->_dbsettings['path']);
							   break;

			default			: throw new Exception('Unknown DB engine specified, please choose pdo_sqlite, mysql or pdo_mysql');
		} # switch

		$this->_conn->connect();
		SpotTiming::stop(__FUNCTION__);
	} # connect

	/*
	 * Geeft het database connectie object terug
	 */
	function getDbHandle() {
		return $this->_conn;
	} # getDbHandle

	/* 
	 * Haalt alle settings op uit de database
	 */
	function getAllSettings() {
		$dbSettings = $this->_conn->arrayQuery('SELECT name,value,serialized FROM settings');
		$tmpSettings = array();
		foreach($dbSettings as $item) {
			if ($item['serialized']) {
				$item['value'] = unserialize($item['value']);
			} # if
			
			$tmpSettings[$item['name']] = $item['value'];
		} # foreach
		
		return $tmpSettings;
	} # getAllSettings

	/* 
	 * Controleer of een messageid niet al eerder gebruikt is door ons om hier
	 * te posten
	 */
	function isCommentMessageIdUnique($messageid) {
        $params = array('messageid' => $messageid);
		$tmpResult = $this->_conn->singleQuery('SELECT messageid FROM commentsposted WHERE messageid = :messageid', $params);

		return (empty($tmpResult));
	} # isCommentMessageIdUnique

	/*
	 * Sla het gepostte comment op van deze user
	 */
	function addPostedComment($userId, $comment) {
        $params = array(
            'userid'    => (int)$userId,
            'messageid' => $comment['newmessageid'],
            'inreplyto' => $comment['inreplyto'],
            'randomstr' => $comment['randomstr'],
            'rating'    => (int)$comment['rating'],
            'body'      => $comment['body'],
            'time'      => (int)time()
        );
        $this->_conn->modify('
            INSERT INTO commentsposted (ouruserid, messageid, inreplyto, randompart, rating, body, stamp)
            VALUES (
                :userid,
                :messageid,
                :inreplyto,
                :randomstr,
                :rating,
                :body,
                :stamp
            )
        ', $params);
	} # addPostedComment

	/*
	 * Verwijder een setting
	 */
	function removeSetting($name) {
        $this->_conn->exec('DELETE FROM settings WHERE name = :name', array('name' => $name));
	} # removeSetting
	
	/*
	 * Update setting
	 */
	function updateSetting($name, $value) {
		# zet het eventueel serialized in de database als dat nodig is
		if ((is_array($value) || is_object($value))) {
			$value = serialize($value);
			$serialized = true;
		} else {
			$serialized = false;
		} # if
		$params = array(
            'name' => $name,
            'value' => $value,
            'serialized' => $serialized
        );
		switch ($this->_dbsettings['engine']) {
			case 'mysql'		:
			case 'pdo_mysql'	: {
                $this->_conn->modify('
                    INSERT INTO settings (name, value, serialized)
                    VALUES (:name, :value, :serialized)
                    ON DUPLICATE KEY UPDATE value = :value, serialized = :serialized
                ', $params);
                break;
			} # mysql
			
			default				: {
                $this->_conn->exec('
                    UPDATE settings SET value = :value, serialized = :serialized WHERE name = :name
                ', $params);
                if ($this->_conn->rows() == 0) {
                    $this->_conn->exec('
                    INSERT INTO settings (name, value, serialized)
                    VALUES (:name, :value, :serialized)
                    ', $params);
                } # if
                break;
			} # default
		} # switch
	} # updateSetting

	/*
	 * Haalt een session op uit de database
	 */
	function getSession($sessionid, $userid) {
        $params = array(
            'sessionid' => $sessionid,
            'userid'    => $userid
        );
        $tmp = $this->_conn->arrayQuery('
        SELECT sessionid, userid, hitcount, lasthit
        FROM sessions
        WHERE sessionid = :sessionid AND userid = :userid
        ', $params);

		if (!empty($tmp)) {
			return $tmp[0];
		} # if
		
		return false;
	} # getSession

	/*
	 * Creert een sessie
	 */
	function addSession($session) {
        $params = array(
            'sessionid' => $session['sessionid'],
            'userid'    => (int)$session['userid'],
            'hitcount'  => (int)$session['hitcount'],
            'lasthit'   => (int)$session['lasthit']
        );
        $this->_conn->modify('
            INSERT INTO sessions SET
                sessionid = :sessionid,
                userid = :userid,
                hitcount = :hitcount,
                lasthit = :lasthit
        ', $params);
	} # addSession

	/*
	 * Haalt een session op uit de database
	 */
	function deleteSession($sessionid) {
        $this->_conn->modify('DELETE FROM sessions WHERE sessionid = :sessionid', array('sessionid' => $sessionid));
	} # deleteSession

	/*
	 * Haalt een session op uit de database
	 */
	function deleteAllUserSessions($userid) {
        $this->_conn->modify('DELETE FROM sessions WHERE userid = :userid', array('userid' => (int)$userid));
	} # deleteAllUserSessions
	
	/*
	 * Haalt een session op uit de database
	 */
	function deleteExpiredSessions($maxLifeTime) {
        $params = array (
            'lasthit' => time() - $maxLifeTime
        );
        $this->_conn->modify('DELETE FROM sessions WHERE lasthit < :lasthit', $params);
	} # deleteExpiredSessions

	/*
	 * Update de last hit van een session
	 */
	function hitSession($sessionid) {
        $params = array(
            'lasthit' => time(),
            'sessionid' => $sessionid
        );

        $this->_conn->modify('
            UPDATE sessions SET
                hitcount = hitcount + 1,
                lasthit = :lasthit
            WHERE sessionid = :sessionid
        ', $params);
	} # hitSession

	/*
	 * Checkt of een username al bestaat
	 */
	function usernameExists($username) {
        $params = array(
            'username' => $username
        );

		$tmpResult = $this->_conn->singleQuery('
    		SELECT username FROM users WHERE username = :username
		', $params);
		return (!empty($tmpResult));
	} # usernameExists

	/*
	 * Checkt of een emailaddress al bestaat
	 */
	function userEmailExists($mail) {
		$tmpResult = $this->_conn->singleQuery('SELECT id FROM users WHERE mail = :mail LIMIT 1', array('mail' => $mail));
		if (!empty($tmpResult)) {
			return $tmpResult;
		} # if

		return false;
	} # userEmailExists

	/*
	 * Haalt een user op uit de database 
	 */
	function getUser($userid) {
        $params = array(
            'userid' => (int)$userid
        );
        $tmp = $this->_conn->arrayQuery('
            SELECT
                u.username      AS username,
                u.firstname     AS firstname,
                u.lastname      AS lastname,
                u.mail          AS mail,
                u.apikey        AS apikey,
                u.deleted       AS deleted,
                u.lastvisit     AS lastvisit,
                u.lastread      AS lastread,
                u.lastapiusage  AS lastapiusage,
                s.publickey     AS publickey,
                s.otherprefs    AS prefs,
                u.id            AS userid
            FROM users AS u
            JOIN usersettings s ON (u.id = s.userid)
            WHERE u.id = :userid AND NOT DELETED
        ', $params);
		if (!empty($tmp)) {
			# Other preferences worden serialized opgeslagen in de database
			$tmp[0]['prefs'] = unserialize($tmp[0]['prefs']);
			return $tmp[0];
		} # if
		
		return false;
	} # getUser

	/*
	 * Haalt een user op uit de database 
	 */
	function listUsers($username, $pageNr, $limit) {
		SpotTiming::start(__FUNCTION__);
		$offset = (int) $pageNr * (int) $limit;
		$params = array(
            'username' => '%' . $this->safe($username) . '%'
        );

        $tmpResult = $this->_conn->arrayQuery('
            SELECT
                u.id AS userid,
                u.username      AS username,
                u.firstname     AS firstname,
                u.lastname      AS lastname,
                u.mail          AS mail,
                u.lastlogin     AS lastlogin,
                u.lastvisit     AS lastvisit,
                s.otherprefs    AS prefs
            FROM users AS u
            JOIN usersettings s ON (u.id = s.userid)
            WHERE username LIKE :username AND NOT DELETED
            LIMIT ' . (int)($limit + 1) . ' OFFSET ' . (int)$offset
        , $params);
		if (!empty($tmpResult)) {
			# Other preferences worden serialized opgeslagen in de database
			$tmpResultCount = count($tmpResult);
			for($i = 0; $i < $tmpResultCount; $i++) {
				$tmpResult[$i]['prefs'] = unserialize($tmpResult[$i]['prefs']);
			} # for
		} # if

		# als we meer resultaten krijgen dan de aanroeper van deze functie vroeg, dan
		# kunnen we er van uit gaan dat er ook nog een pagina is voor de volgende aanroep
		$hasMore = (count($tmpResult) > $limit);
		if ($hasMore) {
			# verwijder het laatste, niet gevraagde, element
			array_pop($tmpResult);
		} # if

		SpotTiming::stop(__FUNCTION__, array($username, $pageNr, $limit));
		return array('list' => $tmpResult, 'hasmore' => $hasMore);
	} # listUsers

	/*
	 * Disable/delete een user. Echt wissen willen we niet 
	 * omdat eventuele comments dan niet meer te traceren
	 * zouden zijn waardoor anti-spam maatregelen erg lastig
	 * worden
	 */
	function deleteUser($userid) {
        $params = array(
            'userid' => (int)$userid
        );
        $this->_conn->modify('UPDATE users SET deleted = true WHERE id = :userid', $params);
	} # deleteUser

	/*
	 * Update de informatie over een user behalve het password
	 */
	function setUser($user) {
		# eerst updaten we de users informatie
        $params = array(
            'firstname'     => $user['firstname'],
            'lastname'      => $user['lastname'],
            'mail'          => $user['mail'],
            'apikey'        => (int)$user['apikey'],
            'lastlogin'     => (isset($user['lastlogin']) ? (int)$user['lastlogin'] : time()),
            'lastvisit'     => (int)$user['lastvisit'],
            'lastread'      => (int)$user['lastread'],
            'lastapiusage'  => (int)$user['lastapiusage'],
            'deleted'       => (int)$user['deleted'],
            'userid'        => (int)$user['userid']
        );
        $this->_conn->modify('
            UPDATE users
            SET firstname = :firstname,
                lastname = :lastname,
                mail = :mail,
                apikey = :apikey,
                lastlogin = :lastlogin,
                lastvisit = :lastvisit,
                lastread = :lastread,
                lastapiusage = :lastapiusage,
                deleted = :deleted
            WHERE id = :userid
        ', $params);

		# daarna updaten we zijn preferences
        $params = array(
            'prefs' => serialize($user['prefs']),
            'userid' => (int)$user['userid']
        );
		$this->_conn->modify('
            UPDATE usersettings
            SET otherprefs = :prefs
            WHERE userid = :userid
        ', $params);
	} # setUser

	/*
	 * Stel users' password in
	 */
	function setUserPassword($user) {
        $params = array(
            'passhash' => $user['passhash'],
            'userid' => (int)$user['userid']
        );
		$this->_conn->modify('
            UPDATE users
            SET passhash = :passhash
            WHERE id = :userid
        ', $params);
	} # setUserPassword

	/*
	 * Vul de public en private key van een user in, alle andere
	 * user methodes kunnen dit niet updaten omdat het altijd
	 * een paar moet zijn
	 */
	function setUserRsaKeys($userId, $publicKey, $privateKey) {
        $params = array(
            'userid'        => $userId,
            'publickey'     => $publicKey,
            'privatekey'    => $privateKey
        );
		# eerst updaten we de users informatie
		$this->_conn->modify('
            UPDATE usersettings
            SET
                publickey = :publickey,
                privatekey = :privatekey
            WHERE userid = :userid
        ', $params);
	} # setUserRsaKeys 

	/*
	 * Vraagt de users' private key op
	 */
	function getUserPrivateRsaKey($userId) {
        $params = array('userid' => $userId);
		return $this->_conn->singleQuery('SELECT privatekey FROM usersettings WHERE userid = :userid', $params);
	} # getUserPrivateRsaKey

	/* 
	 * Voeg een user toe
	 */
	function addUser($user) {
        $params = array(
            'username'  => $user['username'],
            'firstname' => $user['firstname'],
            'lastname'  => $user['lastname'],
            'passhash'  => $user['passhash'],
            'mail'      => $user['mail'],
            'apikey'    => $user['apikey'],
            'lastread'  => $this->getMaxMessageTime()
        );
		$this->_conn->modify('
            INSERT INTO users(
                username,
                firstname,
                lastname,
                passhash,
                mail,
                apikey,
                lastread,
                deleted
            ) VALUES (
                :username,
                :firstname,
                :lastname,
                :passhash,
                :mail,
                :apikey,
                :lastread,
                0
            )', $params);

		# We vragen nu het userrecord terug op om het userid te krijgen,
		# niet echt een mooie oplossing, maar we hebben blijkbaar geen 
		# lastInsertId() exposed in de db klasse
        # lastInsertId() is geen betrouwbare manier om het ID op te halen, ook is dit alleen ondersteund in mySQL voor
        # zover ik weet - h4rdc0m
        $params = array(
            'username' => $user['username']
        );
		$user['userid'] = $this->_conn->singleQuery('
		    SELECT id
		    FROM users
		    WHERE username = :username
		    LIMIT 1
        ', $params);

		# en voeg een usersettings record in
        $params = array(
            'userid' => $user['userid']
        );
		$this->_conn->modify('
            INSERT INTO usersettings (userid, privatekey, publickey, otherprefs)
            VALUES(:userid, \'\', \'\', \'a:0:{}\')
        ', $params);
        
		return $user;
	} # addUser

	/*
	 * Kan de user inloggen met opgegeven password of API key?
	 *
	 * Een userid als de user gevonden kan worden, of false voor failure
	 */
	function authUser($username, $passhash) {
		if ($username === false) {
			$tmp = $this->_conn->arrayQuery('
			    SELECT id
			    FROM users
			    WHERE apikey = :apikey AND NOT DELETED
            ', array('apikey' => $passhash));
		} else {
			$tmp = $this->_conn->arrayQuery('
			    SELECT id
			    FROM users
			    WHERE username = :username
			    AND passhash = :passhash AND NOT DELETED
            ', array('username' => $username, 'passhash' => $passhash));
		} # if

		return (empty($tmp)) ? false : $tmp[0]['id'];
	} # authUser

	/* 
	 * Update of insert the maximum article id in de database.
	 */
	function setMaxArticleId($server, $maxarticleid) {
		# Replace INTO reset de kolommen die we niet updaten naar 0 en dat is stom
        $params = array(
            'maxarticleid'  => (int)$maxarticleid,
            'server'        => $server
        );
		$this->_conn->exec('UPDATE nntp SET maxarticleid = :maxarticleid WHERE server = :server', $params);

		if ($this->_conn->rows() == 0) {	
			$this->_conn->exec('INSERT INTO nntp(server, maxarticleid) VALUES(:server, :maxarticleid)', $params);
		} # if
	} # setMaxArticleId()

	/*
	 * Vraag het huidige articleid (van de NNTP server) op, als die nog 
	 * niet bestaat, voeg dan een nieuw record toe en zet die op 0
	 */
	function getMaxArticleId($server) {
        $params = array('server' => $server);
		$artId = $this->_conn->singleQuery('SELECT maxarticleid FROM nntp WHERE server = :server', $params);
		if ($artId == null) {
			$this->setMaxArticleId($server, 0);
			$artId = 0;
		} # if

		return $artId;
	} # getMaxArticleId

	/* 
	 * Returns the highest messageid from server 
	 */
	function getMaxMessageId($headers) {
		if ($headers == 'headers') {
			$msgIds = $this->_conn->arrayQuery('SELECT messageid FROM spots ORDER BY id DESC LIMIT 5000');
		} else {
			$msgIds = $this->_conn->arrayQuery('SELECT messageid FROM commentsxover ORDER BY id DESC LIMIT 5000');
		} # else
		if ($msgIds == null) {
			return array();
		} # if

		$tempMsgIdList = array();
		$msgIdCount = count($msgIds);
		for($i = 0; $i < $msgIdCount; $i++) {
			$tempMsgIdList['<' . $msgIds[$i]['messageid'] . '>'] = 1;
		} # for
		return $tempMsgIdList;
	} # func. getMaxMessageId

	function getMaxMessageTime() {
		$stamp = $this->_conn->singleQuery('SELECT MAX(stamp) AS stamp FROM spots');
		if ($stamp == null) {
			$stamp = time();
		} # if

		return $stamp;
	} # getMaxMessageTime()

	/*
	 * Geeft een database engine specifieke text-match (bv. fulltxt search) query onderdeel terug
	 */
	function createTextQuery($fieldList) {
		return $this->_conn->createTextQuery($fieldList);
	} # createTextQuery()

	/*
	 * Geef terug of de huidige nntp server al bezig is volgens onze eigen database
	 */
	function isRetrieverRunning($server) {
        $params = array('server' => $server);
		$artId = $this->_conn->singleQuery('SELECT nowrunning FROM nntp WHERE server = :server', $params);
		return ((!empty($artId)) && ($artId > (time() - 900)));
	} # isRetrieverRunning

	/*
	 * Geef terug of de huidige nntp server al bezig is volgens onze eigen database
	 */
	function setRetrieverRunning($server, $isRunning) {
		if ($isRunning) {
			$runTime = time();
		} else {
			$runTime = 0;
		} # if

        $params = array(
            'server' => $server,
            'nowrunning' => $runTime
        );
		switch ($this->_dbsettings['engine']) {

			case 'mysql'		:
			case 'pdo_mysql' 	: {
				$this->_conn->modify('
				    INSERT INTO nntp (server, nowrunning)
				    VALUES (:server, :nowrunning) ON DUPLICATE KEY UPDATE nowrunning = :nowrunning
                ', $params);
				break;
			} # mysql
			
			default				: {
				$this->_conn->modify('UPDATE nntp SET nowrunning = :nowrunning WHERE server = :server', $params);
				if ($this->_conn->rows() == 0) {
					$this->_conn->modify('INSERT INTO nntp(server, nowrunning) VALUES(:server, :nowrunning)', $params);
				} # if
			} # default
		} # switch
	} # setRetrieverRunning

	/*
	 * Remove extra spots 
	 */
	function removeExtraSpots($messageId) {
		# vraag eerst het id op
		$spot = $this->getSpotHeader($messageId);

		# als deze spot leeg is, is er iets raars aan de hand
		if (empty($spot)) {
			throw new Exception("Our highest spot is not in the database!?");
		} # if

        $params = array('spotid' => $spot['id']);
		# en wis nu alles wat 'jonger' is dan deze spot
		switch ($this->_dbsettings['engine']) {
			# geen join delete omdat sqlite dat niet kan
			case 'pdo_pgsql'  : 
			case 'pdo_sqlite' : {
				$this->_conn->modify('
				    DELETE FROM spotsfull
				    WHERE messageid IN (SELECT messageid FROM spots WHERE id > :spotid)
                ', $params);
				$this->_conn->modify('DELETE FROM spots WHERE id > :spotid', $params);
				break;
			} # case

			default			  : {
				$this->_conn->modify('
                    DELETE FROM spots, spotsfull USING spots
                    LEFT JOIN spotsfull on spots.messageid=spotsfull.messageid
                    WHERE spots.id > :spotid
                ', $params);
			} # default
		} # switch
	} # removeExtraSpots

	/*
	 * Remove extra comments
	 */
	function removeExtraComments($messageId) {
		# vraag eerst het id op
        $params = array('messageid' => $messageId);
		$commentId = $this->_conn->singleQuery('
		    SELECT id FROM commentsxover WHERE messageid = :messageid
        ', $params);
		
		# als deze spot leeg is, is er iets raars aan de hand
		if (empty($commentId)) {
			throw new Exception("Our highest comment is not in the database!?");
		} # if

		# en wis nu alles wat 'jonger' is dan deze spot
        $params = array('commentid' => $commentId);
		$this->_conn->modify('DELETE FROM commentsxover WHERE id > :commentid', $params);
	} # removeExtraComments

	/*
	 * Zet de tijd/datum wanneer retrieve voor het laatst geupdate heeft
	 */
	function setLastUpdate($server) {
	    $params = array(
	        'lastrun' => time(),
            'server' => $server
	    );
		return $this->_conn->modify('UPDATE nntp SET lastrun = :lastrun WHERE server = :server', $params);
	} # getLastUpdate

	/*
	 * Geef de datum van de laatste update terug
	 */
	function getLastUpdate($server) {
        $params = array('server' => $server);
		return $this->_conn->singleQuery('SELECT lastrun FROM nntp WHERE server = :server', $params);
	} # getLastUpdate

	/**
	 * Geef het aantal spots terug dat er op dit moment in de db zit
	 */
	function getSpotCount($sqlFilter) {
		SpotTiming::start(__FUNCTION__);
		if (empty($sqlFilter)) {
			$query = "SELECT COUNT(1) FROM spots AS s";
		} else {
			$query = "SELECT COUNT(1) FROM spots AS s 
						LEFT JOIN spotsfull AS f ON s.messageid = f.messageid
						LEFT JOIN spotstatelist AS l ON s.messageid = l.messageid
						WHERE " . $sqlFilter;
		} # else
		$cnt = $this->_conn->singleQuery($query);
		SpotTiming::stop(__FUNCTION__, array($sqlFilter));
		if ($cnt == null) {
			return 0;
		} else {
			return $cnt;
		} # if
	} # getSpotCount

	/*
	 * Match set of comments
	 */
	function matchCommentMessageIds($hdrList) {
		# We negeren commentsfull hier een beetje express, als die een 
		# keer ontbreken dan fixen we dat later wel.
		$idList = array();

		# geen message id's gegeven? vraag het niet eens aan de db
		if (count($hdrList) == 0) {
			return $idList;
		} # if

		# bereid de lijst voor met de queries in de where
		$msgIdList = '';
		foreach($hdrList as $hdr) {
			$msgIdList .= "'" . substr($this->_conn->safe($hdr['Message-ID']), 1, -1) . "', ";
		} # foreach
		$msgIdList = substr($msgIdList, 0, -2);

		# en vraag alle comments op die we kennen
		$rs = $this->_conn->arrayQuery("SELECT messageid FROM commentsxover WHERE messageid IN (" . $msgIdList . ")");

		# geef hier een array terug die kant en klaar is voor array_search
		foreach($rs as $msgids) {
			$idList[$msgids['messageid']] = 1;
		} # foreach
		
		return $idList;
	} # matchCommentMessageIds

	/*
	 * Match set of spots
	 */
	function matchSpotMessageIds($hdrList) {
		$idList = array('spot' => array(), 'fullspot' => array());

		# geen message id's gegeven? vraag het niet eens aan de db
		if (count($hdrList) == 0) {
			return $idList;
		} # if

		# bereid de lijst voor met de queries in de where
		$msgIdList = '';
		foreach($hdrList as $hdr) {
			$msgIdList .= "'" . substr($this->_conn->safe($hdr['Message-ID']), 1, -1) . "', ";
		} # foreach
		$msgIdList = substr($msgIdList, 0, -2);

		# Omdat MySQL geen full joins kent, doen we het zo
		$rs = $this->_conn->arrayQuery("SELECT messageid AS spot, '' AS fullspot FROM spots WHERE messageid IN (" . $msgIdList . ")
											UNION
					 				    SELECT '' as spot, messageid AS fullspot FROM spotsfull WHERE messageid IN (" . $msgIdList . ")");

		# en lossen we het hier op
		foreach($rs as $msgids) {
			if (!empty($msgids['spot'])) {
				$idList['spot'][$msgids['spot']] = 1;
			} # if

			if (!empty($msgids['fullspot'])) {
				$idList['fullspot'][$msgids['fullspot']] = 1;
			} # if
		} # foreach

		return $idList;
	} # matchMessageIds 

	/*
	 * Geef alle spots terug in de database die aan $parsedSearch voldoen.
	 * 
	 */
	function getSpots($ourUserId, $pageNr, $limit, $parsedSearch) {
		SpotTiming::start(__FUNCTION__);
		$results = array();
		$offset = (int) $pageNr * (int) $limit;

		# je hebt de zoek criteria (category, titel, etc)
		$criteriaFilter = $parsedSearch['filter'];
		if (!empty($criteriaFilter)) {
			$criteriaFilter = ' WHERE ' . $criteriaFilter;
		} # if 

		# er kunnen ook nog additionele velden gevraagd zijn door de filter parser
		# als dat zo is, voeg die dan ook toe
		$extendedFieldList = '';
		foreach($parsedSearch['additionalFields'] as $additionalField) {
			$extendedFieldList = ', ' . $additionalField . $extendedFieldList;
		} # foreach

		# ook additionele tabellen kunnen gevraagd zijn door de filter parser, die 
		# moeten we dan ook toevoegen
		$additionalTableList = '';
		foreach($parsedSearch['additionalTables'] as $additionalTable) {
			$additionalTableList = ', ' . $additionalTable . $additionalTableList;
		} # foreach
		
		# Nu prepareren we de sorterings lijst
		$sortFields = $parsedSearch['sortFields'];
		$sortList = array();
		foreach($sortFields as $sortValue) {
			if (!empty($sortValue)) {
				# als er gevraagd is om op 'stamp' descending te sorteren, dan draaien we dit
				# om en voeren de query uit reversestamp zodat we een ASCending sort doen. Dit maakt
				# het voor MySQL ISAM een stuk sneller
				if ((strtolower($sortValue['field']) == 's.stamp') && strtolower($sortValue['direction']) == 'desc') {
					$sortValue['field'] = 's.reversestamp';
					$sortValue['direction'] = 'ASC';
				} # if
				
				$sortList[] = $sortValue['field'] . ' ' . $sortValue['direction'];
			} # if
		} # foreach
		$sortList = implode(', ', $sortList);

		# en voer de query uit. 
		# We vragen altijd 1 meer dan de gevraagde limit zodat we ook een hasMore boolean flag
		# kunnen zetten.
 		$tmpResult = $this->_conn->arrayQuery("SELECT s.id AS id,
												s.messageid AS messageid,
												s.category AS category,
												s.poster AS poster,
												l.download as downloadstamp, 
												l.watch as watchstamp,
												l.seen AS seenstamp,
												s.subcata AS subcata,
												s.subcatb AS subcatb,
												s.subcatc AS subcatc,
												s.subcatd AS subcatd,
												s.subcatz AS subcatz,
												s.title AS title,
												s.tag AS tag,
												s.stamp AS stamp,
												s.moderated AS moderated,
												s.filesize AS filesize,
												s.spotrating AS rating,
												s.commentcount AS commentcount,
												f.userid AS userid,
												f.verified AS verified
												" . $extendedFieldList . "
									 FROM spots AS s " . 
									 $additionalTableList . 
								   " LEFT JOIN spotstatelist AS l on ((s.messageid = l.messageid) AND (l.ouruserid = " . $this->safe( (int) $ourUserId) . ")) 
									 LEFT JOIN spotsfull AS f ON (s.messageid = f.messageid) " .
									 $criteriaFilter . " 
									 ORDER BY " . $sortList . 
								   " LIMIT " . (int) ($limit + 1) ." OFFSET " . (int) $offset);

		# als we meer resultaten krijgen dan de aanroeper van deze functie vroeg, dan
		# kunnen we er van uit gaan dat er ook nog een pagina is voor de volgende aanroep
		$hasMore = (count($tmpResult) > $limit);
		if ($hasMore) {
			# verwijder het laatste, niet gevraagde, element
			array_pop($tmpResult);
		} # if

		SpotTiming::stop(__FUNCTION__, array($ourUserId, $pageNr, $limit, $criteriaFilter));
		return array('list' => $tmpResult, 'hasmore' => $hasMore);
	} # getSpots()

	/*
	 * Geeft enkel de header van de spot terug
	 */
	function getSpotHeader($msgId) {
		SpotTiming::start(__FUNCTION__);
        $params = array ('messageid' => $msgId);
		$tmpArray = $this->_conn->arrayQuery('
            SELECT s.id AS id,
                 s.messageid AS messageid,
                 s.category AS category,
                 s.poster AS poster,
                 s.subcata AS subcata,
                 s.subcatb AS subcatb,
                 s.subcatc AS subcatc,
                 s.subcatd AS subcatd,
                 s.subcatz AS subcatz,
                 s.title AS title,
                 s.tag AS tag,
                 s.stamp AS stamp,
                 s.spotrating AS rating,
                 s.commentcount AS commentcount,
                 s.moderated AS moderated
            FROM spots AS s
            WHERE s.messageid = :messageid
        ', $params);
		if (empty($tmpArray)) {
			return ;
		} # if
		SpotTiming::stop(__FUNCTION__);
		return $tmpArray[0];
	} # getSpotHeader 

	/*
	 * Vraag 1 specifieke spot op, als de volledig spot niet in de database zit
	 * geeft dit NULL terug
	 */
	function getFullSpot($messageId, $ourUserId) {
		SpotTiming::start(__FUNCTION__);
        $params = array(
            'messageid' => $messageId,
            'userid' => $ourUserId
        );
		$tmpArray = $this->_conn->arrayQuery('
            SELECT s.id AS id,
                s.messageid AS messageid,
                s.category AS category,
                s.poster AS poster,
                s.subcata AS subcata,
                s.subcatb AS subcatb,
                s.subcatc AS subcatc,
                s.subcatd AS subcatd,
                s.subcatz AS subcatz,
                s.title AS title,
                s.tag AS tag,
                s.stamp AS stamp,
                s.moderated AS moderated,
                s.spotrating AS rating,
                s.commentcount AS commentcount,
                s.filesize AS filesize,
                l.download AS downloadstamp,
                l.watch as watchstamp,
                l.seen AS seenstamp,
                f.userid AS userid,
                f.verified AS verified,
                f.usersignature AS "user-signature",
                f.userkey AS "user-key",
                f.xmlsignature AS "xml-signature",
                f.fullxml AS fullxml
            FROM spots AS s
            LEFT JOIN spotstatelist AS l ON s.messageid = l.messageid AND l.ouruserid = :userid
            JOIN spotsfull AS f ON f.messageid = s.messageid
            WHERE s.messageid = :messageid
        ', $params);
		if (empty($tmpArray)) {
			return ;
		} # if
		$tmpArray = $tmpArray[0];

		# If spot is fully stored in db and is of the new type, we process it to
		# make it exactly the same as when retrieved using NNTP
		if (!empty($tmpArray['fullxml']) && (!empty($tmpArray['user-signature']))) {
			$tmpArray['user-key'] = unserialize(base64_decode($tmpArray['user-key']));
		} # if

		SpotTiming::stop(__FUNCTION__, array($messageId, $ourUserId));
		return $tmpArray;		
	} # getFullSpot()

	/*
	 * Insert commentreg, 
	 *   messageid is het werkelijke commentaar id
	 *   nntpref is de id van de spot
	 */
	function addCommentRef($messageid, $nntpref, $rating) {
        $params = array(
            'messageid' => $messageid,
            'nntpref'   => $nntpref,
            'rating'    => $rating
        );
		$this->_conn->modify('
		    INSERT INTO commentsxover(messageid, nntpref, spotrating)
		    VALUES(:messageid, :nntpref, :rating)
        ', $params);
	} # addCommentRef

	/*
	 * Insert commentfull, gaat er van uit dat er al een commentsxover entry is
	 */
	function addCommentsFull($commentList) {
        $query = '
            INSERT INTO commentsfull (messageid, fromhdr, stamp, usersignature, userkey, userid, body, verified)
            VALUES (:messageid, :fromhdr, :stamp, :usersignature, :userkey, :userid, :body, :verified)
        ';
		foreach($commentList as $comment) {
			# Kap de verschillende strings af op een maximum van 
			# de datastructuur, de unique keys kappen we expres niet af
			$params = array (
                'messageid'     => $comment['messageid'],
                'fromhdr'       => substr($comment['fromhdr'], 0, 127),
                'stamp'         => $comment['stamp'],
                'usersignature' => $comment['usersignature'],
                'userkey'       => serialize($comment['user-key']),
                'userid'        => $comment['userid'],
                'body'          => implode("\r\n", $comment['body']),
                'verified'       => $comment['verified']
            );
			$this->_conn->modify($query, $params);
		} # foreach
	} # addCommentFull

	/*
	 * Update een lijst van messageid's met de gemiddelde spotrating
	 */
	function updateSpotRating($spotMsgIdList) {
		# Geen message id's gegeven? Doe niets!
		if (count($spotMsgIdList) == 0) {
			return;
		} # if

		# bereid de lijst voor met de queries in de where
		$msgIdList = '';
		foreach($spotMsgIdList as $spotMsgId) {
			$msgIdList .= "'" . $this->_conn->safe($spotMsgId) . "', ";
		} # foreach
		$msgIdList = substr($msgIdList, 0, -2);

		# en update de spotrating
		$this->_conn->modify("UPDATE spots 
								SET spotrating = 
									(SELECT AVG(spotrating) as spotrating 
									 FROM commentsxover 
									 WHERE 
										spots.messageid = commentsxover.nntpref 
										AND spotrating BETWEEN 1 AND 10
									 GROUP BY nntpref)
							WHERE spots.messageid IN (" . $msgIdList . ")
						");
	} # updateSpotRating

	/*
	 * Update een lijst van messageid's met het aantal niet geverifieerde comments
	 */
	function updateSpotCommentCount($spotMsgIdList) {
		if (count($spotMsgIdList) == 0) {
			return;
		} # if

		# bereid de lijst voor met de queries in de where
		$msgIdList = '';
		foreach($spotMsgIdList as $spotMsgId) {
			$msgIdList .= "'" . $this->_conn->safe($spotMsgId) . "', ";
		} # foreach
		$msgIdList = substr($msgIdList, 0, -2);

		# en update de spotrating
		$this->_conn->modify("UPDATE spots 
								SET commentcount = 
									(SELECT COUNT(1) as commentcount 
									 FROM commentsxover 
									 WHERE 
										spots.messageid = commentsxover.nntpref 
									 GROUP BY nntpref)
							WHERE spots.messageid IN (" . $msgIdList . ")
						");
	} # updateSpotCommentCount

	/*
	 * Vraag de volledige commentaar lijst op, gaat er van uit dat er al een commentsxover entry is
	 */
	function getCommentsFull($nntpRef) {
		SpotTiming::start(__FUNCTION__);
        $params = array('nntpref' => $nntpRef);

		# en vraag de comments daadwerkelijk op
		$commentList = $this->_conn->arrayQuery('
            SELECT c.messageid AS messageid,
                (f.messageid IS NOT NULL) AS havefull,
                f.fromhdr AS fromhdr,
                f.stamp AS stamp,
                f.usersignature AS usersignature,
                f.userkey AS "user-key",
                f.userid AS userid,
                f.body AS body,
                f.verified AS verified,
                c.spotrating AS spotrating
            FROM commentsfull f
            RIGHT JOIN commentsxover c on (f.messageid = c.messageid)
            WHERE c.nntpref = :nntpref
            ORDER BY c.id
        ', $params);

		$commentListCount = count($commentList);
		for($i = 0; $i < $commentListCount; $i++) {
			if ($commentList[$i]['havefull']) {
				$commentList[$i]['user-key'] = base64_decode($commentList[$i]['user-key']);
				$commentList[$i]['body'] = explode("\r\n", utf8_decode($commentList[$i]['body']));
			} # if
		} # foreach

		SpotTiming::stop(__FUNCTION__);
		return $commentList;
	} # getCommentsFull

	/*
	 * Geeft huidig database schema versie nummer terug
	 */
	function getSchemaVer() {
		return $this->_conn->singleQuery("SELECT value FROM settings WHERE name = 'schemaversion'");
	} # getSchemaVer

	/* 
	 * Is onze database versie nog wel geldig?
	 */
	function schemaValid() {
		$schemaVer = $this->getSchemaVer();

		# SPOTDB_SCHEMA_VERSION is gedefinieerd bovenin dit bestand
		return ($schemaVer == SPOTDB_SCHEMA_VERSION);
	} # schemaValid

	/*
	 * Verwijder een spot uit de db
	 */
	function deleteSpot($msgId) {
        $params = array('messageid' => $msgId);
		switch ($this->_dbsettings['engine']) {
			case 'pdo_pgsql'  : 
			case 'pdo_sqlite' : {
				$this->_conn->modify('DELETE FROM spots WHERE messageid = :messageid', $params);
				$this->_conn->modify('DELETE FROM spotsfull WHERE messageid = :messageid', $params);
				$this->_conn->modify('DELETE FROM commentsfull WHERE messageid IN (SELECT messageid FROM commentsxover WHERE nntpref= :messageid)', $params);
				$this->_conn->modify('DELETE FROM commentsxover WHERE nntpref = :messageid', $params);
				$this->_conn->modify('DELETE FROM spotstatelist WHERE messageid = :messageid', $params);
				break; 
			} # pdo_sqlite
			
			default			: {
				$this->_conn->modify('
                    DELETE FROM spots, spotsfull, commentsxover, spotstatelist USING spots
                    LEFT JOIN spotsfull ON spots.messageid=spotsfull.messageid
                    LEFT JOIN commentsxover ON spots.messageid=commentsxover.nntpref
                    LEFT JOIN spotstatelist ON spots.messageid=spotstatelist.messageid
                    WHERE spots.messageid = :messageid
                ', $params);
			} # default
		} # switch
	} # deleteSpot

	/*
	 * Markeer een spot in de db moderated
	 */
	function markSpotModerated($msgId) {
        $params = array('messageid' => $msgId);
		$this->_conn->modify('UPDATE spots SET moderated = 1 WHERE messageid = :messageid', $params);
	} # markSpotModerated

	/*
	 * Verwijder oude spots uit de db
	 */
	function deleteSpotsRetention($retention) {
		$retention = $retention * 24 * 60 * 60; // omzetten in seconden
        $params = array('retention' => $retention);
		switch ($this->_dbsettings['engine']) {
			case 'pdo_pgsql' : 
 			case 'pdo_sqlite': {
				$this->_conn->modify('DELETE FROM spots WHERE spots.stamp < :retention', $params);
				$this->_conn->modify('DELETE FROM spotsfull WHERE spotsfull.messageid not in
									(SELECT messageid FROM spots)') ;
				$this->_conn->modify('DELETE FROM commentsfull WHERE messageid IN
									(SELECT messageid FROM commentsxover WHERE commentsxover.nntpref not in 
									(SELECT messageid FROM spots))') ;
				$this->_conn->modify('DELETE FROM commentsxover WHERE commentsxover.nntpref not in
									(SELECT messageid FROM spots)') ;
				$this->_conn->modify('DELETE FROM spotstatelist WHERE spotstatelist.messageid not in
									(SELECT messageid FROM spots)') ;
				break;
			} # pdo_sqlite
			default		: {
				$this->_conn->modify('
                    DELETE FROM spots, spotsfull, commentsxover, spotstatelist USING spots
					LEFT JOIN spotsfull ON spots.messageid=spotsfull.messageid
					LEFT JOIN commentsxover ON spots.messageid=commentsxover.nntpref
					LEFT JOIN spotstatelist ON spots.messageid=spotstatelist.messageid
					WHERE spots.stamp < :retention
                ', $params);
			} # default
		} # switch
	} # deleteSpotsRetention

	/*
	 * Voeg een spot toe aan de database
	 */
	function addSpot($spot, $fullSpot = array()) {
		# we checken hier handmatig of filesize wel numeriek is, dit is omdat printen met %d in sommige PHP
		# versies een verkeerde afronding geeft bij >32bits getallen.
		if (!is_numeric($spot['filesize'])) {
			$spot['filesize'] = 0;
		} # if
		
		# Kap de verschillende strings af op een maximum van 
		# de datastructuur, de unique keys kappen we expres niet af

        $params = array(
            'messageid'     => $spot['messageid'],
            'poster'        => substr($spot['poster'], 0, 127),
            'title'         => substr($spot['title'], 0, 127),
            'tag'           => substr($spot['tag'], 0, 127),
            'category'      => (int)$spot['category'],
            'subcata'       => substr($spot['subcata'], 0, 63),
            'subcatb'       => substr($spot['subcatb'], 0, 63),
            'subcatc'       => substr($spot['subcatc'], 0, 63),
            'subcatd'       => substr($spot['subcatd'], 0, 63),
            'subcatz'       => $spot['subcatz'],
            'stamp'         => $spot['stamp'],
            'reversestamp'  => ($spot['stamp'] * -1),
            'filesize'      => $spot['filesize']
        );
		$this->_conn->modify('
            INSERT INTO spots (messageid, poster, title, tag, category, subcata, subcatb, subcatc, subcatd, subcatz, stamp, reversestamp, filesize)
            VALUES (:messageid, :poster, :title, :tag, :category, :subcata, :subcatb, :subcatc, :subcatd, :subcatz, :stamp, :reversestamp, :filesize)
        ', $params);

		if (!empty($fullSpot)) {
			$this->addFullSpot($fullSpot);
		} # if
	} # addSpot()

	/*
	 * Voeg enkel de full spot toe aan de database, niet gebruiken zonder dat er een entry in 'spots' staat
	 * want dan komt deze spot niet in het overzicht te staan.
	 */
	function addFullSpot($fullSpot) {
		# Kap de verschillende strings af op een maximum van 
		# de datastructuur, de unique keys en de RSA keys en dergeijke
		# kappen we expres niet af

        $params = array (
            'messageid'     => $fullSpot['messageid'],
            'userid'        => substr($fullSpot['userid'], 0, 31),
            'verified'      => (int) $fullSpot['verified'],
            'usersignature' => $fullSpot['user-signature'],
            'userkey'       => base64_encode(serialize($fullSpot['user-key'])),
            'xmlsignature'  => $fullSpot['xml-signature'],
            'fullxml'       => $fullSpot['fullxml']
        );
		# en voeg het aan de database toe
		$this->_conn->modify('
            INSERT INTO spotsfull(messageid, userid, verified, usersignature, userkey, xmlsignature, fullxml)
            VALUES (:messageid, :userid, :verified, :usersignature, :userkey, :xmlsignature, :fullxml)
        ', $params);
	} # addFullSpot

	function addToSpotStateList($list, $messageId, $ourUserId, $stamp='') {
		SpotTiming::start(__FUNCTION__);
		$verifiedList = $this->verifyListType($list);
		if (empty($stamp)) { $stamp = time(); }
        $params = array (
            'messageid' => $messageId,
            'userid' => (int)$ourUserId,
            'stamp' => $stamp
        );
		switch ($this->_dbsettings['engine']) {
			case 'pdo_mysql'	:
			case 'mysql'		:  {
				$this->_conn->modify('
				    INSERT INTO spotstatelist (messageid, ouruserid, ' . $verifiedList . ')
				    VALUES (:messageid, :userid, :stamp)
				    ON DUPLICATE KEY UPDATE ' . $verifiedList . ' = :stamp
                ', $params);
				break;
			} # mysql
			
			default				:  {
				$this->_conn->modify('
				    UPDATE spotstatelist
				    SET ' . $verifiedList . ' = :stamp
				    WHERE messageid = :messageid AND ouruserid = :userid
                ', $params);
				if ($this->_conn->rows() == 0) {
					$this->_conn->modify('
					    INSERT INTO spotstatelist (messageid, ouruserid, " . $verifiedList . ")
					    VALUES (:messageid, :userid, :stamp)
                    ', $params);
				} # if
			} # default
		} # switch
		SpotTiming::stop(__FUNCTION__, array($list, $messageId, $ourUserId, $stamp));
	} # addToSpotStateList

	function clearSpotStateList($list, $ourUserId) {
		SpotTiming::start(__FUNCTION__);
		$verifiedList = $this->verifyListType($list);
		$this->_conn->modify('
            UPDATE spotstatelist
            SET ' . $verifiedList . ' = NULL
            WHERE ouruserid = :userid
        ', array('userid' => $ourUserId));
		SpotTiming::stop(__FUNCTION__, array($list, $ourUserId));
	} # clearSpotStatelist

	function cleanSpotStateList() {
		$this->_conn->rawExec("DELETE FROM spotstatelist WHERE download IS NULL AND watch IS NULL AND seen IS NULL");
	} # cleanSpotStateList

	function removeFromSpotStateList($list, $messageid, $ourUserId) {
		SpotTiming::start(__FUNCTION__);
		$verifiedList = $this->verifyListType($list);
        $params = array(
            'messageid' => $messageid,
            'userid' => $ourUserId
        );
		$this->_conn->modify('
		    UPDATE spotstatelist SET ' . $verifiedList . ' = NULL
		    WHERE messageid = :messageid AND ouruserid = :userid
		    LIMIT 1
        ', $params);

		SpotTiming::stop(__FUNCTION__, array($list, $messageid, $ourUserId));
	} # removeFromSpotStateList

	function verifyListType($list) {
		switch($list) {
			case self::spotstate_Down	: $verifiedList = 'download'; break;
			case self::spotstate_Watch	: $verifiedList = 'watch'; break;
			case self::spotstate_Seen	: $verifiedList = 'seen'; break;
			default						: throw new Exception("Invalid listtype given!");
		} # switch

		return $verifiedList;
	} # verifyListType
	
	
	/* 
	 * Geeft de permissies terug van een bepaalde groep
	 */
	function getGroupPerms($groupId) {
        $params = array('groupid' => $groupId);
		return $this->_conn->arrayQuery('
		    SELECT permissionid, objectid, deny
		    FROM grouppermissions
		    WHERE groupid = :groupid
        ', $params);
	} # getgroupPerms
	
	/*
	 * Geeft permissies terug welke user heeft, automatisch in het formaat zoals
	 * SpotSecurity dat heeft (maw - dat de rechtencheck een simpele 'isset' is om 
	 * overhead te voorkomen
	 */
	function getPermissions($userId) {
		$permList = array();
        $params = array('userid' => $userId);
		$tmpList = $this->_conn->arrayQuery('
            SELECT permissionid, objectid, deny
            FROM grouppermissions
            WHERE groupid IN
                (SELECT groupid FROM usergroups WHERE userid = :userid ORDER BY prio)
        ', $params);

		foreach($tmpList as $perm) {
			# Voeg dit permissionid toe aan de lijst met permissies
			if (!isset($permList[$perm['permissionid']])) {
				$permList[$perm['permissionid']] = array();
			} # if
			
			$permList[$perm['permissionid']][$perm['objectid']] = !(boolean) $perm['deny'];
		} # foreach
		
		return $permList;
	} # getPermissions

	/*
	 * Geeft alle gedefinieerde groepen terug
	 */
	function getGroupList($userId) {
		if ($userId == null) {
			return $this->_conn->arrayQuery("SELECT id,name,0 as \"ismember\" FROM securitygroups");
		} else {
            $params = array(
                'userid' => $userId
            );
			return $this->_conn->arrayQuery('
                SELECT sg.id,name,ug.userid IS NOT NULL as "ismember"
                FROM securitygroups sg
                LEFT JOIN usergroups ug ON sg.id = ug.groupid AND ug.userid = :userid
            ', $params);
		} # if
	} # getGroupList
	
	/*
	 * Verwijdert een permissie uit een security group
	 */
	function removePermFromSecGroup($groupId, $perm) {
        $params = array(
            'groupid' => $groupId,
            'permissionid' => $perm['permissionid'],
            'objectid'  => $perm['objectid']
        );
		$this->_conn->modify('
		    DELETE FROM grouppermissions
		    WHERE groupid = :groupid
		    AND permissionid = :permissionid
		    AND objectid = :objectid
        ', $params);
	} # removePermFromSecGroup
	
	/*
	 * Voegt een permissie aan een security group toe
	 */
	function addPermToSecGroup($groupId, $perm) {
        $params = array(
            'groupid' => $groupId,
            'permissionid' => $perm['permissionid'],
            'objectid'     => $perm['objectid']
        );
		$this->_conn->modify('
		    INSERT INTO grouppermissions(groupid,permissionid,objectid)
		    VALUES (:groupid, :permissionid, :objectid)
        ', $params);
	} # addPermToSecGroup

	/*
	 * Geef een specifieke security group terug
	 */
	function getSecurityGroup($groupId) {
		return $this->_conn->arrayQuery('
		    SELECT id,name
		    FROM securitygroups
		    WHERE id = :groupid
		', array('groupid' => $groupId));
	} # getSecurityGroup
		
	/*
	 * Geef een specifieke security group terug
	 */
	function setSecurityGroup($group) {
        $params = array(
            'name' => $group['name'],
            'id' => $group['id']
        );
		$this->_conn->modify('
		    UPDATE securitygroups
		    SET name = :name
		    WHERE id = :id
        ', $params);
	} # setSecurityGroup
	
	/*
	 * Geef een specifieke security group terug
	 */
	function addSecurityGroup($group) {
		$this->_conn->modify('
		    INSERT INTO securitygroups(name)
		    VALUES (:name)
        ', array('name' => $group['name']));
	} # addSecurityGroup

	/*
	 * Geef een specifieke security group terug
	 */
	function removeSecurityGroup($group) {
		$this->_conn->modify('
		    DELETE FROM securitygroups
		    WHERE id = :id
		', Array('id' => $group['id']));
	} # removeSecurityGroup
	
	/*
	 * Wijzigt group membership van een user
	 */
	function setUserGroupList($userId, $groupList) {
		# We wissen eerst huidige group membership
		$this->_conn->modify('
            DELETE FROM usergroups
            WHERE userid = :userid
        ', array('userid' => $userId));

        $query = '
            INSERT INTO usergroups(userid,groupid,prio)
            VALUES(:userid, :groupid, :prio)
        ';
		foreach($groupList as $groupInfo) {
            $params = array(
                'userid' => $userId,
                'groupid' => $groupInfo['groupid'],
                'prio' => $groupInfo['prio']
            );
			$this->_conn->modify($query, $params);
		} # foreach
	} # setUserGroupList
	
	/*
	 * Voegt een nieuwe notificatie toe
	 */
	function addNewNotification($userId, $objectId, $type, $title, $body) {
        $params = array(
            'userid' => $userId,
            'stamp' => time(),
            'objectid' => $objectId,
            'type' => $type,
            'title' => $title,
            'body'  => $body,
            'sent' => 0
        );
		$this->_conn->modify('
		    INSERT INTO notifications(userid,stamp,objectid,type,title,body,sent)
		    VALUES(:userid, :stamp, :objectid, :type, :title, :body, :sent)
        ', $params);
	} # addNewNotification
	
	/*
	 * Haalt niet-verzonden notificaties op van een user
	 */
	function getUnsentNotifications($userId) {
		$tmpResult = $this->_conn->arrayQuery('
            SELECT id,userid,objectid,type,title,body
            FROM notifications
            WHERE userid = :userid
            AND NOT SENT
        ', array('userid' => $userId));
		return $tmpResult;
	} # getUnsentNotifications

	/* 
	 * Een notificatie updaten
	 */
	function updateNotification($msg) {
        $params = array(
            'title' => $msg['title'],
            'body' => $msg['body'],
            'sent' => $msg['sent'],
            'id' => $msg['id']
        );
		$this->_conn->modify('
		    UPDATE notifications
		    SET
		        title = :title,
		        body = :body,
		        sent = :sent
            WHERE id = :id
        ', $params);
	} // updateNotification

	/*
	 * Verwijder een filter en de children toe (recursive)
	 */
	function deleteFilter($userId, $filterId, $filterType) {
		$filterList = $this->getFilterList($userId, $filterType);
		foreach($filterList as $filter) {
		
			if ($filter['id'] == $filterId) {
				foreach($filter['children'] as $child) {
					$this->deleteFilter($userId, $child['id'], $filterType);
				} # foreach
			} # if
			
			$this->_conn->modify("DELETE FROM filters WHERE userid = %d AND id = %d", 
					Array($userId, $filterId));
		} # foreach
	} # deleteFilter
	
	/*
	 * Voegt een filter en de children toe (recursive)
	 */
	function addFilter($userId, $filter) {
        $params = Array('userid' => (int)$userId,
                        'filtertype'=> $filter['filtertype'],
                        'title'     => $filter['title'],
                        'icon'      => $filter['icon'],
                        'torder'    => $filter['torder'],
                        'tparent'   => $filter['tparent'],
                        'tree'      => $filter['tree'],
                        'valuelist' => implode('&', $filter['valuelist']),
                        'sorton'    => $filter['sorton'],
                        'sortorder' => $filter['sortorder']);
		$this->_conn->modify("INSERT INTO filters(userid, filtertype, title, icon, torder, tparent, tree, valuelist, sorton, sortorder)
								VALUES(:userid, :filtertype, :title, :icon, :torder, :tparent, :tree, :valuelist, :sorton, :sortorder)",$params);

		$parentId = $this->_conn->lastInsertId('filters');

		foreach($filter['children'] as $tmpFilter) {
			$tmpFilter['tparent'] = $parentId;
			$this->addFilter($userId, $tmpFilter);
		} # foreach
	} # addFilter
	
	/*
	 * Copieert de filterlijst van een user naar een andere user
	 */
	function copyFilterList($srcId, $dstId) {
		$filterList = $this->getFilterList($srcId, '');
		
		foreach($filterList as $filterItems) {
			$this->addFilter($dstId, $filterItems);
		} # foreach
	} # copyFilterList
	
	/*
	 * Verwijdert alle ingestelde filters voor een user
	 */
	function removeAllFilters($userId) {
		$this->_conn->modify("DELETE FROM filters WHERE userid = :userid", Array('userid' => (int) $userId));
	} # removeAllfilters

	/*
	 * Get a specific filter
	 */
	function getFilter($userId, $filterId) {
		/* Haal de lijst met filter values op */
		$tmpResult = $this->_conn->arrayQuery("SELECT id,
													  userid,
													  filtertype,
													  title,
													  icon,
													  torder,
													  tparent,
													  tree,
													  valuelist,
													  sorton,
													  sortorder 
												FROM filters 
												WHERE userid = :userid AND id = :id",
					Array('userid' => (int) $userId, 'id' => (int) $filterId));
		if (!empty($tmpResult)) {
			return $tmpResult[0];
		} else {
			return false;
		} # else
	} # getFilter

	/*
	 * Get a specific index filter 
	 */
	function getUserIndexFilter($userId) {
		/* Haal de lijst met filter values op */
		$tmpResult = $this->_conn->arrayQuery("SELECT id,
													  userid,
													  filtertype,
													  title,
													  icon,
													  torder,
													  tparent,
													  tree,
													  valuelist,
													  sorton,
													  sortorder 
												FROM filters 
												WHERE userid = :userid AND filtertype = 'index_filter'",
					Array('userid' => (int) $userId));
		if (!empty($tmpResult)) {
			return $tmpResult[0];
		} else {
			return false;
		} # else
	} # getUserIndexFilter
	
	
	/*
	 * Get a specific filter
	 */
	function updateFilter($userId, $filter) {
		/* Haal de lijst met filter values op */
		$tmpResult = $this->_conn->modify("UPDATE filters 
												SET title = :title,
												    icon = :icon,
													torder = :torder,
													tparent = :tparent
												WHERE userid = :userid AND id = :id",
					Array('title'   => $filter['title'],
						  'icon'    => $filter['icon'],
						  'torder'  => (int) $filter['torder'],
						  'tparent' => (int) $filter['tparent'],
						  'userid'  => (int) $userId,
						  'id'      => (int) $filter['id']));
	} # updateFilter

	/* 
	 * Haalt de filterlijst op als een platte lijst
	 */
	function getPlainFilterList($userId, $filterType) {
		/* willen we een specifiek soort filter hebben? */
		if (empty($filterType)) {
			$filterTypeFilter = '';
		} else {
			$filterTypeFilter = " AND filtertype = 'filter'"; 
		} # else
		
		/* Haal de lijst met filter values op */
		return $this->_conn->arrayQuery("SELECT id,
											  userid,
											  filtertype,
											  title,
											  icon,
											  torder,
											  tparent,
											  tree,
											  valuelist,
											  sorton,
											  sortorder 
										FROM filters 
										WHERE userid = :userid " . $filterTypeFilter . "
										ORDER BY tparent,torder", /* was: id, tparent, torder */
				Array('userid'=>$userId));
	} # getPlainFilterList
	
	/*
	 * Haalt de filter lijst op en formatteert die in een boom
	 */
	function getFilterList($userId, $filterType) {
		$tmpResult = $this->getPlainFilterList($userId, $filterType);
		$idMapping = array();
		foreach($tmpResult as &$tmp) {
			$idMapping[$tmp['id']] =& $tmp;
		} # foreach
		
		/* Hier zetten we het om naar een daadwerkelijke boom */
		$tree = array();
		foreach($tmpResult as &$filter) {
			if (!isset($filter['children'])) {
				$filter['children'] = array();
			} # if
			
			# de filter waardes zijn URL encoded opgeslagen 
			# en we gebruiken de & om individuele filterwaardes
			# te onderscheiden
			$filter['valuelist'] = explode('&', $filter['valuelist']);
			
			if ($filter['tparent'] == 0) {
				$tree[$filter['id']] =& $filter;
			} else {
				$idMapping[$filter['tparent']]['children'][] =& $filter;
			} # else
		} # foreach

		return $tree;
	} # getFilterList

	function beginTransaction() {
		$this->_conn->beginTransaction();
	} # beginTransaction

	function abortTransaction() {
		$this->_conn->rollback();
	} # abortTransaction

	function commitTransaction() {
		$this->_conn->commit();
	} # commitTransaction

	function safe($q) {
		return $this->_conn->safe($q);
	} # safe

} # class db