<?php
/*
LICENSE:
Copyright (c) 2010, Jesse Kochis
All rights reserved.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions are met:
1. Redistributions of source code must retain the above copyright
   notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
3. All advertising materials mentioning features or use of this software
   must display the following acknowledgement:
   This product includes software developed by Jesse Kochis.
4. Neither the name of Jesse Kochis nor the
   names of its contributors may be used to endorse or promote products
   derived from this software without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY Jesse Kochis 'AS IS' AND ANY
EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
DISCLAIMED. IN NO EVENT SHALL Jesse Kochis BE LIABLE FOR ANY
DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
(INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
(INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*/
require_once 'twitter.php';
require_once 'function.resize.php';
require_once 'snoopy.class.php';
require_once 'htmlsql.class.php';

/**
 * 1. Get and cache mentions
 * 2. Parse twitpics
 * 3. Get images and crop
 */
// Instantiate Twitter class
$twitter = new Twitter('<username>', '<password>');
// Get all tweets @hunt_and_gather
$tweets = $twitter->getMentionsReplies();
// Instantiate HTMLSql
$wsql = new htmlsql();
// Path to log file
$cacheFile = 'cache/pics.log';
// Refresh always for now
$cacheMinutes = 0;
// Open log file
$fh = fopen($cacheFile, 'a') or die("can't open file");

function parsePics($tweets) {
	$picUrls = array();
	$twitpicPattern = '%(http://twitpic\.com/\w+)%i';
	$yfrogPattern = '%(http://yfrog\.com/\w+)%i';
	foreach($tweets as $tweet) {
		if(preg_match($twitpicPattern, $tweet['text'], $matches)
		|| preg_match($yfrogPattern, $tweet['text'], $matches)) {
			$picUrls = array_merge($matches, $picUrls);
		}
	}
	return getPics(array_unique($picUrls));
}

function getPics($picUrls) {
	global $wsql, $cacheFile, $fh;
	$cachedPics = file($cacheFile);
	foreach($picUrls as $picUrl) {
		if (in_array($picUrl . "\n", $cachedPics)) {
			continue;
		}
		// Need to check yfrog links and use a different connection because they redirect
		// Possibly can use the yfrog connection alone...
		if (preg_match('/yfrog/', $picUrl) == false){
			if (!$wsql->connect('url', $picUrl)){
				echo $picUrl;
				print 'Error while connecting: ' . $wsql->error;
				exit;
			}
			if (!$wsql->query('SELECT src FROM img WHERE $id == "photo-display"')){
				echo $picUrl;
				print "Query error: " . $wsql->error; 
				exit;
			}
		} else {
			if (!$wsql->connect('string', file_get_contents($picUrl))){
				echo $picUrl;
				print 'Error while connecting: ' . $wsql->error;
				exit;
			}
			if (!$wsql->query('SELECT value FROM input WHERE preg_match("%(jpg|png)%", substr($value, -3))')){
				echo $picUrl;
				print "Query error: " . $wsql->error; 
				exit;
			}
		}
		// Loop over the rows, downloads, resizes, and logs the new images downloaded
		foreach($wsql->fetch_array() as $row){
			if(isset($row['value'])) {
				$row['src'] = $row['value'];
			}
			// Need to get returned image name and log it so I can get links and output them
			$savedImg = resize($row['src'], array('w'=>232, 'h'=>164));
			fwrite($fh, $picUrl . "\n" . $row['src'] . "\n" . $savedImg . "|" . $picUrl . "\n");
		}
	}
}

// Determine how to render img tags. Sorry IE no base64 for you
// Could possibly be enhanced...
function isIE() {
	if (isset($_SERVER['HTTP_USER_AGENT']) && (strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false)) {
		return true;
	}
	return false;
}

// Output base64 data for non IE browser
function outputImage($pic) {
	if (!is_object($pic)) {
		$pic = new SplFileObject($pic);
	}
	$picPath = $pic->getPathName();
	$picExt = strtolower(substr($picPath, -3)); //jeko moved strtolower only used for preg_match and mime type
	// IE gets paths to images, cool kids get base64 image data
	if(preg_match('%(jpg|png|gif)%',$picExt)){ //sm_com preg_match once
		if (isIE()){
			return $picPath;
		} else{ //sm_com removed if !isIE()
			$picData = base64_encode(file_get_contents($picPath));
			return "data:image/" . $picExt . ";base64," . $picData; //sm_com removed switch:
		}
	}else{
		return false; //sm_com or throw an error (it's not an acceptable image type or not an image)
	}
}

function loadPics() {
	global $tweets, $cacheFile, $cacheMinutes;
	// Time to get new pics?
	if(filemtime($cacheFile) < strtotime('-'.$cacheMinutes.' minutes')):
		parsePics($tweets);
	endif;
	// Thank you SPL
	// Loop over the directory items
	try {
		$pics = array();
		$picsDir = new DirectoryIterator('cache/');
		foreach ($picsDir as $pic) {
			if($p = outputImage($pic)) {
				$pics[] = $p;
			} 
		}
		return array_reverse($pics);
	}
	catch(Exception $e) {
		echo 'No files Found!<br />';
	}
}

// Outputs image data inside the optional format
function load($fmt=null) {
	global $fh;
	if ($fmt == null) {
		$fmt = "<img src='%s' />";
	}
	foreach(loadPics() as $pic) {
		echo sprintf($fmt, $pic);
	}
	fclose($fh);
}
?>