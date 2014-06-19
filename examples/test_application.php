<?php
/*
	This is a simple example script that shows how easy is to integrate your application within draugiem.lv.
	Feel free to use it in your own projects.
*/

include 'DraugiemApi.php';

$app_key = 'd6949661ccde33a65d98653043bc3119';//Application API key of your app goes here
$app_id = 999;//Application ID of your app goes here

$draugiem = new DraugiemApi($app_id, $app_key);//Create Passport object

session_start(); //Start PHP session

$draugiem->cookieFix(); //Iframe cookie workaround for IE and Safari

$session = $draugiem->getSession();//Authenticate user

if($session){//Authentication successful

	$user = $draugiem->getUserData();//Get user info

?><html>
	<head>
		<title>Draugiem API test</title>
<?php
			echo $draugiem->getJavascript('body', 'http://'.$_SERVER['SERVER_NAME'].dirname($_SERVER['PHP_SELF']).'/callback.html'); //Set up JS API + iframe Resize
?>
	</head>
	<body>
		<div id="body">
<?php
			//Print greeting for user
			echo '<h2>Hello, '.$user['name'].' '.$user['surname'].'</h2>';
			if($user['img']){
				echo '<img src="'.$draugiem->imageForSize($user['img'], 'medium').'" alt="" />';
			}

			echo '<hr />';
			
			//Show 10 users of this app with small image
			if($users = $draugiem->getAppUsers(1, 10)){

				echo '<h3>Some users of this application</h3>';

				foreach($users as $friend){
					if($friend['img']){
						echo '<img src="'.$draugiem->imageForSize($friend['img'], 'icon').'" alt="" align="left"  />';
					}
					echo '<a target="_top" href="http://'.$draugiem->getSessionDomain().'/friend/?'.$friend['uid'].'">';
					echo $friend['name'].' '.$friend['surname'].'</a><br />';
					if($friend['nick']){
						echo '('.$friend['nick'].') ';
					}
					echo $friend['place'].'<br style="clear:both" />';
				}
			}

			//Show 10 friends with normal size image
			if($users = $draugiem->getUserFriends(1, 10)){

				echo '<h3>Your friends who also use this application</h3>';

				foreach($users as $friend){
					if($friend['img']){
						echo '<img src="'.$friend['img'].'" alt="" align="left" style="clear:left" />';
					}
					echo '<a target="_top" href="http://'.$draugiem->getSessionDomain().'/friend/?'.$friend['uid'].'">';
					echo $friend['name'].' '.$friend['surname'].'</a><br />';
					if($friend['nick']){
						echo '('.$friend['nick'].') ';
					}
					echo $friend['place'].'<br style="clear:both" />';
				}
			}
?>
		</div>
	</body>
</html>
<?php

} else {//User not logged in, session failed
	echo 'AUTHENTICATION FAILED';
	session_destroy();
}