<?php
abstract class SpotRetriever_Abs {
		protected $_server;
		protected $_spotnntp;
		protected $_db;
		protected $_settings;
		
		private $_msgdata;

		/*
		 * Geef de status weer in category/text formaat. Beide zijn vrij te bepalen
		 */
		abstract function displayStatus($cat, $txt);
		
		/*
		 * De daadwerkelijke processing van de headers
		 */
		abstract function process($hdrList, $curMsg, $increment);
		
		/*
		 * Wis alle spots welke in de database zitten met een hoger id dan dat wij
		 * opgehaald hebben.
		 */
		abstract function updateLastRetrieved($highestMessageId);
		
		/*
		 * NNTP Server waar geconnet moet worden
		 */
		function __construct($server, SpotDb $db, SpotSettings $settings) {
			$this->_server = $server;
			$this->_db = $db;
			$this->_settings = $settings;
		} # ctor
		
		function connect($group) {
			# als er al een retriever instance loopt, stop er dan mee
			if ($this->_db->isRetrieverRunning($this->_server['host'])) {
				throw new RetrieverRunningException();
			} # if
			
			# anders melden we onszelf aan dat we al draaien
			$this->_db->setRetrieverRunning($this->_server['host'], true);

			# zo niet, dan gaan we draaien
			$this->displayStatus("start", $this->_server['host']);
			$this->_spotnntp = new SpotNntp($this->_server);
			$this->_msgdata = $this->_spotnntp->selectGroup($group);
			
			return $this->_msgdata;
		} # connect
		

		/*
		 * Zoekt het juiste articlenummer voor een opgegeven lijst van messageids
		 */
		function searchMessageid($messageIdList) {
			if (empty($messageIdList)) {
				return 0;
			} # if
				
			$this->displayStatus('searchmsgid', '');
			
			$found = false;
			$decrement = 5000;
			$curMsg = $this->_msgdata['last'];

			# en start met zoeken
			while (($curMsg >= $this->_msgdata['first']) && (!$found)) {
				$curMsg = max(($curMsg - $decrement), $this->_msgdata['first'] - 1);

				# get the list of headers (XHDR)
				$hdrList = $this->_spotnntp->getMessageIdList($curMsg - 1, ($curMsg + $decrement));
				
				# we draaien de messageid's lijst om, omdat we willen dat we de meest recente
				# messageid als uitgangspunt nemen
				$hdrList = array_reverse($hdrList, true);

				foreach($hdrList as $msgNum => $msgId) {
					if (isset($messageIdList[$msgId])) {
						$curMsg = $msgNum;
						$found = true;
						break;
					} # if
				} # for
			} # while

			return $curMsg;
		} # searchMessageId
		
		/*
		 * Haal de headers op en zorg dat ze steeds verwerkt worden
		 */
		function loopTillEnd($curMsg, $increment = 1000) {
			$processed = 0;
			$headersProcessed = 0;
			$highestMessageId = '';
			
			# make sure we handle articlenumber wrap arounds
			if ($curMsg < $this->_msgdata['first']) {
				$curMsg = $this->_msgdata['first'];
			} # if

			$this->displayStatus("groupmessagecount", ($this->_msgdata['last'] - $this->_msgdata['first']));
			$this->displayStatus("firstmsg", $this->_msgdata['first']);
			$this->displayStatus("lastmsg", $this->_msgdata['last']);
			$this->displayStatus("curmsg", $curMsg);
			$this->displayStatus("", "");

			while ($curMsg < $this->_msgdata['last']) {
				# get the list of headers (XOVER)
				$hdrList = $this->_spotnntp->getOverview($curMsg, ($curMsg + $increment));
				
				$saveCurMsg = $curMsg;
				# If no spots were found, just manually increase the
				# messagenumber with the increment to make sure we advance
				if ((count($hdrList) < 1) || ($hdrList[count($hdrList)-1]['Number'] < $curMsg)) {
					$curMsg += $increment;
				} else {
					$curMsg = ($hdrList[count($hdrList)-1]['Number'] + 1);
				} # else
				
				# run the processing method
				$processOutput = $this->process($hdrList, $saveCurMsg, $curMsg);
				$processed += $processOutput['count'];
				$headersProcessed += $processOutput['headercount'];
				$highestMessageId = $processOutput['lastmsgid'];

				# reset the start time to prevent a another retriever from starting
				# during the intial retrieve which can take many hours 
				$this->_db->setRetrieverRunning($this->_server['host'], true);
			} # while
			
			# we are done updating, make sure that if the newsserver deleted 
			# earlier retrieved messages, we remove them from our database
			if ($highestMessageId != '') {
				$this->updateLastRetrieved($highestMessageId);
			} # if
	
			$this->displayStatus("totalprocessed", $processed);
			return $headersProcessed;
		} # loopTillEnd()

		function quit() {
			# anders melden we onszelf af dat we al draaien
			$this->_db->setRetrieverRunning($this->_server['host'], false);
			
			# sluit de NNTP connectie af
			$this->_spotnntp->quit();
			$this->displayStatus("done", "");
		} # quit()
} # class SpotRetriever
