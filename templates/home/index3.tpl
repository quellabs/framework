{literal}
<!DOCTYPE html>
<html lang="en">
<body>

<h1>WakaPAC</h1>

<!-- Basic Features Demo -->
<div id="demo">
    <span>{{ id }}</span>
    <span>{{ title }}</span>
</div>

<!-- Communication Log -->
<div class="message-log" id="message-log">
    <div><span class="timestamp">[System]</span> PAC Framework Communication Log - Watch bidirectional messages below:</div>
</div>

<script src="wakapac.js"></script>
<script src="js/abstractions/PostAbstraction.js"></script>

// Add this diagnostic to your original example:
<script>
    window.waka = wakaPAC('#demo', createPostAbstraction());

    waka.id = 999;
    waka.title = 'xManual Test';
    //waka.load(1);
</script>
</body>
</html>
{/literal}