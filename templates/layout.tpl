{* templates/layout.tpl *}
<!DOCTYPE html>
<html>
<head>
    <title>{$title|default:"Canvas Blog"}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        nav a { margin-right: 20px; }
    </style>
</head>
<body>
<nav>
    <a href="/">Home</a>
    <a href="/posts">Posts</a>
</nav>
<main>{$content}</main>
</body>
</html>