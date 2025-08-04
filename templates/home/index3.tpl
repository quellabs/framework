{literal}
<!DOCTYPE html>
<html lang="en">
<body>

<h1>WakaPAC</h1>

<!-- Basic Features Demo -->
<div id="demo">
    {{ id }} {{ title }}
</div>

<!-- Communication Log -->
<div class="message-log" id="message-log">
    <div><span class="timestamp">[System]</span> PAC Framework Communication Log - Watch bidirectional messages below:</div>
</div>

<script src="wakapac.js"></script>
<script src="js/abstractions/PostAbstraction.js"></script>

// Add this diagnostic to your original example:
<script>
    const waka = wakaPAC('#demo', createPostAbstraction());
    waka.load(1);

    setInterval(function(e) {
        ++waka.id;
    }, 1000);

    //waka.load(1);
</script>
</body>
</html>
{/literal}