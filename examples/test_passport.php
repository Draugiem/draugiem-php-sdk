<?php
/*
	This is a simple example script that shows how easy is to integrate your page with Draugiem.lv Passport.
	Feel free to use it in your own projects.
*/

$app_key = 'd6949661ccde33a65d98653043bc3119';//Application API key of your app goes here
$app_id = 999;//Application ID of your app goes here

session_start(); //Start PHP session

if(isset($_GET['logout'])){//Logout
	session_destroy();
	header('Location: ?');
}


include 'DraugiemApi.php';
	
$draugiem = new DraugiemApi($app_id, $app_key);//Create Draugiem.lv API object
	
$session = $draugiem->getSession(); //Try to authenticate user

if($session && !empty($_GET['dr_auth_code'])){//New session, check if we are not redirected from popup
	if(!empty($_GET['dr_popup'])){//Redirected from popup, refresh parent window and close the popup with Javascript
		?>
		<script type="text/javascript">
		window.opener.location.reload();
		window.opener.focus();
		if(window.opener!=window){
			window.close();
		}
		</script>
		<?php
	} else {//No popup, simply reload current window
		header('Location: ?');
	}
	exit;
}elseif(!empty($_GET['dr_popup'])){ // failed login
		?><script type="text/javascript">
		window.opener.location.reload();
		window.opener.focus();
		if(window.opener!=window){
			window.close();
		}
		</script><?php
		exit;
}

?><!DOCTYPE html>
<html>
	<head>
		<title>Draugiem Passport test</title>
		<meta charset="utf-8">
	</head>
	<body>
<?php
	if($session){//Authentication successful

		$user = $draugiem->getUserData();//Get user info

		//Print greeting for user
		echo '<h2>Hello, '.$user['name'].' '.$user['surname'].'</h2>';
		if($user['img']){
			echo '<img src="'.$draugiem->imageForSize($user['img'], 'medium').'" alt="" />';
		}

		echo '<hr />';

		//Show 10 friends with normal size image
		if($users = $draugiem->getUserFriends(1, 10)){

			echo '<h3>Your friends who also use this application</h3>';

			foreach($users as $friend){
				if($friend['img']){
					echo '<img src="'.$friend['img'].'" alt="" align="left" style="clear:left" />';
				}
				echo '<a target="_top" href="http://www.draugiem.lv/friend/?'.$friend['uid'].'">';
				echo $friend['name'].' '.$friend['surname'].'</a><br />';
				if($friend['nick']){
					echo '('.$friend['nick'].') ';
				}
				echo $friend['place'].'<br style="clear:both" />';
			}
		}

		//Logout link
		echo '<hr /><a href="?logout">Logout ['.$user['name'].' '.$user['surname'].']</a>';

	} else { //User not logged in, show login button

		echo '<h2>Welcome to our site. Please login with draugiem.lv Passport</h2>';

		$redirect = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];//Where to redirect after authorization

		echo $draugiem->getLoginButton($redirect);//Show the button

		echo '<hr />';

		//Show 10 users of this app with small image
		if($users = $draugiem->getAppUsers(1, 10)){

			echo '<h3>Users of this website</h3>';

			foreach($users as $friend){
				if($friend['img']){
					echo '<img src="'.$draugiem->imageForSize($friend['img'], 'icon').'" alt="" align="left"  />';
				}
				echo '<a target="_top" href="http://www.draugiem.lv/friend/?'.$friend['uid'].'">';
				echo $friend['name'].' '.$friend['surname'].'</a><br />';
				if($friend['nick']){
					echo '('.$friend['nick'].') ';
				}
				echo $friend['place'].'<br style="clear:both" />';
			}
		}
	}
?>
	</body>
</html>
