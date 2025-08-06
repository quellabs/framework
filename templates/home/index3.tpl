{literal}
<!DOCTYPE html>
<html lang="en">
<body>

<h1>WakaPAC</h1>

<!-- Basic Features Demo -->
<div id="demo">
    {{ id }} {{ title }}
</div>

<script src="wakapac.js"></script>
<script src="js/abstractions/PostAbstraction.js"></script>

<script>
    const waka = wakaPAC('#demo', createPostAbstraction());
    waka.load(1);

    setInterval(function(e) {
        ++waka.id;
    }, 1000);
</script>
</body>
</html>
{/literal}