#!/usr/bin/php
<?php
$iptvurl = "--put it here--";
$user  = "john";
$domain = "--Upload Domain Here--";
$basescpdir = "--your base scp dir--"
#--------------------------------------Go Below Here If you Dare!---------------------------------
$option = $argv[1];

if ($option == "--getm3u") {
	$output = file_get_contents($iptvurl);
	$fp = fopen("/tmp/playlist.m3u","w");
	fwrite($fp,$output);
	fclose($fp);
}

if ($option == "--stop") {
	check();
}

if ($option == "--check") {
	check();
}


function check() {
	$db = new SQLite3("/home/$user/tv.db");
	$results = $db->query('SELECT pid,stopTime,season,filename,show from shows where uploaded=0 and pid != 0 and finished != 1');
	while ($row = $results->fetchArray()) {
    		$stopTime = $row[1];
    		$pid=$row[0];
		$season = $row[2];
		$show = $row[4];
		$filename = $row[3];
		echo $stopTime ."\n";
		if (time() >= $stopTime) {
			echo "Ending $pid .\n";
			$command = "kill $pid";
			exec($command, $output);
			$db->exec("UPDATE shows set finished=1 where pid=$pid");
			$pid = exec($command, $output);
			#We still need comskip and stuff, but this is a start
			/*this will move to a new command, because we only want to upload once a day late at night because google. we have a DB column for this.
			This should also include comskip and stuff as well, but really it should order uploads not try to dump them at once.
			*/
			$showesc = str_replace(" ","\\ ",$show);
			$showscp = str_replace(" ","\\\\\\ ",$show);
			$command = "ssh $user@$domain 'mkdir $basescpdir/$showesc/Season\ $season'";
			exec($command, $output);
			print_r($output);
			echo "scp /tmp/$filename $user@$domain:$basescpdir/$showscp/Season\\\\ $season/$filename &" ."\n";
			$command = "scp /tmp/$filename $user@$domain:$basescpdir/$showscp/Season\\\\\\ $season/$filename > /dev/null 2>&1 &";
			//exec($command, $output);
			system($command);
			print_r($output);
			//end move to new command
		}
	}

	$results = $db->query('SELECT filename,airdate,show from shows where uploaded=0 and pid=0');
	while ($row = $results->fetchArray()) {
    		$airdate = $row[1];
    		$filename=$row[0];
		$airdate = $airdate - 300; 
		$showLength = getShowLength($row[2]); 
		$channel = getChannel($row[2]); 
		$lines = file("/tmp/playlist.m3u");
		$count = 0;
		$getline = 0;
		foreach ($lines as $l) {
			$l = trim($l);
			if ($getline > 0 && $getline == $count) {
				$url = $l;
				break;
			}
			if (stristr($l,$channel)) {
				$getline = $count + 2;
			}
			$count++;
		}
		//Change this to > to make it production
		if (time() >= $airdate) {
			$showLength = $showLength + 300;
			$stopTime = time() + $showLength;
			//spawn a sh client to do the recording.
			//$PID=system("/home/$user/record.sh $filename $showLength \"$url\"");
			//echo $PID ."\n";
			$command = "/usr/bin/cvlc \"$url\" --preferred-resolution 720 --sout \"#transcode{acodec=mpga,ab=128,channels=2,samplerate=44100,threads=4,audio-sync=1}:standard{access=file,mux=mkv,dst=/tmp/$filename}\" > /dev/null 2>&1 & echo $!; ";
			$pid = exec($command, $output);
			$db->exec("UPDATE shows set pid=\"$pid\",stopTime=\"$stopTime\" where filename=\"$filename\"");
			exit;

		}
	}
	$db->close();
}


function getChannel($show) {
	$db = new SQLite3("/home/$user/tv.db");
	$channel = 0;
	$results = $db->query("SELECT channel from showList where show=\"$show\"");
	while ($row = $results->fetchArray()) {
		$channel = $row[0];
	}
	$db->close();
	return $channel;
}

function getShowLength($show) {
	$db = new SQLite3("/home/$user/tv.db");
	$showLength = 3600;
	$results = $db->query("SELECT length from showList where show=\"$show\"");
	while ($row = $results->fetchArray()) {
		$showLength = $row[0];
	}
	$db->close();
	return $showLength;
}

if ($option == "--help") {
	echo "--loadxml .. Loads from schedules direct\n";
	echo "--check   .. Check for recordings that need starting or stopping\n";
	echo "--getm3u  .. Download the m3u for this tuner\n";
}

if ($option == "--loadxml") {
	system("/usr/bin/tv_grab_na_dd > /home/$user/guide.xml");
	$SL = 0;
	$SHOW= "";
	$xml=simplexml_load_file("/home/$user/guide.xml");
	$db = new SQLite3("/home/$user/tv.db");

	$results = $db->query('SELECT show,seasonLength from showList');
	while ($row = $results->fetchArray()) {
    		$SL = $row[1];
    		$SHOW=$row[0];
	}

	foreach($xml as $x) {
		if (!isset($x->{'previously-shown'})) {
			if ($x->title == $SHOW) {
				$FILENAME_BEG=str_replace(" ",".",$SHOW);
				$filename = getFilename($SL,$x->{'episode-num'}[1],$FILENAME_BEG);
				$season = getSeason($SL,$x->{'episode-num'}[1],$FILENAME_BEG);
				$episode = getEpisode($SL,$x->{'episode-num'}[1],$FILENAME_BEG);
				$airTime = strtotime($x['start']);
				$db->exec("DELETE FROM shows where filename=\"$filename\" and pid=0");
				$db->exec("INSERT INTO shows (filename,airdate,pid,uploaded,show,season,episode) values (\"$filename\",$airTime,0,0,\"$SHOW\",\"$season\",\"$episode\")");
			}
		}

	}
	$db->close();
}

function getFilename($SL,$full,$FILENAME_BEG) {
	$SEASONEP=$full;
	$SEASON_LENGTH=$SL;
	$SEASON=substr($SEASONEP,0,$SEASON_LENGTH);
	$EPISODE=substr($SEASONEP,3);
	$filename = $FILENAME_BEG . ".S".$SEASON."E".$EPISODE.".mkv";
	return $filename;
}

function getSeason($SL,$full,$FILENAME_BEG) {
	$SEASONEP=$full;
	$SEASON_LENGTH=$SL;
	$SEASON=substr($SEASONEP,0,$SEASON_LENGTH);
	$EPISODE=substr($SEASONEP,3);
	$filename = $FILENAME_BEG . ".S".$SEASON."E".$EPISODE.".mkv";
	return $SEASON;
}

function getEpisode($SL,$full,$FILENAME_BEG) {
	$SEASONEP=$full;
	$SEASON_LENGTH=$SL;
	$SEASON=substr($SEASONEP,0,$SEASON_LENGTH);
	$EPISODE=substr($SEASONEP,3);
	$filename = $FILENAME_BEG . ".S".$SEASON."E".$EPISODE.".mkv";
	return $EPISODE;
}
