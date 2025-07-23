<?php
	
	$pdo = new PDO("mysql:host=localhost;port=3306;dbname=canvas_blog", "root", "root");
	
	$stmt = $pdo->prepare("SELECT * FROM `posts` WHERE id = ?");
	$stmt->execute([1]);
	
	echo "<h2>Hallo</h2>";