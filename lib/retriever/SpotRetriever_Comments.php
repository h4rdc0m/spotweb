<?php
class SpotRetriever_Comments extends SpotRetriever_Abs {
		private $_outputType;

		/**
		 * server - de server waar naar geconnect moet worden
		 * db - database object
		 * rsakeys = array van rsa keys
		 */
		function __construct($server, SpotDb $db, SpotSettings $settings, $outputType) {
			parent::__construct($server, $db, $settings);			
			
			$this->_outputType = $outputType;
		} # ctor
		
		/*
		 * Geef de status weer in category/text formaat. Beide zijn vrij te bepalen
		 */
		function displayStatus($cat, $txt) {
			if ($this->_outputType != 'xml') {
				switch($cat) {
					case 'start'			: echo "Retrieving new comments from server " . $txt . "..." . PHP_EOL; break;
					case 'done'				: echo "Finished retrieving comments." . PHP_EOL . PHP_EOL; break;
					case 'groupmessagecount': echo "Appr. Message count: 	" . $txt . "" . PHP_EOL; break;
					case 'firstmsg'			: echo "First message number:	" . $txt . "" . PHP_EOL; break;
					case 'lastmsg'			: echo "Last message number:	" . $txt . "" . PHP_EOL; break;
					case 'curmsg'			: echo "Current message:	" . $txt . "" . PHP_EOL; break;
					case 'progress'			: echo "Retrieving " . $txt; break;
					case 'loopcount'		: echo ", found " . $txt . " comments" . PHP_EOL; break;
					case 'totalprocessed'	: echo "Processed a total of " . $txt . " comments" . PHP_EOL; break;
					case 'searchmsgid'		: echo "Looking for articlenumber for messageid" . PHP_EOL; break;
					case ''					: echo PHP_EOL; break;
					
					default					: echo $cat . $txt;
				} # switch
			} else {

				switch($cat) {
					case 'start'			: echo "<comments>"; break;
					case 'done'				: echo "</comments>"; break;
					case 'totalprocessed'	: echo "<totalprocessed>" . $txt . "</totalprocessed>"; break;
					default					: break;
				} # switch
			} # xml output
		} # displayStatus

		/*
		 * Wis alle spots welke in de database zitten met een hoger id dan dat wij
		 * opgehaald hebben.
		 */
		function updateLastRetrieved($highestMessageId) {
			$this->_db->removeExtraComments($highestMessageId);
		} # updateLastRetrieved
		
		/*
		 * De daadwerkelijke processing van de headers
		 */
		function process($hdrList, $curMsg, $endMsg) {
			$this->displayStatus("progress", ($curMsg) . " till " . ($endMsg));
		
			$this->_db->beginTransaction();
			$signedCount = 0;
			$lastProcessedId = '';
			
			# pak onze lijst met messageid's, en kijk welke er al in de database zitten
			$dbIdList = $this->_db->matchCommentMessageIds($hdrList);
			
			# we houden een aparte lijst met spot messageids bij zodat we dat extracten
			# niet meer in de db laag moeten doen
			$spotMsgIdList = array();
			
			# en loop door elke header heen
			foreach($hdrList as $msgid => $msgheader) {
				# Reset timelimit
				set_time_limit(120);			

				# strip de reference van de <>'s
				$commentId = substr($msgheader['Message-ID'], 1, strlen($msgheader['Message-ID']) - 2);

				# als we de comment nog niet in de database hebben, haal hem dan op
				if (!isset($dbIdList[$commentId])) {
					# fix de references, niet alle news servers geven die goed door
					$msgIdParts = explode(".", $commentId);
					$msgheader['References'] = $msgIdParts[0] . substr($commentId, strpos($commentId, '@'));
					$spotMsgIdList[] = $msgheader['References'];
					
					# als dit een nieuw soort comment is met rating vul die dan ook op
					if (count($msgIdParts) == 5) {
						$msgheader['rating'] = (int) $msgIdParts[1];
						
						# Sommige oudere comments bevatten een niet-numerieke
						# string op deze positie, dus we controleren nog even
						# of het puur een getal is wat er staat.
						if (!is_numeric($msgIdParts[1])) {
							$msgheader['rating'] = 0;
						} # if
					} else {
						$msgheader['rating'] = 0;
					} # if
					$lastProcessedId = $commentId;

					# voeg spot aan db toe
					$this->_db->addCommentRef($commentId, $msgheader['References'], $msgheader['rating']);

					# we moeten ook de msgid lijst updaten omdat 
					# soms een messageid meerdere keren per xover mee komt
					$dbIdList[$commentId] = 1;
				} # if
			} # foreach

			if (count($hdrList) > 0) {
				$this->displayStatus("loopcount", count($hdrList));
			} else {
				$this->displayStatus("loopcount", 0);
			} # else

			# herbereken de gemiddelde spotrating, en update het 
			# aantal niet geverifieerde comments
			$this->_db->updateSpotRating($spotMsgIdList);
			$this->_db->updateSpotCommentCount($spotMsgIdList);
			
			# update the last retrieved article			
			$this->_db->setMaxArticleid('comments', $curMsg);
			$this->_db->commitTransaction();
			
			return array('count' => count($hdrList), 'headercount' => count($hdrList), 'lastmsgid' => $lastProcessedId);
		} # process()
		
} # class SpotRetriever_Comments
