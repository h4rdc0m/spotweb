<?php
	require_once "includes/header.inc.php";
	$spot = $tplHelper->formatSpot($spot);
	
	// We definieeren hier een aantal settings zodat we niet steeds dezelfde check hoeven uit te voeren
	$show_nzb_button = ( (!empty($spot['nzb'])) && 
						 ($spot['stamp'] > 1290578400) && 
						 ($tplHelper->allowed(SpotSecurity::spotsec_retrieve_nzb, ''))
						);
	$show_watchlist_button = ($currentSession['user']['prefs']['keep_watchlist'] && $tplHelper->allowed(SpotSecurity::spotsec_keep_own_watchlist, ''));
?>
		<div id="details" class="details <?php echo $tplHelper->cat2color($spot) ?>">
			<table class="spotheader">
				<tbody>
					<tr>
						<th class="back"> <a class="closeDetails" title="Ga terug naar het overzicht (esc / u)">&lt;&lt;</a> </th>
						<th class="category"><span><?php echo $spot['formatname'];?></span></th>
						<th class="title"><?php echo $spot['title'];?></th>
						<th class="rating">
<?php
	if($spot['rating'] == 0) {
		echo '<span class="rating" title="Deze spot heeft nog geen rating"><span style="width:0px;"></span></span>';
	} elseif($spot['rating'] == 1) {
		echo '<span class="rating" title="Deze spot heeft '.$spot['rating'].' ster"><span style="width:' . $spot['rating'] * 4 . 'px;"></span></span>';
	} else {
		echo '<span class="rating" title="Deze spot heeft '.$spot['rating'].' sterren"><span style="width:' . $spot['rating'] * 4 . 'px;"></span></span>';
	}
?>
						</th>
						<th class="nzb">
<?php if ($show_nzb_button) { ?>
							<a class="nzb<?php if ($spot['hasbeendownloaded']) { echo " downloaded"; } ?>" href="<?php echo $tplHelper->makeNzbUrl($spot); ?>" title="Download NZB <?php if ($spot['hasbeendownloaded']) {echo '(deze spot is al gedownload)';} echo " (n)"; ?>"></a>
<?php } ?>				</th>
						<th class="search"><a href="<?php echo $spot['searchurl'];?>" title="NZB zoeken"></a></th>
<?php if ($show_watchlist_button) {
echo "<th class='watch'>";
echo "<a class='remove watchremove_".$spot['id']."' onclick=\"toggleWatchSpot('".$spot['messageid']."','remove',".$spot['id'].")\""; if($spot['isbeingwatched'] == false) { echo " style='display: none;'"; } echo " title='Verwijder uit watchlist (w)'> </a>";
echo "<a class='add watchadd_".$spot['id']."' onclick=\"toggleWatchSpot('".$spot['messageid']."','add',".$spot['id'].")\""; if($spot['isbeingwatched'] == true) { echo " style='display: none;'"; } echo " title='Plaats in watchlist (w)'> </a>";
echo "</th>";
} ?>
<?php if ((!empty($spot['nzb'])) && (!empty($spot['sabnzbdurl']))) { ?>
<?php if ($spot['hasbeendownloaded']) { ?>
						<th class="sabnzbd"><a onclick="downloadSabnzbd(<?php echo "'".$spot['id']."','".$spot['sabnzbdurl']."'"; ?>)" class="<?php echo "sab_".$spot['id'].""; ?> sabnzbd-button succes" title="Add NZB to SabNZBd queue (you already downloaded this spot) (s)"> </a></th>
<?php } else { ?>
						<th class="sabnzbd"><a onclick="downloadSabnzbd(<?php echo "'".$spot['id']."','".$spot['sabnzbdurl']."'"; ?>)" class="<?php echo "sab_".$spot['id'].""; ?> sabnzbd-button" title="Add NZB to SabNZBd queue (s)"> </a></th>
<?php } } ?>
					</tr>
				</tbody>
			</table>
			<table class="spotdetails">
				<tr>
					<td class="img">
						<a onclick="toggleImageSize('<?php echo $spot['image']; ?>')" class="postimage">
							<img class="spotinfoimage" src="<?php echo $tplHelper->makeImageUrl($spot, 300, 300); ?>" alt="<?php echo $spot['title'];?>">
						</a>
					</td>
					<td class="info">
<?php if (!$spot['verified'] || $tplHelper->isModerated($spot)) {
	echo "<div class='warning'>";
	if (!$spot['verified']) {
		echo "Deze spot is niet geverifi&euml;erd, de naam van de poster is niet bevestigd!<br>";
	}
	if ($tplHelper->isModerated($spot)) {
		echo "Deze spot is als mogelijk onwenselijk gemodereerd!";
	}
	echo "</div>";
} ?>
						<table class="spotinfo">
							<tbody>
								<tr><th> Categorie </th> <td><a href="<?php echo $tplHelper->makeCatUrl($spot); ?>" title='Zoek spots in de categorie "<?php echo $spot['catname']; ?>"'><?php echo $spot['catname']; ?></a></td> </tr>
<?php
	if (!empty($spot['subcatlist'])) {
		foreach($spot['subcatlist'] as $sub) {
			$subcatType = substr($sub, 0, 1);
			echo "\t\t\t\t\t\t<tr><th> " . SpotCategories::SubcatDescription($spot['category'], $subcatType) .  "</th>";
			echo "<td><a href='" . $tplHelper->makeSubCatUrl($spot, $sub) . "' title='Zoek spots in de categorie " . SpotCategories::Cat2Desc($spot['category'], $sub) . "'>" . SpotCategories::Cat2Desc($spot['category'], $sub) . "</a></td> </tr>\r\n";
		} # foreach
	} # if
?>
								<tr><th> Omvang </th> <td> <?php echo $tplHelper->format_size($spot['filesize']); ?> </td> </tr>
								<tr><td class="break" colspan="2">&nbsp;</td> </tr>
								<tr><th> Website </th> <td> <a href='<?php echo $spot['website']; ?>'><?php echo $spot['website'];?></a> </td> </tr>
								<tr> <td class="break" colspan="2">&nbsp;</td> </tr>
								<tr> <th> Afzender </th> <td> <a href="<?php echo $tplHelper->makePosterUrl($spot); ?>" title='Zoek naar spots van "<?php echo $spot['poster']; ?>"'><?php echo $spot['poster']; ?></a>
								<?php if (!empty($spot['userid'])) { ?> (<a href="<?php echo $tplHelper->makeUserIdUrl($spot); ?>" title='Zoek naar spots van "<?php echo $spot['poster']; ?>"'><?php echo $spot['userid']; ?></a>)<?php } ?>
								</td> </tr>
								<tr> <th> Tag </th> <td> <a href="<?php echo $tplHelper->makeTagUrl($spot); ?>" title='Zoek naar spots met de tag "<?php echo $spot['tag']; ?>"'><?php echo $spot['tag']; ?></a> </td> </tr>
								<tr> <td class="break" colspan="2">&nbsp;</td> </tr>
								<tr> <th> Zoekmachine </th> <td> <a href='<?php echo $spot['searchurl']; ?>'>Zoek</a> </td> </tr>
<?php if ($show_nzb_button) { ?>		
								<tr> <th> NZB </th> <td> <a href='<?php echo $tplHelper->makeNzbUrl($spot); ?>' title='Download NZB (n)'>NZB</a> </td> </tr>
<?php } ?>
							</tbody>
						</table>
					</td>
				</tr>
			</table>
			<div class="description">
				<h4>Post Description</h4>
				<pre><?php echo $spot['description']; ?></pre>
			</div>

<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_comments, '')) { ?>
			<div class="comments" id="comments">
				<h4>Comments <span class="commentcount"># 0</span></h4>
				<ul id="commentslist">
<?php 
if ($tplHelper->allowed(SpotSecurity::spotsec_post_comment, '')) { 
	if ($currentSession['user']['userid'] > 2) { 
		echo "<li class='addComment'>";
		echo "<a class='togglePostComment' title='Reactie toevoegen (uitklappen)'>Reactie toevoegen <span></span></a><div><div></div>";
		include "postcomment.inc.php"; 
		echo "</div></li>";
	}
} ?>
				</ul>
			</div>
<?php } ?>
		</div>
		
		<input type="hidden" id="messageid" value="<?php echo $spot['messageid'] ?>" />
		<script type="text/javascript">
			$(document).ready(function(){
				$("#details").addClass("external");

				$("a[href^='http']").attr('target','_blank');

				$("a.closeDetails").click(function(){ 
					window.close();
				});

				var messageid = $('#messageid').val();
				postCommentsForm();
				loadSpotImage();
				loadComments(messageid,'5','0');
			});

			function addText(text,element_id) {
				document.getElementById(element_id).value += text;
			}
		</script>
<?
require_once "includes/footer.inc.php";
