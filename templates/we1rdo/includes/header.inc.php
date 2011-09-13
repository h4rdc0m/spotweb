<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
	<head>
		<meta http-equiv='Content-Type' content='text/html; charset=utf-8'>
		<title><?php echo $pagetitle?></title>
		<meta name="generator" content="SpotWeb v<?php echo SPOTWEB_VERSION; ?>">
<?php if ($settings->get('deny_robots')) { echo "\t\t<meta name=\"robots\" content=\"noindex, nofollow\">\r\n"; } ?>
		<base href='<?php echo $tplHelper->makeBaseUrl("full"); ?>'>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_rssfeed, '')) { ?>
		<link rel='alternate' type='application/rss+xml' href='<?php echo $tplHelper->makeRssUrl(); ?>'>
<?php } ?>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_view_statics, '')) { ?>
		<link rel='stylesheet' type='text/css' href='?page=statics&amp;type=css&amp;mod=<?php echo $tplHelper->getStaticModTime('css'); ?>'>
		<link rel='shortcut icon' href='?page=statics&amp;type=ico&amp;mod=<?php echo $tplHelper->getStaticModTime('ico'); ?>'>
		<script src='?page=statics&amp;type=js&amp;mod=<?php echo $tplHelper->getStaticModTime('js'); ?>' type='text/javascript'></script>
<?php } ?>
<?php if ($tplHelper->allowed(SpotSecurity::spotsec_allow_custom_stylesheet, '')) { ?>
		<style type="text/css" media="screen,handheld,projection">
			<?php echo $tplHelper->getUserCustomCss(); ?>
		</style>		
<?php } ?>
		<script type='text/javascript'>
			// Define some global variables showing or hiding specific parts of the UI
			// based on users' security rights
			var spotweb_security_allow_spotdetail = <?php echo (int) $tplHelper->allowed(SpotSecurity::spotsec_view_spotdetail, ''); ?>;
			var spotweb_security_allow_view_spotimage = <?php echo (int) $tplHelper->allowed(SpotSecurity::spotsec_view_spotimage, ''); ?>;
			var spotweb_security_allow_view_comments = <?php echo (int) $tplHelper->allowed(SpotSecurity::spotsec_view_comments, ''); ?>;
		</script>
	</head>
	<body>
		<div id='editdialogdiv'></div>
		<div id="overlay"></div>
		<div class="container" id="container">
