<?php
	
	$db = mysqli_connect('localhost', 'root', 'root', 'canvas_blog');
	
	mysqli_query($db, 'select * from `posts`');
	
	echo "<h2>Hallo</h2>";