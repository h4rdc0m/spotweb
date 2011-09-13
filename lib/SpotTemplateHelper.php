<?php
# Utility class voor template functies, kan eventueel 
# door custom templates extended worden
class SpotTemplateHelper {	
	protected $_settings;
	protected $_db;
	protected $_spotnzb;
	protected $_spotsOverview;
	protected $_currentSession;
	protected $_params;
	protected $_nzbhandler;
	protected $_spotSec;
	
	function __construct(SpotSettings $settings, $currentSession, SpotDb $db, $params) {
		$this->_settings = $settings;
		$this->_currentSession = $currentSession;
		$this->_spotSec = $currentSession['security'];
		$this->_db = $db;
		$this->_params = $params;
		
		# We initialiseren hier een SpotNzb object omdat we die
		# voor het maken van de sabnzbd categorieen nodig hebben.
		# Door die hier aan te maken verplaatsen we een boel allocaties
		$this->_spotnzb = new SpotNzb($db, $settings);

		# We hebben SpotsOverview altijd nodig omdat we die ook voor het
		# maken van de sorturl nodig hebben, dus maken we deze hier aan
		$this->_spotsOverview = new SpotsOverview($db, $settings);

		# We initialiseren hier een NzbHandler object om te voorkomen
		# dat we voor iedere spot een nieuw object initialiseren, een property
		# zou mooier zijn, maar daar is PHP dan weer te traag voor
		$nzbHandlerFactory = new NzbHandler_Factory();
		$this->_nzbHandler = $nzbHandlerFactory->build($settings, 
					$currentSession['user']['prefs']['nzbhandling']['action'], 
					$currentSession['user']['prefs']['nzbhandling']);
	} # ctor

	/*
	 * Geef het aantal spots terug
	 */
	function getSpotCount($sqlFilter) {
		# Controleer de users' rechten
		if ($this->_spotSec->allowed(SpotSecurity::spotsec_view_spotcount_total, '')) {
			return $this->_db->getSpotCount($sqlFilter);
		} else {
			return 0;
		} # else
	} # getSpotCount

	/*
	 * Set params - update de gehele lijst van parameters
	 */
	function setParams($params) {
		$this->_params = $params;
	} # setParams
	
	/* 
	 * Geeft de waarde van een parameter terug
	 */
	function getParam($name) {
		if (isset($this->_params[$name])) {
			return $this->_params[$name];
		} else {
			return NULL;
		} # if
	} # getParam
	
	/*
 	 * Geef het aantal spots terug maar dan rekening houdende met het filter
 	 */
	function getFilteredSpotCount($filterStr) {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_view_spotcount_filtered, '')) {
			return 0;
		} # else

		parse_str(html_entity_decode($filterStr), $query_params);
		
		$parsedSearch = $this->_spotsOverview->filterToQuery($query_params['search'], array(), $this->_currentSession, array());

		return $this->getSpotCount($parsedSearch['filter']);
	} # getFilteredSpotCount

	/*
	 * Geef het aantal spots terug, maar enkel die new zijn
	 */
	function getNewCountForFilter($filterStr) {
		static $skipNewCount = null;
		if ($skipNewCount) {
			return '';
		} # if

		$filterStr .= "&search[value][]=New:=:0";
		$newCount = $this->getFilteredSpotCount($filterStr);

		# en geef het aantal terug dat we willen hebben. Inclusief extragratis
		# lelijke hack om er voor te zorgen dat als er erg veel nieuwe spots
		# zijn, SpotWeb niet ontzettend traag wordt. 
		if ($newCount > 500) {
			$skipNewCount = true;
			return '';
		} elseif ($newCount > 0) {
			return $newCount;
		} else {
			return '';
		} # else
	} # getNewCountForFilter

	/*
	 * Geeft een aantal comments terug
	 */
	function getSpotComments($msgId, $start, $length) {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_view_comments, '');

		$spotnntp = new SpotNntp($this->_settings->get('nntp_hdr'));
		
		return $this->_spotsOverview->getSpotComments($msgId, $spotnntp, $start, $length);
	} # getSpotComments

	/* 
	 * Geeft terug of een bepaalde actie toegestaan is of niet
	 */
	function allowed($perm, $object) {
		return $this->_spotSec->allowed($perm, $object);
	} # allowed
	
	/*
	 * Geeft een full spot terug
	 */
	function getFullSpot($msgId, $markAsRead) {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_view_spotdetail, '');
		
		$spotnntp = new SpotNntp($this->_settings->get('nntp_hdr'));
		
		$fullSpot = $this->_spotsOverview->getFullSpot($msgId, $this->_currentSession['user']['userid'], $spotnntp);

		# seen list
		if ($markAsRead) {
			if ($this->_spotSec->allowed(SpotSecurity::spotsec_keep_own_seenlist, '')) {
				if ($this->_currentSession['user']['prefs']['keep_seenlist']) {
					if ($fullSpot['seenstamp'] == NULL) {
						$this->_db->addToSpotStateList(SpotDb::spotstate_Seen, 
													$msgId, 
													$this->_currentSession['user']['userid']);
					} # if
				} # if
				
			} # if allowed
		} # if
		
		return $fullSpot;
	} # getFullSpot

	/*
	 * Creeert een URL naar de zoekmachine zoals gedefinieerd in de settings
	 */
	function makeSearchUrl($spot) {
		$searchString = (empty($spot['filename'])) ? $spot['title'] : $spot['filename'];
		
		switch ($this->_currentSession['user']['prefs']['nzb_search_engine']) {
			case 'nzbindex'	: return 'http://nzbindex.nl/search/?q=' . $searchString; break;
			case 'binsearch':
			default			: return 'http://www.binsearch.info/?adv_age=&amp;q=' . $searchString;
		} # switch
	} # makeSearchUrl
	
	/*
	 * Geef het volledige URL of path naar Spotweb terug
	 */
	function makeBaseUrl($type) {
		switch ($type) {
			case 'path'	: return parse_url($this->_settings->get('spotweburl'), PHP_URL_PATH); break;
			default		: return $this->_settings->get('spotweburl');
		} # switch
	} # makeBaseurl

	/*
	 * Creeert een linkje naar de sabnzbd API zoals gedefinieerd in de 
	 * settings
	 */
	function makeSabnzbdUrl($spot) {
		$nzbHandling = $this->_currentSession['user']['prefs']['nzbhandling'];
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_download_integration, $nzbHandling['action'])) {
			return '';
		} # if
		
		return $this->_nzbHandler->generateNzbHandlerUrl($spot, $this->makeApiRequestString());
	} # makeSabnzbdUrl

	/*
	 * Creeert een linkje naar een specifieke spot
	 */
	function makeSpotUrl($spot) {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_view_spotdetail, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=getspot&amp;messageid=" . urlencode($spot['messageid']); 
	} # makeSpotUrl

	/*
	 * Creeert de action url voor het aanmaken van de user
	 */
	function makeCreateUserAction() {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_create_new_user, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=createuser";
	} # makeCreateUserAction
	
	/*
	 * Creeert de action url voor het beweken van een security group 
	 */
	function makeEditSecGroupAction() {
		return $this->makeBaseUrl("path") . "?page=editsecgroup";
	} # makeEditSecGroupAction

	/*
	 * Creeert de action url voor het wijzigen van een filter
	 */
	function makeEditFilterAction() {
		return $this->makeBaseUrl("path") . "?page=editfilter";
	} # makeEditFilterAction

	/*
	 * Creeert de action url voor het wissen van een filter
	 */
	function makeDeleteFilterAction() {
		return $this->makeBaseUrl("path") . "?page=editfilter";
	} # makeDeleteFilterAction

	/*
	 * Creeert de action url voor het wijzigen van de user (gebruikt in form post actions)
	 */
	function makeEditUserAction() {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_edit_own_user, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=edituser";
	} # makeEditUserAction
	
	/*
	 * Creeert de action url voor het wijzigen van de users' preferences (gebruikt in form post actions)
	 */
	function makeEditUserPrefsAction() {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_edit_own_userprefs, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=edituserprefs";
	} # makeEditUserPrefsAction
	
	/*
	 * Creeert de url voor het bewerken van een bestaande user
	 */
	function makeEditUserUrl($userid, $action) {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_edit_own_user, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=edituser&amp;userid=" . ((int) $userid) . '&amp;action=' . $action;
	} # makeEditUserUrl

	/*
	 * Creeert de action url voor het inloggen van een user
	 */
	function makeLoginAction() {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_perform_login, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=login";
	} # makeLoginAction

	/*
	 * Creeert de action url voor het inloggen van een user
	 */
	function makePostCommentAction() {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_post_comment, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("path") . "?page=postcomment";
	} # makePostCommentAction
	
	/*
	 * Creeert een linkje naar een specifieke nzb
	 */
	function makeNzbUrl($spot) {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_retrieve_nzb, '')) {
			return '';
		} # if
		
		return $this->makeBaseUrl("full") . '?page=getnzb&amp;action=display&amp;messageid=' . urlencode($spot['messageid']) . $this->makeApiRequestString();
	} # makeNzbUrl

	/*
	 * Creeert een linkje naar retrieve.php
	 */
	function makeRetrieveUrl() {
		# Controleer de users' rechten
		if ((!$this->_spotSec->allowed(SpotSecurity::spotsec_retrieve_spots, '')) || (!$this->_spotSec->allowed(SpotSecurity::spotsec_consume_api, ''))) {
			return '';
		} # if
		
		return $this->makeBaseUrl("full") . 'retrieve.php?output=xml' . $this->makeApiRequestString();
	} # makeRetrieveUrl

	/*
	 * Geef het pad op naar de image
	 */
	function makeImageUrl($spot, $height, $width) {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_view_spotimage, '')) {
			return '';
		} # if
		
		# Volgens issue 941 wil men soms vanuit de RSS of Newznab feed rechtstreeks
		# images kunnen laden. We checken of het 'getimage' recht rechtstreeks via de
		# API aan te roepen is, en zo ja, creeren we API urls.
		$apiKey = '';
		if ($this->_spotSec->allowed(SpotSecurity::spotsec_consume_api, 'getimage')) {
			$apiKey = $this->makeApiRequestString();
		} # if
		
		return $this->makeBaseUrl("path") . '?page=getimage&amp;messageid=' . urlencode($spot['messageid']) . '&amp;image[height]=' . $height . '&amp;image[width]=' . $width . $apiKey;
	} # makeImageUrl

	/*
	 * Creert een sorteer url
	 */
	function makeSortUrl($page, $sortby, $sortdir) {
		return $this->makeBaseUrl("path") . '?page=' . $page . $this->convertFilterToQueryParams() . '&amp;sortby=' . $sortby . '&amp;sortdir=' . $sortdir;
	} # makeSortUrl
	
	/*
	 * Creert een category url
	 */
	function makeCatUrl($spot) {
		# subcata mag altijd maar 1 category hebben, dus exploden we niet
		$catSpot = substr($spot['subcata'], 0, -1);
		return $this->makeBaseUrl("path") . '?search[tree]=cat' . $spot['category'] . '_' . $catSpot . '&amp;sortby=stamp&amp;sortdir=DESC';
	} # makeCatUrl

	/*
	 * Creert een subcategory url
	 */
	function makeSubCatUrl($spot, $cat) {
		$catSpot = explode("|", $cat);
		return $this->makeBaseUrl("path") . '?search[tree]=cat' . $spot['category'] . '_' . $catSpot[0] . '&amp;sortby=stamp&amp;sortdir=DESC';
	} # makeSubCatUrl

	/*
	 * Creert een Poster url
	 */
	function makePosterUrl($spot) {
		return $this->makeBaseUrl("path") . '?search[tree]=&amp;search[value][]=Poster:=:' . urlencode($spot['poster']) . '&amp;sortby=stamp&amp;sortdir=DESC';
	} # makePosterUrl

	/*
	 * Creeert een linkje naar een zoekopdracht op userid
	 */
	function makeUserIdUrl($spot) {
		return $this->makeBaseUrl("path") . '?search[tree]=&amp;search[value][]=UserID:=:' . urlencode($spot['userid']) . '&amp;sortby=stamp&amp;sortdir=DESC';
	} # makeUserIdUrl

	/*
	 * Creeert een linkje naar een zoekopdracht op tag
	 */
	function makeTagUrl($spot) {
		return $this->makeBaseUrl("path") . '?search[tree]=&amp;search[value][]=Tag:=:' . urlencode($spot['tag']);
	} # makeUserIdUrl

	/*
	 * Creeert een request string met username en apikey als deze zijn opgegeven
	 */
	function makeApiRequestString() {
		# Controleer de users' rechten
		if (!$this->_spotSec->allowed(SpotSecurity::spotsec_consume_api, '')) {
			return '';
		} # if

		if ($this->_currentSession['user']['userid'] > SPOTWEB_ADMIN_USERID) {
			return '&amp;apikey=' . $this->_currentSession['user']['apikey'];
		} else {
			return '';
		} # else
	} # makeApiRequestString

	/* 
	 * Creert een RSS url
	 */
	function makeRssUrl() {
		if (isset($this->_params['parsedsearch'])) {
			return $this->makeBaseUrl("path") . '?page=rss&amp;' . $this->convertFilterToQueryParams() . '&amp;' . $this->convertSortToQueryParams();
		} else {
			return '';
		} # if
	} # makeRssUrl
	
	/*
	 * Creert een basis navigatie pagina
	 */
	function getPageUrl($page) {
		return $this->makeBaseUrl("path") . '?page=' . $page;
	} # getPageUrl
	
	/*
	 * Geeft het linkje terug naar ons zelf
	 */
	function makeSelfUrl($type) {
		return $this->makeBaseUrl($type) . htmlspecialchars((isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ""));
	} # makeSelfUrl
	
	# Function from http://www.php.net/manual/en/function.filesize.php#99333
	function format_size($size) {
		$sizes = array(" Bytes", " KB", " MB", " GB", " TB", " PB", " EB", " ZB", " YB");
		if ($size == 0) { 
			return('n/a'); 
		} else {
			return (round($size/pow(1024, ($i = floor(log($size, 1024)))), $i > 1 ? 2 : 0) . $sizes[$i]); 

			// test (n.a.v. http://gathering.tweakers.net/forum/list_message/36208481#36208481) om altijd op 
			// 3 getallen te eindigen, maar maakt het niet rustiger.
			//
			//		$roundedSize = round($size/pow(1024, ($i = floor(log($size, 1024)))),99);
			//		return number_format($roundedSize, 3 - strlen(round($roundedSize))) . $sizes[$i];
		} # else
	} # format_size

	
	function formatContent($tmp) {
		# escape alle embedded HTML, maar eerst zetten we de spot inhoud om naar 
		# volledige HTML, dit doen we omdat er soms embedded entities (&#237; e.d.) 
		# in zitten welke we wel willen behouden.
		$tmp = htmlentities($tmp);
		$tmp = html_entity_decode($tmp, ENT_COMPAT, 'UTF-8');
		
		# Code gecopieerd vanaf 
		#		http://stackoverflow.com/questions/635844/php-how-to-grab-an-url-out-of-a-chunk-of-text
		# converteert linkjes naar bb code
		$pattern = "((https?|ftp|gopher|telnet|file|notes|ms-help):((//)|(\\\\))+[\w\d:#@%/;$()~_?\+-=\\\.&]*)";
		$tmp = preg_replace($pattern, '[url=\0]\0[/url]', $tmp);
		
		# initialize ubb parser
		$parser = new SpotUbb_parser($tmp);
		TagHandler::setDeniedTags( Array() );
		TagHandler::setadditionalinfo('img', 'allowedimgs', $this->getSmileyList() );
        $tmp = $parser->parse();
		$tmp = $tmp[0];
	
		# en replace eventuele misvormde br tags
		$tmp = str_ireplace('&lt;br&gt;', '<br />', $tmp);
		$tmp = str_ireplace('&lt;br /&gt;', '<br />', $tmp);
		$tmp = str_ireplace('&amp;lt;br />', '<br />', $tmp);

		return $tmp;
	} # formatContent

	/*
	 * Geeft de huidige lijst met categoryselectie terug
	 * als een comma seperated lijst voor de dynatree initialisatie
	 */
	function categoryListToDynatree() {
		return $this->_spotsOverview->compressCategorySelection($this->_params['parsedsearch']['categoryList'], $this->_params['parsedsearch']['strongNotList']);
	} # categoryListToDynatree
	
	/*
	 * Converteert de aanwezige filters naar een nieuwe, eventueel
	 * geoptimaliserde GET (query) parameters 
	 */
	function convertFilterToQueryParams() {
		#var_dump($this->_params['parsedsearch']['categoryList']);
		#var_dump($this->_params['parsedsearch']['strongNotList']);
		#var_dump($this->_params['parsedsearch']['filterValueList']);
		#var_dump($this->_params['parsedsearch']['sortFields']);

		
		//$xml = $this->_spotsOverview->parsedSearchToXml($this->_params['parsedsearch']);
		//$parsed = $this->_spotsOverview->xmlToParsedSearch($xml, $this->_currentSession);
		//var_dump($parsed);
		//die();
		
		return $this->convertUnfilteredToQueryParams() . $this->convertTreeFilterToQueryParams() . $this->convertTextFilterToQueryParams();
	} # convertFilterToQueryParams

	/*
	 * Converteer de huidige unfiltered setting
	 * naar een nieuwe GET query
	 */
	function convertUnfilteredToQueryParams() {
		# en eventueel als de huidige list unfiltered is, geef
		# dat ook mee
		$unfilteredStr = '';
		if ($this->_params['parsedsearch']['unfiltered']) {
			$unfilteredStr = '&amp;search[unfiltered]=true';
		} # if

		return $unfilteredStr;
	} # convertUnfilteredToQueryParams()
	
	/*
	 * Converteert de aanwezige filter boom naar een
	 * nieuwe GET query
	 */
	function convertTreeFilterToQueryParams() {
		# Bouwen de search[tree] value op
		return '&amp;search[tree]=' . $this->_spotsOverview->compressCategorySelection($this->_params['parsedsearch']['categoryList'],
														$this->_params['parsedsearch']['strongNotList']);
	} # convertTreeFilterToQueryParams

	/*
	 * Converteert de aanwezige filter velden (behalve de boom)
	 * naar een nieuwe GET query
	 */
	function convertTextFilterToQueryParams() {
		# Vervolgens bouwen we de filtervalues op
		$filterStr = '';
		foreach($this->_params['parsedsearch']['filterValueList'] as $value) {
			$filterStr .= '&amp;search[value][]=' . $value['fieldname'] . ':' . $value['operator'] . ':' . htmlspecialchars($value['value'], ENT_QUOTES, "utf-8");
		} # foreach

		return $filterStr;
	} # convertTextFilterToQueryParams

	/*
	 * Geeft de huidige actieve sortering terug
	 */
	function getActiveSorting() {
		$activeSort = array('field' => '',
							'direction' => '',
							'friendlyname' => '');
		
		# als we niet aan het sorteren zijn, doen we niets
		if (!isset($this->_params['parsedsearch'])) {
			return $activeSort;
		} # if
		
		# we voegen alleen sorteringen toe die ook door
		# de gebruiker expliciet zijn toegevoegd
		foreach($this->_params['parsedsearch']['sortFields'] as $value) {
			if (!$value['autoadded']) {
				$activeSort['field'] = $value['field'];
				$activeSort['direction'] = $value['direction'];
				$activeSort['friendlyname'] = $value['friendlyname'];
				break;
			} # if
		} # foreach
		
		return $activeSort;
	} # getActiveSorting
	
	/*
	 * Converteert de huidige actieve sorteer parameters
	 * naar GET parameters voor in de URL
	 */
	function convertSortToQueryParams() {
		$sortStr = '';
		$activeSort = $this->getActiveSorting();
		
		if (!empty($activeSort['field'])) {
			return '&amp;sortby=' . $activeSort['friendlyname'] . '&amp;sortdir=' . $activeSort['direction'];
		} # if
		
		return '';
	} # convertSortToQueryParams

	/* 
	 * Safely escape de velden en vul wat velden in
	 */
	function formatSpotHeader($spot) {
	/*
		$spot['sabnzbdurl'] = '';
		$spot['searchurl'] = '';
		$spot['spoturl'] = '';
		$spot['caturl'] = '';
		$spot['subcaturl'] = '';
		$spot['posterurl'] = '';
		$spot['title'] = '';
		$spot['poster'] = '';
		$spot['catshortdesc'] = '';
		$spot['catdesc'] = '';
		$spot['hasbeendownloaded'] = ($spot['downloadstamp'] != NULL);
		$spot['isbeingwatched'] = ($spot['watchstamp'] != NULL);
		return $spot;
	*/
		
		# fix the sabnzbdurl, searchurl, sporturl, subcaturl, posterurl
		$spot['sabnzbdurl'] = $this->makeSabnzbdUrl($spot);
		$spot['searchurl'] = $this->makeSearchUrl($spot);
		$spot['spoturl'] = $this->makeSpotUrl($spot);
		$spot['caturl'] = $this->makeCatUrl($spot);
		$spot['subcaturl'] = $this->makeSubCatUrl($spot, $spot['subcat' . SpotCategories::SubcatNumberFromHeadcat($spot['category'])]);
		$spot['posterurl'] = $this->makePosterUrl($spot);

		// title escapen
		$spot['title'] = htmlspecialchars(strip_tags($this->remove_extensive_dots($spot['title'])), ENT_QUOTES, 'UTF-8');
		$spot['poster'] = htmlspecialchars(strip_tags($spot['poster']), ENT_QUOTES, 'UTF-8');
		
		// we zetten de short description van de category bij
		$spot['catshortdesc'] = SpotCategories::Cat2ShortDesc($spot['category'], $spot['subcata']);
		$spot['catdesc'] = SpotCategories::Cat2Desc($spot['category'], $spot['subcat' . SpotCategories::SubcatNumberFromHeadcat($spot['category'])]);
		
		// commentcount en rating altijd teruggeven
		$spot['commentcount'] = (int) $spot['commentcount'];
		$spot['rating'] = (int) $spot['rating'];
		
		// is deze spot al eens gedownload?
		$spot['hasbeendownloaded'] = ($spot['downloadstamp'] != NULL);
		
		// zit deze spot in de watchlist?
		$spot['isbeingwatched'] = ($spot['watchstamp'] != NULL);
		
		return $spot;
	} # formatSpotHeader

	/*
	 * Formatteert (maakt op) een lijst van comments
	 */
	function formatComments($comments) {
		// escape de HTML voor de comments
		$commentCount = count($comments);
		for($i = 0; $i < $commentCount; $i++ ){
			$comments[$i]['body'] = array_map('strip_tags', $comments[$i]['body']);
			$comments[$i]['fromhdr'] = htmlentities($comments[$i]['fromhdr'], ENT_NOQUOTES, 'UTF-8');
			
			# we joinen eerst de contents zodat we het kunnen parsen als 1 string
			# en tags over meerdere lijnen toch nog werkt. We voegen een extra \n toe
			# om zeker te zijn dat we altijd een array terugkrijgen
			$tmpBody = implode("\n", $comments[$i]['body']);
			$tmpBody = $this->formatContent($tmpBody);
			$comments[$i]['body'] = explode("\n", $tmpBody);
		} # for
		
		return $comments;
	} # formatComments
	
	/*
	 * Omdat we geen zin hebben elke variabele te controleren of hij bestaat,
	 * vullen we een aantal defaults in.
	 */
	function formatSpot($spot) {
		# formatteer de spot
		$spot = $this->formatSpotHeader($spot);
		
		// Category is altijd een integer bij ons
		$spot['category'] = (int) $spot['category'];
		
		// Geen website? Dan standaard naar de zoekmachine
		if (empty($spot['website'])) {
			$spot['website'] = $this->makeSearchUrl($spot);
		} # if
		
		// geef de category een fatsoenlijke naam
		$spot['catname'] = SpotCategories::HeadCat2Desc($spot['category']);
		$spot['formatname'] = SpotCategories::Cat2ShortDesc($spot['category'], $spot['subcata']);
		
		// properly escape several  urls
		if (!is_array($spot['image'])) {
			$spot['image'] = htmlspecialchars($spot['image']);
		} else {
			$spot['image'] = '';
		} # else
		$spot['website'] = htmlspecialchars($spot['website']);
		$spot['tag'] = htmlspecialchars(strip_tags($spot['tag']), ENT_QUOTES, 'UTF-8');
		
		// description
		$spot['description'] = $this->formatContent($spot['description']);
				
		return $spot;
	} # formatSpot

	function isSpotNew($spot) {
		if ($this->_currentSession['user']['prefs']['auto_markasread']) {
			return ( max($this->_currentSession['user']['lastvisit'],$this->_currentSession['user']['lastread']) < $spot['stamp'] && $spot['seenstamp'] == NULL);
		} else {
			return ($this->_currentSession['user']['lastread'] < $spot['stamp'] && $spot['seenstamp'] == NULL);
		} # else
	} # isSpotNew
	
	#
	# Copied from:
	# 	http://www.mdj.us/web-development/php-programming/another-variation-on-the-time-ago-php-function-use-mysqls-datetime-field-type/
	# DISPLAYS COMMENT POST TIME AS "1 year, 1 week ago" or "5 minutes, 7 seconds ago", etc...	
	function time_ago($date, $granularity=2) {
		$difference = time() - $date;
		$periods = array(0 => 315360000,
			1 => 31536000,
			2 => 2628000,
			3 => 604800, 
			4 => 86400,
			5 => 3600,
			6 => 60,
			7 => 1);
		$names_singular = array('eeuw', 'jaar', 'maand', 'week', 'dag', 'uur', 'minuut', 'seconde');
		$names_plural = array('eeuwen', 'jaar', 'maanden', 'weken', 'dagen', 'uur', 'minuten', 'seconden');
			
		$retval = '';
		foreach ($periods as $key => $value) {
			if ($difference >= $value) {
				$time = floor($difference/$value);
				$difference %= $value;
				$retval .= ($retval ? ' ' : '').$time.' ';
				
				if ($time > 1) {
					$retval .= $names_plural[$key];
				} else {
					$retval .= $names_singular[$key];
				} # if
				$retval .= ', ';
				$granularity--;
			}
			
			if ($granularity == '0') { break; }
		}
		return substr($retval, 0, -2);
	} # time_ago()


	function formatDate($stamp, $type) {
		if ($this->_currentSession['user']['prefs']['date_formatting'] == 'human') {
			return $this->time_ago($stamp);
		} else {
			switch($type) {
				case 'comment'		:
				case 'spotlist'		: 
				case 'lastupdate'	: 
				case 'lastvisit'	:
				case 'userlist'		:
				default 			: return strftime($this->_currentSession['user']['prefs']['date_formatting'], $stamp);
			} # switch
		} # else
	} # formatDate
	
	function isModerated($spot) {
		return ($spot['moderated'] != 0);
	} # isModerated

	/*
	 * Geeft een lijst van mogelijke smilies terug
	 */
	function getSmileyList() {
		return array();
	} # getSmileyList
	
	# Functie voor in combinatie met SpotPage_statics.php -
	# deze functie hoort een lijst van onze static files terug te geven die door de SpotPage_statics
	# dan geserved wordt als nooit meer veranderend. 
	function getStaticFiles($type) {
		return array();
	} # getStaticFiles

	# Functie voor in combinatie met SpotPage_statics.php -
	# deze functie kijkt wat de laatste timetsamp is van de file en kan gebruikt worden in de templates.
	# Omdat stat() behoorlijk traag is, is het voor betere performance aan te raden handmatig je versie nummer
	# op te hogen in je template en deze functie niet te gebruiken
	function getStaticModTime($type) {
		$fileTime = 0;
		$fileList = $this->getStaticFiles($type);
		
		foreach($fileList as $file) {
			$thisftime = filemtime($file);
			
			if ($thisftime > $fileTime) {
				$fileTime = $thisftime;
			} # if
		} # foreach
		
		return $fileTime;
	} # getStaticFiles

	function remove_extensive_dots($s) {
		if (substr_count($s,  '.') > 3) {
			$s = str_replace('.', ' ', $s);
		} # if
		return $s;
	} # remove_extensive_dots
	
	/*
	 * Creeer een anti-XSRF cookie
	 */
	function generateXsrfCookie($action) {
		return SpotReq::generateXsrfCookie($action);
	} # generateXsrfCookie

	/*
	 * API to hash
	 */
	function apiToHash($api) {
		return sha1(strrev(substr($this->_settings->get('pass_salt'), 1, 3)) . $api . $this->_settings->get('pass_salt'));
	} # apiToHash 
	
	/*
	 * Converteert een message string uit Spotweb naar een toonbare tekst
	 */
	function formMessageToString($message) {
		$strings = array();
		$strings['validateuser_mailalreadyexist'] = 'Mailadres is al in gebruik';
		$strings['validateuser_invalidmail'] = 'Geen geldig mailadres';
		$strings['validateuser_invalidfirstname'] = 'Geen geldige voornaam';
		$strings['validateuser_invalidlastname'] = 'Geen geldige achternaam';
		$strings['validateuser_invalidusername'] = 'Geen geldige gebruikersnaam';
		$strings['validateuser_usernameexists'] = "'%s' bestaat al";
		$strings['validateuser_passwordtooshort'] = 'Opgegeven wachtwoord is te kort';
		$strings['validateuser_passworddontmatch'] = 'Wachtwoord velden komen niet overeen';
		$strings['validateuser_invalidpreference'] = 'Ongeldige user preference waarde (%s)';
		
		$strings['edituser_usernotfound'] = 'User kan niet gevonden worden';
		$strings['edituser_cannoteditanonymous'] = 'Anonymous user kan niet bewerkt worden';
		$strings['edituser_cannotremovesystemuser'] = 'admin en anonymous user kunnen niet verwijderd worden';
		$strings['edituser_usermusthaveonegroup'] = 'Een gebruiker moet in minstens een groep zitten';

		$strings['postcomment_invalidhashcash'] = 'Hash is niet goed berekend, ongeldige post';
		$strings['postcomment_bodytooshort'] = 'Geef een reactie';
		$strings['postcomment_ratinginvalid'] = 'Gegeven rating is niet geldig';
		$strings['postcomment_replayattack'] = 'Replay attack';
		
		$strings['validatesecgroup_invalidname'] = 'Ongeldige naam voor de groep';
		$strings['validatesecgroup_duplicatename'] = 'Deze naam voor de groep is al in gebruik';
		$strings['validatesecgroup_duplicatepermission'] = 'Permissie bestaat al in deze groep';
		$strings['validatesecgroup_groupdoesnotexist'] = 'Groep bestaat niet';
		$strings['validatesecgroup_cannoteditbuiltin'] = 'Ingebouwde groepen mogen niet bewerkt worden';
		
		$strings['validatefilter_filterdoesnotexist'] = 'Filter bestaat niet';
		$strings['validatefilter_invalidtitle'] = 'Ongeldige naam voor een filter';
		$strings['validatefilter_nofileupload'] = 'Filter is niet geupload';
		$strings['validatefilter_fileuploaderr'] = 'Fout tijdens uploaden van filter (%d)';
		$strings['validatefilter_invaliduploadxml'] = 'Geuploade Spotweb filter file is ongeldig';
		
		return vsprintf($strings[$message[0]], $message[1]);
	} # formMessageToString

	/*
	 * Geeft de lijst met users terug
	 */
	function getUserList($username) {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_list_all_users, '');
		
		return $this->_db->listUsers($username, 0, 9999);
	} # getUserList
	
	/*
	 * Wanneer was de spotindex voor het laatst geupdate?
	 */
	function getLastSpotUpdates() {
		# query wanneer de laatste keer de spots geupdate werden
		$nntp_hdr_settings = $this->_settings->get('nntp_hdr');
		return $this->_db->getLastUpdate($nntp_hdr_settings['host']);
	} # getLastSpotUpdates
	
	/*
	 * Leegt de lijst met gedownloade NZB bestanden
	 */
	function clearDownloadList() {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_keep_own_downloadlist, '');
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_keep_own_downloadlist, 'erasedls');
		
		$this->_db->clearSpotStateList(SpotDb::spotstate_Down, $this->_currentSession['user']['userid']);
	} # clearDownloadList
	
	/*
	 * Converteert een permission id naar een string
	 */
	function permToString($perm) {
		return $this->_spotSec->toHuman($perm);
	} # permToString
	
	/*
	 * Geeft alle mogelijke Spotweb permissies terug
	 */
	function getAllAvailablePerms() {
		return $this->_spotSec->getAllPermissions();
	} # getAllAvailablePerms
	
	/*
	 * Genereert een random string
	 */
	function getSessionCalculatedUserId() {
		$spotSigning = new SpotSigning();
		return $spotSigning->calculateUserid($this->_currentSession['user']['publickey']);
	} # getSessionCalculatedUserId
	
	/*
	 * Geeft een lijst met alle security groepen terug
	 */
	function getGroupList() {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_display_groupmembership, '');
		
		return $this->_db->getGroupList(null);
	}  # getGroupList

	/*
 	 * Geeft een lijst met alle security groepen terug voor een bepaalde user
	 */
	function getGroupListForUser($userId) {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_display_groupmembership, '');
		
		return $this->_db->getGroupList($userId);
	}  # getGroupListForUser

	/*
	 * Geeft de users' custom CSS terug 
	 */
	function getUserCustomCss() {
		if (!$this->allowed(SpotSecurity::spotsec_allow_custom_stylesheet, '')) {
			return '';
		} # if
		
		return $this->_currentSession['user']['prefs']['customcss'];
	} # if 
	
	/*
	 * Geeft alle permissies in een bepaalde securitygroup terug
	 */
	function getSecGroup($groupId) {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_edit_securitygroups, '');
		
		$tmpGroup = $this->_db->getSecurityGroup($groupId);
		if (!empty($tmpGroup)) {
			return $tmpGroup[0];
		} else {
			return false;
		} # else
	} # getSecGroup

	/*
	 * Geeft alle permissies in een bepaalde securitygroup terug
	 */
	function getSecGroupPerms($id) {
		# Controleer de users' rechten
		$this->_spotSec->fatalPermCheck(SpotSecurity::spotsec_edit_securitygroups, '');
		
		return $this->_db->getGroupPerms($id);
	} # getSecGroupPerms
	
	/*
	 * Redirect naar een opgegeven url
	 */
	function redirect($url) {
		Header("Location: " . $url); 
	} # redirect()
	
	/*
	 * Get users' filter list
	 */
	function getUserFilterList() {
		$spotUser = new SpotUserSystem($this->_db, $this->_settings);
		return $spotUser->getFilterList($this->_currentSession['user']['userid'], 'filter');
	} # getUserFilterList

	/*
	 * Get specific filter
	 */
	function getUserFilter($filterId) {
		$spotUser = new SpotUserSystem($this->_db, $this->_settings);
		return $spotUser->getFilter($this->_currentSession['user']['userid'], $filterId);
	} # getUserFilter

	/*
	 * Get index filter
	 */
	function getIndexFilter() {
		$spotUser = new SpotUserSystem($this->_db, $this->_settings);
		return $spotUser->getIndexFilter($this->_currentSession['user']['userid']);
	} # getIndexFilter
	
	/*
	 * Genereert een random string
	 */
	function getCleanRandomString($len) {
		$spotParser = new SpotParser();
		$spotSigning = new SpotSigning();
		return substr($spotParser->specialString(base64_encode($spotSigning->makeRandomStr($len))), 0, $len);
	} # getRandomStr
	
	/*
	 * Geeft de naam van de nzbhandler terug
	 */
	function getNzbHandlerName(){
		return $this->_nzbHandler->getName();
	} # getNzbHandlerName
	
	/*
	 * Geeft een string met gesupporte API functies terug of false wanneer er geen API support is
	 * voor de geselecteerde NzbHandler
	 */
	function getNzbHandlerApiSupport(){
		return $this->_nzbHandler->hasApiSupport();
	} # getNzbHandlerApiSupport
	
} # class SpotTemplateHelper
